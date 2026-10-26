<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema\Db;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Internal;
use Magento\Framework\Setup\Declaration\Schema\TableNameResolver;
use Horobyna\Db\Adapter\Pdo\Pgsql;

/**
 * Lists the indexes and foreign keys of a table under the names the declarative schema gives them
 *
 * The declarative schema does not store element names: it derives them from the table and columns (see
 * \Magento\Framework\Setup\Declaration\Schema\Declaration\TableElement\ElementNameResolver). The names stored in
 * PostgreSQL may differ, e.g. pgloader prefixes indexes ("idx_43480_store_code") and PostgreSQL truncates
 * names to 63 characters. So stored elements are named the same way as declared ones; the stored name is still
 * available (KEY_NAME, FK_NAME) to drop the element.
 */
class TableInspector
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var TableNameResolver
     */
    private $tableNameResolver;

    /**
     * @param ResourceConnection $resourceConnection
     * @param TableNameResolver $tableNameResolver
     */
    public function __construct(ResourceConnection $resourceConnection, TableNameResolver $tableNameResolver)
    {
        $this->resourceConnection = $resourceConnection;
        $this->tableNameResolver = $tableNameResolver;
    }

    /**
     * Index types that are named alike
     */
    private const INDEX_TYPES = [
        AdapterInterface::INDEX_TYPE_INDEX,
        AdapterInterface::INDEX_TYPE_UNIQUE,
        AdapterInterface::INDEX_TYPE_FULLTEXT,
    ];

    /**
     * Get the indexes, unique and primary keys of a table by their declarative names
     *
     * An index is named after its type, unless its stored name (without the prefix of pgloader) is the name of
     * another type: pgloader e.g. turns a MySQL full-text index FTI_... into a btree one, which has to be found
     * by that name to be replaced.
     *
     * Partial and expression indexes cannot be declared and are left out. When several indexes would get
     * the same name, the others keep their stored name.
     *
     * @param string $tableName
     * @param string $resource
     * @return array name => Pgsql::getIndexList() item
     */
    public function getIndexes(string $tableName, string $resource): array
    {
        /** @var Pgsql $connection */
        $connection = $this->resourceConnection->getConnection($resource);
        $indexes = [];
        foreach ($connection->getIndexList($tableName) as $index) {
            if ($index['IS_PARTIAL'] || empty($index['COLUMNS_LIST'])) {
                continue;
            }
            $type = strtolower($index['INDEX_TYPE']);
            if ($type === AdapterInterface::INDEX_TYPE_PRIMARY) {
                $name = Internal::PRIMARY_NAME;
            } else {
                $storedName = preg_replace('/^IDX_\d+_/', '', strtoupper($index['KEY_NAME']));
                $names = [];
                foreach (self::INDEX_TYPES as $indexType) {
                    $names[$indexType] = $this->getIndexName($tableName, $index['COLUMNS_LIST'], $indexType);
                }
                $name = in_array($storedName, $names, true) ? $storedName : $names[$type];
            }
            if (isset($indexes[$name])) {
                $name = strtoupper($index['KEY_NAME']);
            }
            $indexes[$name] = $index;
        }

        return $indexes;
    }

    /**
     * Get the foreign keys of a table by their declarative names
     *
     * @param string $tableName
     * @param string $resource
     * @return array name => Pgsql::getForeignKeys() item
     */
    public function getForeignKeys(string $tableName, string $resource): array
    {
        $connection = $this->resourceConnection->getConnection($resource);
        $foreignKeys = [];
        foreach ($connection->getForeignKeys($tableName) as $foreignKey) {
            $name = $this->resourceConnection->getFkName(
                $this->tableNameResolver->getNameOfOriginTable($tableName),
                $foreignKey['COLUMN_NAME'],
                $foreignKey['REF_TABLE_NAME'],
                $foreignKey['REF_COLUMN_NAME']
            );
            if (isset($foreignKeys[$name])) {
                $name = strtoupper($foreignKey['FK_NAME']);
            }
            $foreignKeys[$name] = $foreignKey;
        }

        return $foreignKeys;
    }

    /**
     * Get the declarative name of an index or unique key
     *
     * @param string $tableName
     * @param string[] $columns
     * @param string $type index, fulltext or unique
     * @return string
     */
    private function getIndexName(string $tableName, array $columns, string $type): string
    {
        return $this->resourceConnection->getIdxName(
            $this->tableNameResolver->getNameOfOriginTable($tableName),
            $columns,
            $type
        );
    }
}
