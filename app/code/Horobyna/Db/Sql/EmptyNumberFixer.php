<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Sql;

/**
 * Replaces an empty string that PostgreSQL rejected as a number with '0'
 *
 * MySQL compares '' with a number as 0, and Magento relies on it: PHP null is quoted as '' by Zend_Db, so e.g.
 * loading an entity by a null id runs "entity_id = ''", which finds nothing in MySQL and fails in PostgreSQL with
 * "invalid input syntax for type bigint". The column type is unknown when the value is quoted, but the error
 * points at the rejected literal (line, excerpt and caret), so exactly that literal is replaced.
 */
class EmptyNumberFixer
{
    /**
     * Error raised for '' in a numeric context, with the position PostgreSQL reports
     */
    private const ERROR_PATTERN = '/invalid input syntax for type (?:smallint|integer|bigint|numeric|real|double precision): ""'
        . '\nLINE (\d+): ([^\n]*)\n( *)\^/';

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
     * Fix the literal an error points at
     *
     * @param string $sql statement as sent to PDO
     * @param array $bind bound values (positional or named)
     * @param string $errorMessage
     * @return array|null [sql, bind] with the literal replaced, null if the error is not about '' or cannot be located
     */
    public function fix(string $sql, array $bind, string $errorMessage)
    {
        if (!preg_match(self::ERROR_PATTERN, $errorMessage, $matches)) {
            return null;
        }
        list(, $lineNumber, $excerpt, $caretIndent) = $matches;
        $caret = strlen($caretIndent) - strlen('LINE ' . $lineNumber . ': ');
        if (strncmp($excerpt, '...', 3) === 0) {
            $excerpt = substr($excerpt, 3);
            $caret -= 3;
        }
        if (substr($excerpt, -3) === '...') {
            $excerpt = substr($excerpt, 0, -3);
        }

        // The excerpt shows the statement after PDO filled in the bound values
        $interpolated = $this->interpolate($sql, $bind);
        if ($interpolated === null) {
            return null;
        }
        list($text, $segments) = $interpolated;

        $lineStart = 0;
        for ($line = 1; $line < (int)$lineNumber; $line++) {
            $lineStart = strpos($text, "\n", $lineStart);
            if ($lineStart === false) {
                return null;
            }
            $lineStart++;
        }
        $lineEnd = strpos($text, "\n", $lineStart);
        $lineText = $lineEnd === false ? substr($text, $lineStart) : substr($text, $lineStart, $lineEnd - $lineStart);

        $offset = -1;
        while (($offset = strpos($lineText, $excerpt, $offset + 1)) !== false) {
            $position = $lineStart + $offset + $caret;
            if (substr($text, $position, 2) === "''") {
                return $this->replace($sql, $bind, $segments, $position);
            }
        }
        return null;
    }

    /**
     * Replace the literal at a position of the interpolated statement
     *
     * @param string $sql
     * @param array $bind
     * @param array $segments
     * @param int $position
     * @return array|null
     */
    private function replace(string $sql, array $bind, array $segments, int $position)
    {
        $shift = 0;
        foreach ($segments as list($sqlOffset, $placeholderLength, $textOffset, $valueLength, $key)) {
            if ($position >= $textOffset && $position < $textOffset + $valueLength) {
                // The literal is a bound value
                $bind[$key] = '0';
                return [$sql, $bind];
            }
            if ($textOffset > $position) {
                break;
            }
            $shift = ($textOffset + $valueLength) - ($sqlOffset + $placeholderLength);
        }
        $sqlPosition = $position - $shift;
        if (substr($sql, $sqlPosition, 2) !== "''") {
            return null;
        }
        return [substr_replace($sql, "'0'", $sqlPosition, 2), $bind];
    }

    /**
     * Fill in bound values the way PDO emulated prepares do, keeping track of where they are
     *
     * @param string $sql
     * @param array $bind
     * @return array|null [text, segments], segments: [sql offset, placeholder length, text offset, value length, key]
     */
    private function interpolate(string $sql, array $bind)
    {
        if (!$bind) {
            return [$sql, []];
        }
        $text = '';
        $segments = [];
        $positional = 0;
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $verbatimEnd = $this->scanner->verbatimEnd($sql, $i);
            if ($verbatimEnd !== null) {
                $text .= substr($sql, $i, $verbatimEnd - $i);
                $i = $verbatimEnd;
                continue;
            }
            $placeholder = null;
            if ($sql[$i] === '?') {
                $placeholder = '?';
                $key = $positional++;
            } elseif ($sql[$i] === ':' && ($sql[$i - 1] ?? '') !== ':'
                && preg_match('/\G:[A-Za-z_]\w*/', $sql, $matches, 0, $i)
            ) {
                $placeholder = $matches[0];
                $key = $placeholder;
            }
            if ($placeholder === null || !array_key_exists($key, $bind)) {
                $text .= $sql[$i];
                $i++;
                continue;
            }
            $value = $bind[$key];
            if (is_object($value) || is_array($value)) {
                return null;
            }
            $quoted = $value === null ? 'NULL' : "'" . str_replace("'", "''", (string)$value) . "'";
            $segments[] = [$i, strlen($placeholder), strlen($text), strlen($quoted), $key];
            $text .= $quoted;
            $i += strlen($placeholder);
        }
        return [$text, $segments];
    }
}
