<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Test\Unit\Sql;

use Horobyna\Db\Sql\FunctionMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FunctionMapperTest extends TestCase
{
    /**
     * @var FunctionMapper
     */
    private $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FunctionMapper();
    }

    public static function mapProvider(): array
    {
        return [
            'ifnull' => [
                "SELECT IFNULL(a, 'x') FROM t",
                "SELECT COALESCE(a, 'x') FROM t",
            ],
            'nested ifnull' => [
                'SELECT IFNULL(IFNULL(a, b), c) FROM t',
                'SELECT COALESCE(COALESCE(a, b), c) FROM t',
            ],
            'if' => [
                "SELECT IF(a > 1, 'y', 'n') FROM t",
                "SELECT (CASE WHEN a > 1 THEN 'y' ELSE 'n' END) FROM t",
            ],
            'find_in_set as condition' => [
                'SELECT * FROM t WHERE FIND_IN_SET(2, ids)',
                'SELECT * FROM t WHERE (COALESCE(array_position(string_to_array(CAST(ids AS TEXT), \',\'),'
                    . ' CAST(2 AS TEXT)), 0) > 0)',
            ],
            'group_concat' => [
                "SELECT GROUP_CONCAT(DISTINCT name ORDER BY name SEPARATOR ', ') FROM t",
                "SELECT STRING_AGG(DISTINCT CAST(name AS TEXT), ', ' ORDER BY CAST(name AS TEXT)) FROM t",
            ],
            'field' => [
                'SELECT FIELD(id, 3, 1, 2) FROM t',
                'SELECT COALESCE(array_position(ARRAY[CAST(3 AS TEXT), CAST(1 AS TEXT), CAST(2 AS TEXT)],'
                    . ' CAST(id AS TEXT)), 0) FROM t',
            ],
            'date_format' => [
                "SELECT DATE_FORMAT(created_at, '%Y-%m-%d') FROM t",
                'SELECT TO_CHAR(CAST(created_at AS TIMESTAMP), \'YYYY"-"MM"-"DD\') FROM t',
            ],
            'date_add' => [
                'SELECT DATE_ADD(created_at, INTERVAL 1 DAY) FROM t',
                "SELECT (CAST(created_at AS TIMESTAMP) + (1) * INTERVAL '1 day') FROM t",
            ],
            'bare interval' => [
                'SELECT * FROM t WHERE updated_at < NOW() - INTERVAL 7 DAY',
                "SELECT * FROM t WHERE updated_at < NOW() - (7 * INTERVAL '1 day')",
            ],
            'sql value function' => [
                'SELECT CURRENT_TIMESTAMP() FROM t',
                'SELECT CURRENT_TIMESTAMP FROM t',
            ],
            'cast as signed' => [
                'SELECT CAST(x AS SIGNED) FROM t',
                "SELECT CAST(COALESCE(SUBSTRING(CAST(x AS TEXT) FROM '^[[:space:]]*([-+]{0,1}[0-9]{1,18})'), '0')"
                    . ' AS BIGINT) FROM t',
            ],
            'call in literal' => [
                "SELECT 'IFNULL(a, b)' FROM t",
                "SELECT 'IFNULL(a, b)' FROM t",
            ],
            'wrong argument count' => [
                'SELECT IFNULL(a) FROM t',
                'SELECT IFNULL(a) FROM t',
            ],
            'postgres cast' => [
                'SELECT CAST(x AS INTEGER) FROM t',
                'SELECT CAST(x AS INTEGER) FROM t',
            ],
        ];
    }

    #[DataProvider('mapProvider')]
    public function testMap(string $sql, string $expected): void
    {
        $this->assertSame($expected, $this->mapper->map($sql));
    }

    public function testTranslateDateFormat(): void
    {
        $this->assertSame(
            'YYYY"-"MM"-"DD" "HH24":"MI":"SS',
            $this->mapper->translateDateFormat('%Y-%m-%d %H:%i:%s')
        );
    }
}
