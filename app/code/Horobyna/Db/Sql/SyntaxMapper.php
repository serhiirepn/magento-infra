<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Sql;

/**
 * Rewrites MySQL-only operators, clauses and statements in SQL text
 *
 * Supported:
 * - a [NOT] REGEXP|RLIKE b      a ~* b, a !~* b (case-insensitive, as with MySQL _ci collations; a must be text)
 * - USE|FORCE|IGNORE INDEX|KEY [FOR ...] (...)   dropped, PostgreSQL has no index hints
 * - UPDATE t SET ... [WHERE ...] [ORDER BY ...] LIMIT n,
 *   DELETE FROM t [WHERE ...] [ORDER BY ...] LIMIT n   the rows are chosen by ctid in a subquery
 * - ANALYZE|OPTIMIZE TABLE t[, ...]   ANALYZE t[, ...]
 *
 * Literals, quoted identifiers and comments are left untouched. A statement that does not match the supported
 * form is left as is.
 */
class SyntaxMapper
{
    /**
     * Quick checks on the unmasked SQL, so that most statements are not scanned
     */
    private const ANALYZE_CHECK = '/^\s*(?:ANALYZE|OPTIMIZE)\s/i';
    private const INDEX_HINT_CHECK = '/\b(?:USE|FORCE|IGNORE)\s+(?:INDEX|KEY)\b/i';
    private const REGEXP_CHECK = '/\b(?:REGEXP|RLIKE)\b/i';
    private const DML_LIMIT_CHECK = '/^\s*(?:UPDATE|DELETE)\b.*\bLIMIT\b/is';

    private const REGEXP_PATTERN = '/\b(NOT\s+)?(?:REGEXP|RLIKE)\b/i';

    private const INDEX_HINT_PATTERN = '/\s+(?:USE|FORCE|IGNORE)\s+(?:INDEX|KEY)'
        . '(?:\s+FOR\s+(?:JOIN|ORDER\s+BY|GROUP\s+BY))?\s*\([^()]*\)/i';

    /**
     * Table with an optional alias, not followed by a keyword
     */
    private const TABLE = '(?<table>[^\s,()]+(?:\s+(?:AS\s+)?(?!(?:SET|WHERE|ORDER|LIMIT)\b)[A-Za-z_]\w*)?)';

    private const DML_TAIL = '(?:\s+WHERE\s+(?<where>.+?))?(?:\s+ORDER\s+BY\s+(?<order>.+?))?'
        . '\s+LIMIT\s+(?<limit>\d+|\?|:\w+)\s*;?\s*$/is';

    private const UPDATE_LIMIT_PATTERN = '/^\s*UPDATE\s+' . self::TABLE . '\s+SET\s+(?<set>.+?)' . self::DML_TAIL;

    private const DELETE_LIMIT_PATTERN = '/^\s*DELETE\s+FROM\s+' . self::TABLE . self::DML_TAIL;

    private const ANALYZE_PATTERN = '/^\s*(?:ANALYZE|OPTIMIZE)\s+(?:NO_WRITE_TO_BINLOG\s+|LOCAL\s+)?TABLES?\s+(.+?)\s*;?\s*$/is';

    /**
     * @var Scanner
     */
    private $scanner;

    /**
     * @param Scanner|null $scanner
     */
    public function __construct(?Scanner $scanner = null)
    {
        $this->scanner = $scanner ?? new Scanner();
    }

    /**
     * Rewrite MySQL syntax in an SQL statement
     *
     * @param string $sql
     * @return string
     */
    public function map(string $sql): string
    {
        // Masked parts stay tokens ("x"), so that a quoted table name still matches as a name
        if (preg_match(self::ANALYZE_CHECK, $sql)
            && preg_match(self::ANALYZE_PATTERN, $this->scanner->mask($sql, 'x'), $matches, PREG_OFFSET_CAPTURE)
        ) {
            return 'ANALYZE ' . substr($sql, $matches[1][1], strlen($matches[1][0]));
        }
        if (preg_match(self::INDEX_HINT_CHECK, $sql)) {
            $sql = $this->replaceMatches($sql, self::INDEX_HINT_PATTERN, fn () => '');
        }
        if (preg_match(self::REGEXP_CHECK, $sql)) {
            $sql = $this->replaceMatches(
                $sql,
                self::REGEXP_PATTERN,
                fn ($match) => isset($match[1]) && $match[1][1] >= 0 && $match[1][0] !== '' ? '!~*' : '~*'
            );
        }

        return preg_match(self::DML_LIMIT_CHECK, $sql) ? $this->mapDmlLimit($sql) : $sql;
    }

    /**
     * Replace the matches of a pattern outside of literals, quoted identifiers and comments
     *
     * @param string $sql
     * @param string $pattern
     * @param callable $replacement receives the match with offsets
     * @return string
     */
    private function replaceMatches(string $sql, string $pattern, callable $replacement): string
    {
        $masked = $this->scanner->mask($sql, 'x');
        if (!preg_match_all($pattern, $masked, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $sql;
        }
        foreach (array_reverse($matches) as $match) {
            $sql = substr_replace($sql, $replacement($match), $match[0][1], strlen($match[0][0]));
        }
        return $sql;
    }

    /**
     * UPDATE/DELETE ... LIMIT n => ... WHERE ctid IN (SELECT ctid FROM t WHERE ... LIMIT n)
     *
     * Clauses are only looked for at the top level: parenthesized parts are masked as well.
     *
     * @param string $sql
     * @return string
     */
    private function mapDmlLimit(string $sql): string
    {
        $flat = $this->maskParentheses($this->scanner->mask($sql, 'x'));
        if (!preg_match(self::UPDATE_LIMIT_PATTERN, $flat, $matches, PREG_OFFSET_CAPTURE)
            && !preg_match(self::DELETE_LIMIT_PATTERN, $flat, $matches, PREG_OFFSET_CAPTURE)
        ) {
            return $sql;
        }
        $part = fn ($name) => isset($matches[$name]) && $matches[$name][1] >= 0
            ? substr($sql, $matches[$name][1], strlen($matches[$name][0]))
            : '';
        $table = $part('table');
        $tableWords = preg_split('/\s+/', trim($table));
        $ctid = count($tableWords) > 1 ? end($tableWords) . '.ctid' : 'ctid';
        $subquery = sprintf(
            'SELECT %s FROM %s%s%s LIMIT %s',
            $ctid,
            $table,
            $part('where') !== '' ? ' WHERE ' . $part('where') : '',
            $part('order') !== '' ? ' ORDER BY ' . $part('order') : '',
            $part('limit')
        );

        return $part('set') !== ''
            ? sprintf('UPDATE %s SET %s WHERE %s IN (%s)', $table, $part('set'), $ctid, $subquery)
            : sprintf('DELETE FROM %s WHERE %s IN (%s)', $table, $ctid, $subquery);
    }

    /**
     * Mask everything inside parentheses with "x", keeping the parentheses and offsets
     *
     * @param string $masked SQL with literals already masked
     * @return string
     */
    private function maskParentheses(string $masked): string
    {
        $depth = 0;
        $length = strlen($masked);
        for ($i = 0; $i < $length; $i++) {
            $char = $masked[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($depth > 0) {
                $masked[$i] = 'x';
            }
        }
        return $masked;
    }
}
