<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Test\Unit\Sql;

use Horobyna\Db\Sql\SyntaxMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SyntaxMapperTest extends TestCase
{
    public static function mapProvider(): array
    {
        return [
            'regexp' => [
                "SELECT * FROM t WHERE sku REGEXP '^a'",
                "SELECT * FROM t WHERE sku ~* '^a'",
            ],
            'not rlike' => [
                "SELECT * FROM t WHERE sku NOT RLIKE '^a'",
                "SELECT * FROM t WHERE sku !~* '^a'",
            ],
            'regexp in literal' => [
                "SELECT 'a REGEXP b' FROM t",
                "SELECT 'a REGEXP b' FROM t",
            ],
            'index hint' => [
                'SELECT * FROM t FORCE INDEX (idx_a) WHERE a = 1',
                'SELECT * FROM t WHERE a = 1',
            ],
            'index hint for order by' => [
                'SELECT * FROM t USE KEY FOR ORDER BY (idx_a, idx_b) ORDER BY a',
                'SELECT * FROM t ORDER BY a',
            ],
            'optimize table' => [
                'OPTIMIZE TABLE "catalog_product_entity", sales_order;',
                'ANALYZE "catalog_product_entity", sales_order',
            ],
            'analyze local table' => [
                'ANALYZE LOCAL TABLE t',
                'ANALYZE t',
            ],
            'delete limit' => [
                'DELETE FROM t WHERE a = 1 ORDER BY id LIMIT 100',
                'DELETE FROM t WHERE ctid IN (SELECT ctid FROM t WHERE a = 1 ORDER BY id LIMIT 100)',
            ],
            'update limit with alias' => [
                'UPDATE t AS x SET a = 2 WHERE b IN (1, 2) LIMIT ?',
                'UPDATE t AS x SET a = 2 WHERE x.ctid IN (SELECT x.ctid FROM t AS x WHERE b IN (1, 2) LIMIT ?)',
            ],
            'limit in subquery only' => [
                'DELETE FROM t WHERE id IN (SELECT id FROM s LIMIT 5)',
                'DELETE FROM t WHERE id IN (SELECT id FROM s LIMIT 5)',
            ],
            'plain select' => [
                'SELECT * FROM t LIMIT 10',
                'SELECT * FROM t LIMIT 10',
            ],
        ];
    }

    #[DataProvider('mapProvider')]
    public function testMap(string $sql, string $expected): void
    {
        $this->assertSame($expected, (new SyntaxMapper())->map($sql));
    }
}
