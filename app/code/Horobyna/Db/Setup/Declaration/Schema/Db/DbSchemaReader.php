<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema\Db;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Db\DbSchemaReaderInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraint;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Reference;
use Magento\Framework\Setup\Declaration\Schema\Dto\Factories\Table as TableFactory;
use Magento\Framework\Setup\Declaration\Schema\Dto\Index;
use Horobyna\Db\Adapter\Pdo\Pgsql;

/**
 * Reads the schema of a PostgreSQL database for the declarative schema
 *
 * Counterpart of \Magento\Framework\Setup\Declaration\Schema\Db\MySQL\DbSchemaReader. Elements are returned in
 * the form the DTO factories take, with PostgreSQL types mapped to declarative ones (integer => int,
 * bytea => blob, ...); since PostgreSQL has no unsigned types, columns are never unsigned (see
 * \Horobyna\Db\Setup\Declaration\Schema\Comparator). MySQL table options have no counterpart: every table is
 * reported with the default engine, charset and collation.
 */
class DbSchemaReader implements DbSchemaReaderInterface
{
    /**
     * PostgreSQL type (see SqlBuilder) => declarative column type
     */
    private const COLUMN_TYPE_MAP = [
        'smallint' => 'smallint',
        'integer' => 'int',
        'bigint' => 'bigint',
        'boolean' => 'boolean',
        'double precision' => 'float',
        'text' => 'text',
        'bytea' => 'blob',
        'json' => 'json',
        'date' => 'date',
        'timestamp' => 'timestamp',
    ];

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var SqlBuilder
     */
    private $sqlBuilder;

    /**
     * @var TableInspector
     */
    private $tableInspector;

    /**
     * @var TableFactory
     */
    private $tableFactory;

    /**
     * @param ResourceConnection $resourceConnection
     * @param SqlBuilder $sqlBuilder
     * @param TableInspector $tableInspector
     * @param TableFactory $tableFactory
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        SqlBuilder $sqlBuilder,
        TableInspector $tableInspector,
        TableFactory $tableFactory
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->sqlBuilder = $sqlBuilder;
        $this->tableInspector = $tableInspector;
        $this->tableFactory = $tableFactory;
    }

    /**
     * Return names of all tables of the current schema
     *
     * Reading starts here, so the DDL cache is cleared: the schema may have been changed by other processes.
     *
     * @param string $resource
     * @return string[]
     */
    public function readTables($resource)
    {
        $connection = $this->resourceConnection->getConnection($resource);
        $connection->resetDdlCache();
        return $connection->fetchCol(
            'SELECT c.relname FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace'
            . ' WHERE n.nspname = current_schema() AND c.relkind IN (\'r\', \'p\') AND NOT c.relispartition'
            . ' ORDER BY c.relname'
        );
    }

    /**
     * @inheritdoc
     */
    public function getTableOptions($tableName, $resource)
    {
        $connection = $this->resourceConnection->getConnection($resource);
        $comment = $connection->fetchOne(
            sprintf('SELECT obj_description(%s, \'pg_class\')', $this->getRegclassSql($connection, $tableName))
        );
        return [
            'engine' => TableFactory::DEFAULT_ENGINE,
            'comment' => $this->sqlBuilder->normalizeComment($comment ?: null),
            'charset' => $this->tableFactory->getDefaultCharset(),
            'collation' => $this->tableFactory->getDefaultCollation(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function readColumns($tableName, $resource)
    {
        /** @var Pgsql $connection */
        $connection = $this->resourceConnection->getConnection($resource);
        $comments = $connection->fetchPairs(
            sprintf(
                'SELECT attname, col_description(attrelid, attnum) FROM pg_attribute'
                . ' WHERE attrelid = %s AND attnum > 0 AND NOT attisdropped',
                $this->getRegclassSql($connection, $tableName)
            )
        );
        $onUpdateColumns = $connection->getOnUpdateTimestampColumns($tableName);

        $columns = [];
        foreach ($connection->describeTable($tableName) as $name => $columnData) {
            $pgType = $this->sqlBuilder->getDescribedType($columnData);
            $column = [
                'name' => $name,
                'nullable' => $columnData['NULLABLE'] && !$columnData['IDENTITY'],
                'identity' => $columnData['IDENTITY'],
                'unsigned' => false,
                'default' => $columnData['DEFAULT'],
                'comment' => $this->sqlBuilder->normalizeComment($comments[$name] ?? null),
            ];
            if (preg_match('/^(varchar|char)\((\d+)\)$/', $pgType, $matches)) {
                $column['type'] = $matches[1];
                $column['length'] = (int)$matches[2];
            } elseif (preg_match('/^numeric(?:\((\d+),(\d+)\))?$/', $pgType, $matches)) {
                $column['type'] = 'decimal';
                if (isset($matches[1])) {
                    $column['precision'] = (int)$matches[1];
                    $column['scale'] = (int)$matches[2];
                }
            } else {
                // Types that cannot be declared (uuid, arrays...) are reported as text
                $column['type'] = self::COLUMN_TYPE_MAP[$pgType] ?? 'text';
            }
            if ($column['type'] === 'timestamp') {
                $column['default'] = $this->sqlBuilder->normalizeDefault($column['default'], $pgType);
                if (isset($onUpdateColumns[$name])) {
                    $column['on_update'] = 'CURRENT_TIMESTAMP';
                }
            }
            $columns[$name] = $column;
        }

        return $columns;
    }

    /**
     * @inheritdoc
     */
    public function readIndexes($tableName, $resource)
    {
        $indexes = [];
        foreach ($this->tableInspector->getIndexes($tableName, $resource) as $name => $index) {
            $type = strtolower($index['INDEX_TYPE']);
            if ($type !== Pgsql::INDEX_TYPE_INDEX && $type !== Pgsql::INDEX_TYPE_FULLTEXT) {
                continue;
            }
            $indexes[$name] = [
                'name' => $name,
                'type' => Index::TYPE,
                'column' => $index['COLUMNS_LIST'],
                'indexType' => strtolower($index['INDEX_METHOD']),
            ];
        }

        return $indexes;
    }

    /**
     * @inheritdoc
     */
    public function readConstraints($tableName, $resource)
    {
        $constraints = [];
        foreach ($this->tableInspector->getIndexes($tableName, $resource) as $name => $index) {
            $type = strtolower($index['INDEX_TYPE']);
            if ($type !== Constraint::PRIMARY_TYPE && $type !== Constraint::UNIQUE_TYPE) {
                continue;
            }
            $constraints[$name] = [
                'name' => $name,
                'type' => $type,
                'column' => $index['COLUMNS_LIST'],
            ];
        }

        return $constraints;
    }

    /**
     * @inheritdoc
     */
    public function readReferences($tableName, $resource)
    {
        $references = [];
        foreach ($this->tableInspector->getForeignKeys($tableName, $resource) as $name => $foreignKey) {
            $references[$name] = [
                'name' => $name,
                'type' => Reference::TYPE,
                'column' => $foreignKey['COLUMN_NAME'],
                'referenceTable' => $foreignKey['REF_TABLE_NAME'],
                'referenceColumn' => $foreignKey['REF_COLUMN_NAME'],
                'onDelete' => $foreignKey['ON_DELETE'],
            ];
        }

        return $references;
    }

    /**
     * Get SQL that resolves a table name of the current schema to its OID
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $tableName
     * @return string
     */
    private function getRegclassSql($connection, string $tableName): string
    {
        return sprintf('to_regclass(%s)', $connection->quote($connection->quoteIdentifier($tableName)));
    }
}
