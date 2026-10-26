<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Test\Unit\Sql;

use Horobyna\Db\Sql\UnionValueCaster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnionValueCasterTest extends TestCase
{
    public static function castProvider(): array
    {
        return [
            'eav union all' => [
                'SELECT "t"."attribute_id", "t"."value" FROM "catalog_product_entity_int" AS "t"'
                    . ' UNION ALL SELECT "t"."attribute_id", "t"."value" FROM "catalog_product_entity_varchar" AS "t"',
                'SELECT "t"."attribute_id", ("t"."value")::text AS "value" FROM "catalog_product_entity_int" AS "t"'
                    . ' UNION ALL SELECT "t"."attribute_id", ("t"."value")::text AS "value"'
                    . ' FROM "catalog_product_entity_varchar" AS "t"',
            ],
            'parenthesized branches with aliases' => [
                '(SELECT a.value AS default_value FROM eav_int a) UNION (SELECT b.value AS default_value FROM eav_text b)',
                '(SELECT (a.value)::text AS "default_value" FROM eav_int a)'
                    . ' UNION (SELECT (b.value)::text AS "default_value" FROM eav_text b)',
            ],
            'already cast' => [
                'SELECT value::text AS value FROM t_int UNION SELECT value::text AS value FROM t_text',
                'SELECT value::text AS value FROM t_int UNION SELECT value::text AS value FROM t_text',
            ],
            'not a value table' => [
                'SELECT value FROM core_config_data UNION SELECT value FROM flag',
                'SELECT value FROM core_config_data UNION SELECT value FROM flag',
            ],
            'no union' => [
                'SELECT value FROM catalog_product_entity_int',
                'SELECT value FROM catalog_product_entity_int',
            ],
            'subquery is not a branch' => [
                'SELECT * FROM t WHERE id IN (SELECT value FROM x_int) AND note = \'UNION\'',
                'SELECT * FROM t WHERE id IN (SELECT value FROM x_int) AND note = \'UNION\'',
            ],
        ];
    }

    #[DataProvider('castProvider')]
    public function testCast(string $sql, string $expected): void
    {
        $this->assertSame($expected, (new UnionValueCaster())->cast($sql));
    }
}
