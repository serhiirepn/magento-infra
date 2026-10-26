<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Test\Unit\Sql;

use Horobyna\Db\Sql\EmptyNumberFixer;
use PHPUnit\Framework\TestCase;

class EmptyNumberFixerTest extends TestCase
{
    /**
     * @var EmptyNumberFixer
     */
    private $fixer;

    protected function setUp(): void
    {
        $this->fixer = new EmptyNumberFixer();
    }

    /**
     * Build the error PostgreSQL raises for '' at a position of the interpolated statement line
     *
     * @param string $line
     * @param int $caret
     * @param string $type
     * @return string
     */
    private function error(string $line, int $caret, string $type = 'bigint'): string
    {
        return 'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type '
            . $type . ': ""' . "\nLINE 1: " . $line . "\n" . str_repeat(' ', strlen('LINE 1: ') + $caret) . '^';
    }

    public function testFixesLiteral(): void
    {
        $sql = "SELECT * FROM t WHERE code = '' AND id = ''";
        $error = $this->error($sql, strrpos($sql, "''"));

        $this->assertSame(
            ["SELECT * FROM t WHERE code = '' AND id = '0'", []],
            $this->fixer->fix($sql, [], $error)
        );
    }

    public function testFixesPositionalBind(): void
    {
        $sql = 'SELECT * FROM t WHERE code = ? AND id = ?';
        $line = "SELECT * FROM t WHERE code = 'a' AND id = ''";

        $this->assertSame(
            [$sql, ['a', '0']],
            $this->fixer->fix($sql, ['a', ''], $this->error($line, strrpos($line, "''"), 'integer'))
        );
    }

    public function testFixesNamedBind(): void
    {
        $sql = 'SELECT * FROM t WHERE id = :id';
        $line = "SELECT * FROM t WHERE id = ''";

        $this->assertSame(
            [$sql, [':id' => '0']],
            $this->fixer->fix($sql, [':id' => ''], $this->error($line, strrpos($line, "''")))
        );
    }

    public function testIgnoresOtherErrors(): void
    {
        $sql = "SELECT * FROM t WHERE id = 'abc'";
        $error = 'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type bigint: "abc"'
            . "\nLINE 1: " . $sql . "\n" . str_repeat(' ', 8 + 27) . '^';

        $this->assertNull($this->fixer->fix($sql, [], $error));
    }

    public function testReturnsNullWhenCaretMissesLiteral(): void
    {
        $sql = "SELECT * FROM t WHERE id = ''";

        $this->assertNull($this->fixer->fix($sql, [], $this->error($sql, 5)));
    }
}
