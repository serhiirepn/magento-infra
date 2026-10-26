<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Sql;

/**
 * Casts the value columns of EAV attribute value selects to text, so that they can be combined with UNION
 *
 * Magento loads EAV attributes with a UNION over the value tables of different types (varchar, int, decimal,
 * datetime, text): EAV ReadHandler, collections, ScopeOverriddenValue, AttributeValue... MySQL converts the mixed
 * value column to a string, PostgreSQL refuses to unite text with numbers. The loaded values reach PHP as strings
 * in both cases, and text renders numbers and dates the same way PDO does, so the cast does not change them.
 *
 * Callers build these unions in different ways (Select parts, UnionExpression, strings glued with UNION ALL), so
 * the SQL text is rewritten: in each UNION branch selecting FROM an EAV value table, the select list items named
 * value, default_value or store_value become (expression)::text. Other selects are left as they are.
 */
class UnionValueCaster
{
    /**
     * Output names of the columns holding attribute values; catalog collections also select the default (store 0)
     * and the store value next to the resulting one
     */
    private const VALUE_COLUMNS = ['value', 'default_value', 'store_value'];

    /**
     * Start of a FROM clause on an EAV attribute value table, e.g. "catalog_product_entity_int" AS "t"
     */
    private const VALUE_TABLE_PATTERN = '/^\s*"?\w+_(?:varchar|int|decimal|datetime|text)"?(?:\s|$)/i';

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
     * Cast the value columns of the EAV value table branches of the unions in an SQL statement
     *
     * @param string $sql
     * @return string
     */
    public function cast(string $sql): string
    {
        if (stripos($sql, 'union') === false) {
            return $sql;
        }
        $masked = $this->scanner->mask($sql);
        if (!preg_match('/\bUNION\b/i', $masked)) {
            return $sql;
        }

        $replacements = [];
        preg_match_all('/\bSELECT\b/i', $masked, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as list(, $selectPosition)) {
            $listStart = $selectPosition + strlen('SELECT');
            $fromPosition = $this->findAtSameDepth($masked, $listStart, '/\GFROM\b/i');
            if ($fromPosition === null
                || !preg_match(self::VALUE_TABLE_PATTERN, substr($sql, $fromPosition + 4, 200))
                || !$this->isUnionBranch($masked, $selectPosition, $fromPosition)
            ) {
                continue;
            }
            $list = substr($sql, $listStart, $fromPosition - $listStart);
            $castList = $this->castSelectList($list);
            if ($castList !== $list) {
                $replacements[] = [$listStart, $fromPosition - $listStart, ' ' . $castList . ' '];
            }
        }

        foreach (array_reverse($replacements) as list($offset, $length, $replacement)) {
            $sql = substr_replace($sql, $replacement, $offset, $length);
        }
        return $sql;
    }

    /**
     * Find a pattern at the parenthesis depth of the offset, null if the enclosing parentheses close first
     *
     * @param string $masked SQL with literals masked
     * @param int $offset
     * @param string $pattern anchored with \G
     * @return int|null
     */
    private function findAtSameDepth(string $masked, int $offset, string $pattern)
    {
        $depth = 0;
        $length = strlen($masked);
        for ($i = $offset; $i < $length; $i++) {
            $char = $masked[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
            } elseif ($depth === 0
                && ($i === 0 || !preg_match('/\w/', $masked[$i - 1]))
                && preg_match($pattern, $masked, $matches, 0, $i)
            ) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Check whether a select is a branch of a UNION: UNION precedes it or follows its end
     *
     * @param string $masked SQL with literals masked
     * @param int $selectPosition
     * @param int $fromPosition
     * @return bool
     */
    private function isUnionBranch(string $masked, int $selectPosition, int $fromPosition): bool
    {
        if (preg_match('/\bUNION(?:\s+ALL)?[\s(]*$/i', substr($masked, 0, $selectPosition))) {
            return true;
        }
        $end = $this->findAtSameDepth($masked, $fromPosition, '/\GUNION\b/i');
        if ($end !== null) {
            return true;
        }
        // The select is a parenthesized group followed by UNION: "(SELECT ...) UNION ALL (SELECT ...)". The group
        // must start the statement, a group or follow UNION; "x IN (SELECT ...)" is a subquery, not a branch.
        if (!preg_match('/(?:^|\(|\bUNION(?:\s+ALL)?)\s*\(\s*$/i', substr($masked, 0, $selectPosition))) {
            return false;
        }
        $depth = 0;
        $length = strlen($masked);
        for ($i = $fromPosition; $i < $length; $i++) {
            if ($masked[$i] === '(') {
                $depth++;
            } elseif ($masked[$i] === ')' && $depth-- === 0) {
                return (bool)preg_match('/^[\s)]*UNION\b/i', substr($masked, $i));
            }
        }
        return false;
    }

    /**
     * Cast the value items of a select list
     *
     * @param string $list
     * @return string
     */
    private function castSelectList(string $list): string
    {
        $distinct = '';
        if (preg_match('/^\s*(DISTINCT\s+)(.*)$/is', $list, $matches)) {
            list(, $distinct, $list) = $matches;
        }
        $items = $this->scanner->splitList($list);
        $isChanged = false;
        foreach ($items as $key => $item) {
            if (preg_match('/^(.*?)\s+AS\s+("(?:[^"]|"")+"|\w+)$/is', $item, $matches)) {
                list(, $expression, $name) = $matches;
                $name = trim($name, '"');
            } elseif (preg_match('/^(?:"?\w+"?\.)?"?(\w+)"?$/', $item, $matches)) {
                list($expression, $name) = $matches;
            } else {
                continue;
            }
            if (!in_array($name, self::VALUE_COLUMNS, true) || preg_match('/::text$/i', $expression)) {
                continue;
            }
            $items[$key] = sprintf('(%s)::text AS "%s"', $expression, $name);
            $isChanged = true;
        }
        return $isChanged ? $distinct . implode(', ', $items) : $distinct . $list;
    }
}
