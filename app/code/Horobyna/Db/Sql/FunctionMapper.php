<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Sql;

/**
 * Rewrites MySQL functions in SQL to PostgreSQL equivalents
 *
 * Magento core and extensions write raw MySQL functions (FIND_IN_SET, IFNULL, GROUP_CONCAT, FIELD, ...).
 * The mapper parses calls with their arguments, so nested calls and arguments containing commas or parentheses
 * are handled, while string literals, quoted identifiers, comments and dollar-quoted bodies are left untouched.
 *
 * Supported functions:
 * - FIND_IN_SET(s, list)      position of s in comma separated list; a boolean when used as a condition
 * - IFNULL(a, b)              COALESCE(a, b)
 * - IF(cond, a, b)            CASE WHEN cond THEN a ELSE b END; a non-condition cond counts as true if non-zero
 * - GROUP_CONCAT([DISTINCT] expr[, expr...] [ORDER BY ...] [SEPARATOR 's'])   STRING_AGG()
 * - FIELD(s, a, b, ...)       position of s in the list, 0 if absent
 * - DATE_FORMAT(d, 'format')  TO_CHAR() with the format translated
 * - DATE_ADD/ADDDATE/DATE_SUB/SUBDATE(d, INTERVAL n UNIT | days)
 * - DATEDIFF(a, b), UNIX_TIMESTAMP([d]), FROM_UNIXTIME(n[, 'format']), CURDATE(), CURTIME()
 * - CURRENT_TIMESTAMP(), CURRENT_DATE(), CURRENT_TIME(), LOCALTIMESTAMP(), LOCALTIME()   without parentheses
 * - UTC_TIMESTAMP(), UTC_DATE(), TIMESTAMPDIFF(UNIT, a, b), TIMESTAMPADD(UNIT, n, d), TIMEDIFF(a, b),
 *   TIME_TO_SEC(t), STR_TO_DATE(s, 'format')
 * - RAND(), LAST_INSERT_ID(), LCASE(), UCASE(), INET_ATON(), INET_NTOA()
 * - INSTR(s, sub)             STRPOS(); a boolean when used as a condition
 * - JSON_EXTRACT(doc, '$.a.b') jsonb #> path, for a single path of keys and array indexes
 * - CAST(x AS SIGNED|UNSIGNED [INTEGER])  BIGINT of the leading integer of x, 0 if there is none, as MySQL
 *   converts strings (a decimal is truncated, where MySQL rounds)
 * - MAX/MIN/SUM/AVG(condition)  the condition is counted as 1 or 0, as in MySQL (e.g. SUM(qty > 0))
 *
 * Interval literals outside of DATE_ADD/DATE_SUB ("NOW() - INTERVAL 1 DAY") are rewritten too, when the amount
 * is a number, a placeholder or a column.
 *
 * A call that does not match the supported form (e.g. a wrong number of arguments) is left as is.
 */
class FunctionMapper
{
    /**
     * Functions handled by the mapper, lowercase name => handler method
     */
    private const FUNCTIONS = [
        'find_in_set'    => 'mapFindInSet',
        'ifnull'         => 'mapIfNull',
        'if'             => 'mapIf',
        'group_concat'   => 'mapGroupConcat',
        'field'          => 'mapField',
        'date_format'    => 'mapDateFormat',
        'date_add'       => 'mapDateAdd',
        'adddate'        => 'mapDateAdd',
        'date_sub'       => 'mapDateSub',
        'subdate'        => 'mapDateSub',
        'datediff'       => 'mapDateDiff',
        'unix_timestamp' => 'mapUnixTimestamp',
        'from_unixtime'  => 'mapFromUnixtime',
        'curdate'        => 'mapCurdate',
        'curtime'        => 'mapCurtime',
        'rand'           => 'mapRand',
        'last_insert_id' => 'mapLastInsertId',
        'lcase'          => 'mapLcase',
        'ucase'          => 'mapUcase',
        'inet_aton'      => 'mapInetAton',
        'inet_ntoa'      => 'mapInetNtoa',
        'current_timestamp' => 'mapSqlValueFunction',
        'current_date'   => 'mapSqlValueFunction',
        'current_time'   => 'mapSqlValueFunction',
        'localtimestamp' => 'mapSqlValueFunction',
        'localtime'      => 'mapSqlValueFunction',
        'utc_timestamp'  => 'mapUtcTimestamp',
        'utc_date'       => 'mapUtcDate',
        'timestampdiff'  => 'mapTimestampDiff',
        'timestampadd'   => 'mapTimestampAdd',
        'timediff'       => 'mapTimeDiff',
        'time_to_sec'    => 'mapTimeToSec',
        'str_to_date'    => 'mapStrToDate',
        'instr'          => 'mapInstr',
        'json_extract'   => 'mapJsonExtract',
        // Not in the quick check: CAST() is common, only "AS SIGNED/UNSIGNED" is MySQL-only
        'cast'           => 'mapCast',
        'max'            => 'mapConditionAggregate',
        'min'            => 'mapConditionAggregate',
        'sum'            => 'mapConditionAggregate',
        'avg'            => 'mapConditionAggregate',
    ];

    /**
     * MySQL interval units => PostgreSQL interval of one unit
     */
    private const INTERVAL_UNITS = [
        'MICROSECOND' => '1 microsecond',
        'SECOND'      => '1 second',
        'MINUTE'      => '1 minute',
        'HOUR'        => '1 hour',
        'DAY'         => '1 day',
        'WEEK'        => '1 week',
        'MONTH'       => '1 month',
        'QUARTER'     => '3 months',
        'YEAR'        => '1 year',
    ];

    /**
     * Seconds in a TIMESTAMPDIFF() unit counted by epoch difference; months, quarters and years use AGE()
     */
    private const UNIT_SECONDS = [
        'MICROSECOND' => '0.000001',
        'SECOND'      => '1',
        'MINUTE'      => '60',
        'HOUR'        => '3600',
        'DAY'         => '86400',
        'WEEK'        => '604800',
    ];

    /**
     * MySQL interval literal with an unquoted amount: a number, a placeholder or a (qualified) column
     */
    private const INTERVAL_PATTERN = '/\bINTERVAL\s+([-+]?\d+(?:\.\d+)?|\?|:\w+|[A-Za-z_][\w.]*)\s+'
        . '(MICROSECOND|SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|QUARTER|YEAR)\b/i';

    /**
     * MySQL DATE_FORMAT specifier => PostgreSQL TO_CHAR pattern
     */
    private const DATE_FORMAT_MAP = [
        '%Y' => 'YYYY',
        '%y' => 'YY',
        '%m' => 'MM',
        '%c' => 'FMMM',
        '%d' => 'DD',
        '%e' => 'FMDD',
        '%H' => 'HH24',
        '%k' => 'FMHH24',
        '%h' => 'HH12',
        '%I' => 'HH12',
        '%l' => 'FMHH12',
        '%i' => 'MI',
        '%s' => 'SS',
        '%S' => 'SS',
        '%p' => 'AM',
        '%M' => 'FMMonth',
        '%b' => 'Mon',
        '%W' => 'FMDay',
        '%a' => 'Dy',
        '%j' => 'DDD',
        '%T' => 'HH24:MI:SS',
        '%f' => 'US',
    ];

    /**
     * @var string|null
     */
    private $quickCheckPattern;

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
     * Rewrite MySQL functions in an SQL statement
     *
     * @param string $sql
     * @return string
     */
    public function map(string $sql): string
    {
        if ($this->quickCheckPattern === null) {
            $names = array_diff(array_keys(self::FUNCTIONS), ['cast']);
            $this->quickCheckPattern = '/\b(?:' . implode('|', $names) . ')\s*\(|\bAS\s+(?:UN)?SIGNED\b/i';
        }
        if (preg_match($this->quickCheckPattern, $sql)) {
            $sql = $this->rewrite($sql);
        }
        // After the calls: DATE_ADD/DATE_SUB take their INTERVAL argument as is
        return stripos($sql, 'INTERVAL') !== false ? $this->mapIntervals($sql) : $sql;
    }

    /**
     * Rewrite MySQL interval literals, e.g. "INTERVAL 1 DAY" => "(1 * INTERVAL '1 day')"
     *
     * @param string $sql
     * @return string
     */
    private function mapIntervals(string $sql): string
    {
        $masked = $this->scanner->mask($sql);
        if (!preg_match_all(self::INTERVAL_PATTERN, $masked, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $sql;
        }
        foreach (array_reverse($matches) as $match) {
            $amount = $match[1][0];
            if ($amount === '?' || $amount[0] === ':') {
                $amount = sprintf('CAST(%s AS DOUBLE PRECISION)', $amount);
            }
            $replacement = sprintf("(%s * INTERVAL '%s')", $amount, self::INTERVAL_UNITS[strtoupper($match[2][0])]);
            $sql = substr_replace($sql, $replacement, $match[0][1], strlen($match[0][0]));
        }
        return $sql;
    }

    /**
     * Translate a MySQL DATE_FORMAT() format to a PostgreSQL TO_CHAR() pattern
     *
     * Text outside of specifiers is double-quoted, otherwise TO_CHAR would read letters in it as patterns.
     *
     * @param string $format
     * @return string
     */
    public function translateDateFormat(string $format): string
    {
        $pattern = '';
        $literal = '';
        foreach (preg_split('/(%.)/', $format, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            if (isset(self::DATE_FORMAT_MAP[$part])) {
                $pattern .= ($literal !== '' ? '"' . $literal . '"' : '') . self::DATE_FORMAT_MAP[$part];
                $literal = '';
            } else {
                $literal .= $part === '%%' ? '%' : str_replace('"', '\\"', $part);
            }
        }
        return $pattern . ($literal !== '' ? '"' . $literal . '"' : '');
    }

    /**
     * Make an expression usable where PostgreSQL requires a boolean
     *
     * MySQL accepts any number as a condition (true when non-zero), e.g. IF(is_active, ...);
     * a NULL value is false in both cases.
     *
     * @param string $expression
     * @return string
     */
    public function toCondition(string $expression): string
    {
        // No "CAST(... AS ...)": the result can end up in a string passed to Zend_Db_Select::columns(), which splits
        // strings at " AS " into expression and alias
        return $this->isCondition($expression) ? $expression : sprintf('((%s))::integer <> 0', $expression);
    }

    /**
     * Rewrite all supported function calls in an SQL fragment
     *
     * @param string $sql
     * @return string
     */
    private function rewrite(string $sql): string
    {
        $result = '';
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $verbatimEnd = $this->scanner->verbatimEnd($sql, $i);
            if ($verbatimEnd !== null) {
                $result .= substr($sql, $i, $verbatimEnd - $i);
                $i = $verbatimEnd;
                continue;
            }

            $char = $sql[$i];
            if (!ctype_alpha($char) && $char !== '_') {
                $result .= $char;
                $i++;
                continue;
            }

            preg_match('/\G[A-Za-z_][\w$]*/', $sql, $matches, 0, $i);
            $name = $matches[0];
            $nameEnd = $i + strlen($name);
            $handler = self::FUNCTIONS[strtolower($name)] ?? null;
            $isQualified = $i > 0 && $sql[$i - 1] === '.';
            $call = $handler !== null && !$isQualified ? $this->parseCall($sql, $nameEnd) : null;
            if ($call === null) {
                $result .= $name;
                $i = $nameEnd;
                continue;
            }

            list($inner, $callEnd) = $call;
            $arguments = array_map([$this, 'rewrite'], $this->scanner->splitList($inner));
            $replacement = $this->$handler($arguments, $result, substr($sql, $callEnd), $name);
            $result .= $replacement ?? sprintf('%s(%s)', $name, implode(', ', $arguments));
            $i = $callEnd;
        }

        return $result;
    }

    /**
     * Parse the argument list of a call whose name ends at the offset
     *
     * @param string $sql
     * @param int $offset
     * @return array|null [inner text, offset after the closing parenthesis], null if this is not a call
     */
    private function parseCall(string $sql, int $offset)
    {
        $length = strlen($sql);
        while ($offset < $length && ctype_space($sql[$offset])) {
            $offset++;
        }
        if (($sql[$offset] ?? '') !== '(') {
            return null;
        }

        $start = $offset + 1;
        $depth = 1;
        $i = $start;
        while ($i < $length) {
            $verbatimEnd = $this->scanner->verbatimEnd($sql, $i);
            if ($verbatimEnd !== null) {
                $i = $verbatimEnd;
                continue;
            }
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')' && --$depth === 0) {
                return [substr($sql, $start, $i - $start), $i + 1];
            }
            $i++;
        }

        return null;
    }

    /**
     * Check whether a call is used as a condition rather than as a value
     *
     * MySQL treats a non-zero number as true; PostgreSQL requires a boolean in WHERE, AND, NOT...
     *
     * @param string $before SQL before the call (already rewritten)
     * @param string $after SQL after the call
     * @return bool
     */
    private function isConditionContext(string $before, string $after): bool
    {
        $isPrecededByCondition = preg_match('/(?:\b(?:WHERE|AND|OR|NOT|ON|HAVING|WHEN)|\()\s*$/i', $before);
        $isFollowedByOperator = preg_match('/^\s*(?:[<>=!+\-*\/%|]|IS\b|BETWEEN\b|IN\b|LIKE\b)/i', $after);
        return $isPrecededByCondition && !$isFollowedByOperator;
    }

    /**
     * Check whether an expression is a condition at its top level, e.g. "a != 2" but not "f(a != 2)"
     *
     * @param string $expression
     * @return bool
     */
    public function isCondition(string $expression): bool
    {
        // Keep only the top level: drop literals, quoted identifiers and parenthesized parts
        $topLevel = '';
        $depth = 0;
        $length = strlen($expression);
        $i = 0;
        while ($i < $length) {
            $verbatimEnd = $this->scanner->verbatimEnd($expression, $i);
            if ($verbatimEnd !== null) {
                $topLevel .= ' x ';
                $i = $verbatimEnd;
                continue;
            }
            $char = $expression[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($depth === 0) {
                $topLevel .= $char;
            }
            $i++;
        }
        return (bool)preg_match('/[<>=]|!=|\b(?:IS|NOT|AND|OR|LIKE|ILIKE|IN|BETWEEN|EXISTS)\b/i', $topLevel);
    }

    /**
     * MAX/MIN/SUM/AVG over a condition
     *
     * @param string[] $args
     * @param string $before
     * @param string $after
     * @param string $name
     * @return string|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function mapConditionAggregate(array $args, string $before, string $after, string $name)
    {
        if (count($args) !== 1 || !$this->isCondition($args[0]) || preg_match('/^DISTINCT\b/i', $args[0])) {
            return null;
        }
        return sprintf('%s(CAST((%s) AS INTEGER))', $name, $args[0]);
    }

    /**
     * FIND_IN_SET(s, list)
     *
     * @param string[] $args
     * @param string $before
     * @param string $after
     * @return string|null
     */
    private function mapFindInSet(array $args, string $before, string $after)
    {
        if (count($args) !== 2) {
            return null;
        }
        $position = sprintf(
            "COALESCE(array_position(string_to_array(CAST(%s AS TEXT), ','), CAST(%s AS TEXT)), 0)",
            $args[1],
            $args[0]
        );
        return $this->isConditionContext($before, $after) ? sprintf('(%s > 0)', $position) : $position;
    }

    /**
     * IFNULL(a, b)
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapIfNull(array $args)
    {
        return count($args) === 2 ? sprintf('COALESCE(%s, %s)', $args[0], $args[1]) : null;
    }

    /**
     * IF(cond, a, b)
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapIf(array $args)
    {
        return count($args) === 3
            ? sprintf('(CASE WHEN %s THEN %s ELSE %s END)', $this->toCondition($args[0]), $args[1], $args[2])
            : null;
    }

    /**
     * GROUP_CONCAT([DISTINCT] expr[, expr...] [ORDER BY ...] [SEPARATOR 's'])
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapGroupConcat(array $args)
    {
        $inner = implode(', ', $args);
        if (!preg_match(
            '/^\s*(DISTINCT\s+)?(.*?)(?:\s+ORDER\s+BY\s+(.*?))?(?:\s+SEPARATOR\s+(\'(?:[^\']|\'\')*\'))?\s*$/is',
            $inner,
            $matches
        )) {
            return null;
        }
        $distinct = !empty($matches[1]) ? 'DISTINCT ' : '';
        $expressions = $this->scanner->splitList($matches[2]);
        $value = count($expressions) > 1
            ? sprintf('CONCAT(%s)', implode(', ', $expressions))
            : sprintf('CAST(%s AS TEXT)', $expressions[0]);
        $separator = !empty($matches[4]) ? $matches[4] : "','";
        $order = '';
        if (!empty($matches[3])) {
            // With DISTINCT, PostgreSQL only allows ordering by the aggregated value itself
            $order = $distinct
                ? ' ORDER BY ' . $value . (preg_match('/\bDESC\s*$/i', $matches[3]) ? ' DESC' : '')
                : ' ORDER BY ' . $matches[3];
        }

        return sprintf('STRING_AGG(%s%s, %s%s)', $distinct, $value, $separator, $order);
    }

    /**
     * FIELD(s, a, b, ...)
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapField(array $args)
    {
        if (count($args) < 2) {
            return null;
        }
        $needle = array_shift($args);
        $list = array_map(fn ($arg) => sprintf('CAST(%s AS TEXT)', $arg), $args);
        return sprintf(
            'COALESCE(array_position(ARRAY[%s], CAST(%s AS TEXT)), 0)',
            implode(', ', $list),
            $needle
        );
    }

    /**
     * DATE_FORMAT(d, 'format')
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapDateFormat(array $args)
    {
        if (count($args) !== 2 || !preg_match('/^\'((?:[^\']|\'\')*)\'$/s', $args[1], $matches)) {
            return null;
        }
        $pattern = $this->translateDateFormat(str_replace("''", "'", $matches[1]));
        return sprintf("TO_CHAR(CAST(%s AS TIMESTAMP), '%s')", $args[0], str_replace("'", "''", $pattern));
    }

    /**
     * DATE_ADD(d, INTERVAL n UNIT), ADDDATE(d, days)
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapDateAdd(array $args)
    {
        return $this->mapDateArithmetic($args, '+');
    }

    /**
     * DATE_SUB(d, INTERVAL n UNIT), SUBDATE(d, days)
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapDateSub(array $args)
    {
        return $this->mapDateArithmetic($args, '-');
    }

    /**
     * Add or subtract an interval
     *
     * @param string[] $args
     * @param string $operator
     * @return string|null
     */
    private function mapDateArithmetic(array $args, string $operator)
    {
        if (count($args) !== 2) {
            return null;
        }
        if (preg_match('/^INTERVAL\s+(.+?)\s+([A-Z_]+)$/is', $args[1], $matches)) {
            $unit = self::INTERVAL_UNITS[strtoupper($matches[2])] ?? null;
            if ($unit === null) {
                return null;
            }
            $amount = $matches[1];
        } else {
            $unit = self::INTERVAL_UNITS['DAY'];
            $amount = $args[1];
        }
        return sprintf("(CAST(%s AS TIMESTAMP) %s (%s) * INTERVAL '%s')", $args[0], $operator, $amount, $unit);
    }

    /**
     * DATEDIFF(a, b) - days between dates
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapDateDiff(array $args)
    {
        return count($args) === 2
            ? sprintf('(CAST(%s AS DATE) - CAST(%s AS DATE))', $args[0], $args[1])
            : null;
    }

    /**
     * UNIX_TIMESTAMP([d])
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapUnixTimestamp(array $args)
    {
        if (count($args) > 1) {
            return null;
        }
        $date = $args ? sprintf('CAST(%s AS TIMESTAMP)', $args[0]) : 'CURRENT_TIMESTAMP';
        return sprintf('CAST(FLOOR(EXTRACT(EPOCH FROM %s)) AS BIGINT)', $date);
    }

    /**
     * FROM_UNIXTIME(n[, 'format'])
     *
     * The adapter's session time zone is UTC, so the result is a UTC timestamp, as with MySQL in Magento.
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapFromUnixtime(array $args)
    {
        if (count($args) === 1) {
            return sprintf('CAST(TO_TIMESTAMP(%s) AS TIMESTAMP)', $args[0]);
        }
        if (count($args) === 2) {
            return $this->mapDateFormat([sprintf('TO_TIMESTAMP(%s)', $args[0]), $args[1]]);
        }
        return null;
    }

    /**
     * CURDATE()
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapCurdate(array $args)
    {
        return $args ? null : 'CURRENT_DATE';
    }

    /**
     * CURTIME()
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapCurtime(array $args)
    {
        return $args ? null : 'LOCALTIME';
    }

    /**
     * RAND()
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapRand(array $args)
    {
        return $args ? null : 'RANDOM()';
    }

    /**
     * LAST_INSERT_ID()
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapLastInsertId(array $args)
    {
        return $args ? null : 'LASTVAL()';
    }

    /**
     * LCASE(s)
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapLcase(array $args)
    {
        return count($args) === 1 ? sprintf('LOWER(%s)', $args[0]) : null;
    }

    /**
     * UCASE(s)
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapUcase(array $args)
    {
        return count($args) === 1 ? sprintf('UPPER(%s)', $args[0]) : null;
    }

    /**
     * INET_ATON('a.b.c.d')
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapInetAton(array $args)
    {
        return count($args) === 1 ? sprintf("(CAST(%s AS INET) - '0.0.0.0'::inet)", $args[0]) : null;
    }

    /**
     * INET_NTOA(n)
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapInetNtoa(array $args)
    {
        return count($args) === 1 ? sprintf("host('0.0.0.0'::inet + CAST(%s AS BIGINT))", $args[0]) : null;
    }

    /**
     * CURRENT_TIMESTAMP(), CURRENT_DATE(), ... - PostgreSQL only accepts these keywords without parentheses
     *
     * With an argument (precision) the call is valid PostgreSQL and is left as is.
     *
     * @param string[] $args
     * @param string $before
     * @param string $after
     * @param string $name
     * @return string|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function mapSqlValueFunction(array $args, string $before, string $after, string $name)
    {
        return $args ? null : strtoupper($name);
    }

    /**
     * UTC_TIMESTAMP()
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapUtcTimestamp(array $args)
    {
        return $args ? null : "(CURRENT_TIMESTAMP AT TIME ZONE 'UTC')";
    }

    /**
     * UTC_DATE()
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapUtcDate(array $args)
    {
        return $args ? null : "CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS DATE)";
    }

    /**
     * TIMESTAMPDIFF(UNIT, a, b) - whole units from a to b, truncated toward zero
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapTimestampDiff(array $args)
    {
        if (count($args) !== 3) {
            return null;
        }
        $unit = strtoupper($args[0]);
        if (isset(self::UNIT_SECONDS[$unit])) {
            return sprintf(
                'CAST(TRUNC(EXTRACT(EPOCH FROM (CAST(%s AS TIMESTAMP) - CAST(%s AS TIMESTAMP))) / %s) AS BIGINT)',
                $args[2],
                $args[1],
                self::UNIT_SECONDS[$unit]
            );
        }
        $months = ['MONTH' => 1, 'QUARTER' => 3, 'YEAR' => 12][$unit] ?? null;
        if ($months === null) {
            return null;
        }
        $age = sprintf('AGE(CAST(%s AS TIMESTAMP), CAST(%s AS TIMESTAMP))', $args[2], $args[1]);
        return sprintf(
            'CAST(TRUNC((EXTRACT(YEAR FROM %1$s) * 12 + EXTRACT(MONTH FROM %1$s)) / %2$d) AS BIGINT)',
            $age,
            $months
        );
    }

    /**
     * TIMESTAMPADD(UNIT, n, d)
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapTimestampAdd(array $args)
    {
        return count($args) === 3
            ? $this->mapDateArithmetic([$args[2], sprintf('INTERVAL %s %s', $args[1], $args[0])], '+')
            : null;
    }

    /**
     * TIMEDIFF(a, b) of two date-times, an interval
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapTimeDiff(array $args)
    {
        return count($args) === 2
            ? sprintf('(CAST(%s AS TIMESTAMP) - CAST(%s AS TIMESTAMP))', $args[0], $args[1])
            : null;
    }

    /**
     * TIME_TO_SEC(t) of a time, an interval or a 'hh:mm:ss' string
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapTimeToSec(array $args)
    {
        return count($args) === 1
            ? sprintf('CAST(TRUNC(EXTRACT(EPOCH FROM CAST(%s AS INTERVAL))) AS BIGINT)', $args[0])
            : null;
    }

    /**
     * STR_TO_DATE(s, 'format')
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapStrToDate(array $args)
    {
        if (count($args) !== 2 || !preg_match('/^\'((?:[^\']|\'\')*)\'$/s', $args[1], $matches)) {
            return null;
        }
        $pattern = $this->translateDateFormat(str_replace("''", "'", $matches[1]));
        return sprintf("CAST(TO_TIMESTAMP(%s, '%s') AS TIMESTAMP)", $args[0], str_replace("'", "''", $pattern));
    }

    /**
     * INSTR(s, sub) - 1-based position of sub in s, 0 if absent
     *
     * @param string[] $args
     * @param string $before
     * @param string $after
     * @return string|null
     */
    private function mapInstr(array $args, string $before, string $after)
    {
        if (count($args) !== 2) {
            return null;
        }
        $position = sprintf('STRPOS(CAST(%s AS TEXT), CAST(%s AS TEXT))', $args[0], $args[1]);
        return $this->isConditionContext($before, $after) ? sprintf('(%s > 0)', $position) : $position;
    }

    /**
     * JSON_EXTRACT(doc, '$.key[0].key')
     *
     * The result is jsonb, like the JSON value MySQL returns (strings stay quoted).
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapJsonExtract(array $args)
    {
        if (count($args) !== 2
            || !preg_match('/^\'\$((?:\.[A-Za-z_]\w*|\[\d+\])*)\'$/', $args[1], $matches)
            || !preg_match_all('/\.([A-Za-z_]\w*)|\[(\d+)\]/', $matches[1], $steps, PREG_SET_ORDER)
        ) {
            return null;
        }
        $path = array_map(fn ($step) => $step[1] !== '' ? $step[1] : $step[2], $steps);
        return sprintf("(CAST(%s AS JSONB) #> '{%s}')", $args[0], implode(',', $path));
    }

    /**
     * CAST(x AS SIGNED|UNSIGNED [INTEGER])
     *
     * MySQL converts a string to its leading integer (0 if there is none) instead of failing.
     *
     * @param string[] $args
     * @return string|null
     */
    private function mapCast(array $args)
    {
        if (count($args) !== 1
            || !preg_match('/^(.+)\s+AS\s+(?:UN)?SIGNED(?:\s+INT(?:EGER)?)?$/is', $args[0], $matches)
        ) {
            return null;
        }
        // No "?" in the pattern: it would be taken for a placeholder
        return sprintf(
            "CAST(COALESCE(SUBSTRING(CAST(%s AS TEXT) FROM '^[[:space:]]*([-+]{0,1}[0-9]{1,18})'), '0') AS BIGINT)",
            $matches[1]
        );
    }
}
