<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Test\Unit\Sql;

use Horobyna\Db\Sql\Scanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScannerTest extends TestCase
{
    /**
     * @var Scanner
     */
    private $scanner;

    protected function setUp(): void
    {
        $this->scanner = new Scanner();
    }

    public static function verbatimEndProvider(): array
    {
        return [
            'string literal' => ["x = 'a,b' AND", 4, 9],
            'doubled quote' => ["'it''s' x", 0, 7],
            'escape string' => ["E'a\\'b' x", 0, 7],
            'quoted identifier' => ['"my""col" x', 0, 9],
            'line comment' => ["-- note\nSELECT", 0, 7],
            'block comment' => ['/* a ) b */ x', 0, 11],
            'dollar quoted' => ['$fn$ a $ b $fn$ x', 0, 15],
            'plain char' => ['SELECT 1', 0, null],
            'E not before quote' => ['END', 0, null],
            'E inside identifier' => ["somE'x'", 3, null],
            'single minus' => ['a - b', 2, null],
        ];
    }

    #[DataProvider('verbatimEndProvider')]
    public function testVerbatimEnd(string $sql, int $offset, ?int $expected): void
    {
        $this->assertSame($expected, $this->scanner->verbatimEnd($sql, $offset));
    }

    public static function splitListProvider(): array
    {
        return [
            'empty' => ['  ', []],
            'simple' => ['a, b ,c', ['a', 'b', 'c']],
            'nested calls' => ['f(a, b), g(h(c, d)), e', ['f(a, b)', 'g(h(c, d))', 'e']],
            'commas in literals' => ["'a,b', \"c,d\", e", ["'a,b'", '"c,d"', 'e']],
            'parenthesis in literal' => ["'(', x", ["'('", 'x']],
        ];
    }

    #[DataProvider('splitListProvider')]
    public function testSplitList(string $list, array $expected): void
    {
        $this->assertSame($expected, $this->scanner->splitList($list));
    }

    public function testMaskKeepsOffsets(): void
    {
        $sql = "SELECT 'FROM' AS \"x\" -- FROM\nFROM t";
        $masked = $this->scanner->mask($sql);

        $this->assertSame(strlen($sql), strlen($masked));
        $this->assertSame("SELECT        AS            \nFROM t", $masked);
    }

    public function testMaskWithFill(): void
    {
        $this->assertSame('ANALYZE TABLE xxxxx', $this->scanner->mask('ANALYZE TABLE "a b"', 'x'));
    }
}
