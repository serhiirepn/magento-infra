<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Sql;

/**
 * Helpers for scanning SQL text without looking into literals, quoted identifiers and comments
 */
class Scanner
{
    /**
     * SQL that must be copied verbatim: string literals, quoted identifiers, comments, dollar-quoted strings
     */
    private const VERBATIM_PATTERN = '/\G(?:'
        . '[Ee]\'(?:\\\\.|[^\'\\\\]|\'\')*\''
        . '|\'(?:[^\']|\'\')*\''
        . '|"(?:[^"]|"")*"'
        . '|--[^\n]*'
        . '|\/\*.*?\*\/'
        . '|\$((?:[A-Za-z_]\w*)?)\$.*?\$\1\$'
        . ')/s';

    /**
     * Characters that may start a verbatim part
     */
    private const VERBATIM_START = '\'"-/$Ee';

    /**
     * Get end offset of a literal, quoted identifier or comment starting at the offset, null if there is none
     *
     * @param string $sql
     * @param int $offset
     * @return int|null
     */
    public function verbatimEnd(string $sql, int $offset)
    {
        $char = $sql[$offset];
        if (strpos(self::VERBATIM_START, $char) === false) {
            return null;
        }
        if (($char === 'E' || $char === 'e')
            && (($sql[$offset + 1] ?? '') !== "'" || ($offset > 0 && preg_match('/[\w$]/', $sql[$offset - 1])))
        ) {
            return null;
        }
        if (preg_match(self::VERBATIM_PATTERN, $sql, $matches, 0, $offset)) {
            return $offset + strlen($matches[0]);
        }
        return null;
    }

    /**
     * Split a list on top level commas
     *
     * @param string $list
     * @return string[]
     */
    public function splitList(string $list): array
    {
        if (trim($list) === '') {
            return [];
        }
        $items = [];
        $depth = 0;
        $start = 0;
        $length = strlen($list);
        $i = 0;
        while ($i < $length) {
            $verbatimEnd = $this->verbatimEnd($list, $i);
            if ($verbatimEnd !== null) {
                $i = $verbatimEnd;
                continue;
            }
            $char = $list[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $items[] = trim(substr($list, $start, $i - $start));
                $start = $i + 1;
            }
            $i++;
        }
        $items[] = trim(substr($list, $start));

        return $items;
    }

    /**
     * Mask literals, quoted identifiers and comments with spaces, keeping offsets
     *
     * The result can be searched for keywords and parentheses; offsets map back to the original SQL.
     *
     * @param string $sql
     * @param string $fill character to mask with; a letter keeps each masked part a token, e.g. a table name
     * @return string
     */
    public function mask(string $sql, string $fill = ' '): string
    {
        $masked = '';
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $verbatimEnd = $this->verbatimEnd($sql, $i);
            if ($verbatimEnd !== null) {
                $masked .= str_repeat($fill, $verbatimEnd - $i);
                $i = $verbatimEnd;
                continue;
            }
            $masked .= $sql[$i];
            $i++;
        }
        return $masked;
    }
}
