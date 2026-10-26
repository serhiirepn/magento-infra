<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema\Db;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Dto\Column;
use Magento\Framework\Setup\Declaration\Schema\Dto\Columns\ColumnIdentityAwareInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Columns\ColumnNullableAwareInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Columns\ColumnUnsignedAwareInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Columns\Timestamp;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraint;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Internal;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Reference;
use Magento\Framework\Setup\Declaration\Schema\Dto\Index;
use Magento\Framework\Setup\Declaration\Schema\TableNameResolver;
use Horobyna\Db\Adapter\Pdo\Pgsql;

/**
 * Maps declarative schema elements to PostgreSQL types and SQL
 *
 * The types follow the adapter (see Pgsql::getColumnSpec()) and pgloader: MySQL unsigned integers are widened
 * to the next signed type, booleans are smallint, all text types are text and all binary types are bytea.
 * Column types are canonical SQL type names, e.g. "integer", "numeric(12,4)", "varchar(255)".
 */
class SqlBuilder
{
    /**
     * Integer types from the narrowest to the widest
     */
    private const INTEGER_TYPES = ['smallint', 'integer', 'bigint'];

    /**
     * Defaults that stand for the MySQL zero date, which PostgreSQL cannot store
     */
    private const ZERO_DATES = ['0', '0000-00-00 00:00:00', '0000-00-00'];

    /**
     * Index name prefixes by index type, see Pgsql::getIndexName()
     */
    private const INDEX_NAME_PREFIXES = [
        Pgsql::INDEX_TYPE_INDEX => 'IDX_',
        Pgsql::INDEX_TYPE_UNIQUE => 'UNQ_',
        Pgsql::INDEX_TYPE_FULLTEXT => 'FTI_',
    ];

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
     * Get the PostgreSQL type of a column
     *
     * @param Column $column
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getColumnType(Column $column): string
    {
        $unsigned = $column instanceof ColumnUnsignedAwareInterface && $column->isUnsigned();
        switch ($column->getType()) {
            case 'boolean':
            case 'tinyint':
                return 'smallint';
            case 'smallint':
                return $unsigned ? 'integer' : 'smallint';
            case 'int':
                return $unsigned ? 'bigint' : 'integer';
            case 'bigint':
                return 'bigint';
            case 'decimal':
                return sprintf('numeric(%d,%d)', $column->getPrecision(), $column->getScale());
            case 'float':
            case 'double':
                return 'double precision';
            case 'varchar':
            case 'char':
                return sprintf('%s(%d)', $column->getType(), $column->getLength());
            case 'binary':
            case 'varbinary':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
                return 'bytea';
            case 'text':
            case 'mediumtext':
            case 'longtext':
                return 'text';
            case 'json':
                return 'json';
            case 'date':
                return 'date';
            case 'datetime':
            case 'timestamp':
                return 'timestamp';
        }
        throw new \InvalidArgumentException(sprintf('Column type %s is not supported', $column->getType()));
    }

    /**
     * Get the PostgreSQL type of a column described by Pgsql::describeTable()
     *
     * timestamp with time zone (created by pgloader) is reported as timestamp, which stores the same values.
     *
     * @param array $columnData
     * @return string
     */
    public function getDescribedType(array $columnData): string
    {
        switch ($columnData['DATA_TYPE']) {
            case 'smallint':
            case 'bigint':
                return $columnData['DATA_TYPE'];
            case 'int':
                return 'integer';
            case 'bool':
                return 'boolean';
            case 'decimal':
                return $columnData['PRECISION'] !== null
                    ? sprintf('numeric(%d,%d)', $columnData['PRECISION'], $columnData['SCALE'])
                    : 'numeric';
            case 'float':
                return 'double precision';
            case 'varchar':
                return $columnData['LENGTH'] !== null ? sprintf('varchar(%d)', $columnData['LENGTH']) : 'text';
            case 'char':
                return sprintf('char(%d)', $columnData['LENGTH'] ?? 1);
            case 'blob':
                return 'bytea';
            case 'json':
            case 'jsonb':
                return 'json';
        }
        return $columnData['DATA_TYPE'];
    }

    /**
     * Check whether a column of the actual type can be kept for the wanted type
     *
     * An integer column may be wider than needed, e.g. pgloader widens unsigned tinyint to integer.
     *
     * @param string $actualType
     * @param string $wantedType
     * @return bool
     */
    public function isTypeCompatible(string $actualType, string $wantedType): bool
    {
        $actualRank = array_search($actualType, self::INTEGER_TYPES, true);
        $wantedRank = array_search($wantedType, self::INTEGER_TYPES, true);
        if ($actualRank !== false && $wantedRank !== false) {
            return $actualRank >= $wantedRank;
        }
        return $actualType === $wantedType;
    }

    /**
     * Get the properties of a column that matter to PostgreSQL
     *
     * Returns type, nullable, default (normalized raw value, see normalizeDefault()), identity, comment and
     * onUpdate. MySQL-only properties (padding, unsigned, charset, collation) are left out.
     *
     * A NOT NULL date column defaulting to the MySQL zero date is nullable: NULL stands for the zero date, as
     * pgloader migrates it, and rows inserted without a value get it like in MySQL.
     *
     * @param Column $column
     * @return array
     */
    public function getColumnState(Column $column): array
    {
        $type = $this->getColumnType($column);
        $identity = $column instanceof ColumnIdentityAwareInterface && $column->isIdentity();
        $default = $this->getDefault($column);
        $isZeroDate = ($type === 'timestamp' || $type === 'date')
            && in_array((string)$default, self::ZERO_DATES, true);
        return [
            'type' => $type,
            'nullable' => !$identity && $column instanceof ColumnNullableAwareInterface
                && ($column->isNullable() || $isZeroDate),
            'default' => $identity ? null : $this->normalizeDefault($default, $type),
            'identity' => $identity,
            'comment' => $this->normalizeComment($column->getComment()),
            'onUpdate' => $column instanceof Timestamp && (bool)$column->getOnUpdate(),
        ];
    }

    /**
     * Normalize a default value, so that declared and stored defaults can be compared
     *
     * Numbers lose insignificant zeros, booleans become 1 / 0, the current time is CURRENT_TIMESTAMP and the
     * MySQL zero date is no default. So is the string NULL, which declarations use for "no default"
     * (e.g. default="NULL" of a varchar column).
     *
     * @param mixed $value
     * @param string $type
     * @return string|null
     */
    public function normalizeDefault($value, string $type): ?string
    {
        if ($value === null || $value === 'NULL') {
            return null;
        }
        if (is_bool($value)) {
            $value = (int)$value;
        }
        $value = (string)$value;
        if (preg_match('/^(smallint|integer|bigint|numeric|double precision|boolean)\b/', $type)) {
            if (strcasecmp($value, 'true') === 0 || strcasecmp($value, 'false') === 0) {
                return strcasecmp($value, 'true') === 0 ? '1' : '0';
            }
            return is_numeric($value) ? (string)(0 + $value) : $value;
        }
        if ($type === 'timestamp' || $type === 'date') {
            if (in_array($value, self::ZERO_DATES, true)) {
                return null;
            }
            if (preg_match('/^(CURRENT_TIMESTAMP|LOCALTIMESTAMP|now\(\))$/i', $value)) {
                return 'CURRENT_TIMESTAMP';
            }
        }
        return $value;
    }

    /**
     * Normalize a comment: an empty comment is no comment
     *
     * @param string|null $comment
     * @return string|null
     */
    public function normalizeComment($comment): ?string
    {
        return $comment === null || $comment === '' ? null : (string)$comment;
    }

    /**
     * Get the SQL default of a column, null if it has no default
     *
     * @param Column $column
     * @return string|null
     */
    public function getDefaultSql(Column $column): ?string
    {
        $state = $this->getColumnState($column);
        $default = $state['default'];
        if ($default === null) {
            return null;
        }
        if ($default === 'CURRENT_TIMESTAMP') {
            return $default;
        }
        $connection = $this->resourceConnection->getConnection($column->getTable()->getResource());
        if ($state['type'] === 'bytea') {
            return $connection->quote('\\x' . bin2hex($default)) . '::bytea';
        }
        return $connection->quote($default);
    }

    /**
     * Get the column definition used in CREATE TABLE and ADD COLUMN, e.g. "qty" numeric(12,4) NOT NULL DEFAULT '0'
     *
     * Comments and "on update" are not part of a PostgreSQL column definition.
     *
     * @param Column $column
     * @return string
     */
    public function getColumnSql(Column $column): string
    {
        $state = $this->getColumnState($column);
        $default = $this->getDefaultSql($column);
        return sprintf(
            '%s %s%s%s%s',
            $this->resourceConnection->getConnection()->quoteIdentifier($column->getName()),
            $state['type'],
            $state['identity'] ? ' GENERATED BY DEFAULT AS IDENTITY' : '',
            $state['nullable'] ? ' NULL' : ' NOT NULL',
            $default !== null ? ' DEFAULT ' . $default : ''
        );
    }

    /**
     * Get the constraint definition used in CREATE TABLE and ADD
     *
     * The primary key is not named: constraint names are unique per schema, and Magento names every primary key
     * PRIMARY.
     *
     * @param Constraint $constraint
     * @return string
     */
    public function getConstraintSql(Constraint $constraint): string
    {
        $connection = $this->resourceConnection->getConnection($constraint->getTable()->getResource());
        if ($constraint instanceof Reference) {
            return sprintf(
                'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)%s',
                $connection->quoteIdentifier($constraint->getName()),
                $connection->quoteIdentifier($constraint->getColumn()->getName()),
                $connection->quoteIdentifier(
                    $this->resourceConnection->getTableName($constraint->getReferenceTable()->getName())
                ),
                $connection->quoteIdentifier($constraint->getReferenceColumn()->getName()),
                $constraint->getOnDelete() ? ' ON DELETE ' . strtoupper($constraint->getOnDelete()) : ''
            );
        }
        /** @var Internal $constraint */
        $columns = implode(', ', array_map([$connection, 'quoteIdentifier'], $constraint->getColumnNames()));
        if ($constraint->getType() === Internal::PRIMARY_TYPE) {
            return sprintf('PRIMARY KEY (%s)', $columns);
        }
        return sprintf(
            'CONSTRAINT %s UNIQUE (%s)',
            $connection->quoteIdentifier($this->getStoredIndexName($constraint, Pgsql::INDEX_TYPE_UNIQUE)),
            $columns
        );
    }

    /**
     * Get the CREATE INDEX statement of an index
     *
     * @param Index $index
     * @return string
     */
    public function getIndexSql(Index $index): string
    {
        /** @var Pgsql $connection */
        $connection = $this->resourceConnection->getConnection($index->getTable()->getResource());
        $type = $this->isFullText($index) ? Pgsql::INDEX_TYPE_FULLTEXT : Pgsql::INDEX_TYPE_INDEX;
        return $connection->getAddIndexSql(
            $index->getTable()->getName(),
            $this->getStoredIndexName($index, $type),
            $index->getColumnNames(),
            $type,
            null
        );
    }

    /**
     * Get the name to create an index or unique key with
     *
     * Unlike MySQL, PostgreSQL index names are unique per schema, not per table. Declared names are derived from
     * the origin table, which a replica or temporary table shares (e.g. catalogrule_product_replica), so the
     * stored name is derived from the table itself. Magento swaps tables with their replicas by renaming them,
     * so the name may still belong to another table: then a unique one is made up. Stored names do not matter
     * otherwise, see TableInspector. Other names, e.g. the temporary index of AddColumn, are kept.
     *
     * @param Index|Internal $element
     * @param string $type
     * @return string
     */
    private function getStoredIndexName($element, string $type): string
    {
        $tableName = $element->getTable()->getName();
        $columns = $element->getColumnNames();
        $declaredName = $this->resourceConnection->getIdxName(
            $this->tableNameResolver->getNameOfOriginTable($tableName),
            $columns,
            $type
        );
        if ($element->getName() !== $declaredName) {
            return $element->getName();
        }

        /** @var Pgsql $connection */
        $connection = $this->resourceConnection->getConnection($element->getTable()->getResource());
        $name = $connection->getIndexName($tableName, $columns, $type);
        for ($attempt = 1; $this->isIndexNameTaken($connection, $name, $tableName); $attempt++) {
            // md5() here is not for cryptographic use.
            // phpcs:ignore Magento2.Security.InsecureFunction
            $hash = md5($tableName . '_' . implode('_', $columns) . '_' . $attempt);
            $name = strtoupper(substr(self::INDEX_NAME_PREFIXES[$type] . $hash, 0, Pgsql::LENGTH_INDEX_NAME));
        }
        return $name;
    }

    /**
     * Check whether an index name is used by another table
     *
     * @param Pgsql $connection
     * @param string $name
     * @param string $tableName
     * @return bool
     */
    private function isIndexNameTaken(Pgsql $connection, string $name, string $tableName): bool
    {
        $isOwnIndex = $connection->fetchOne(
            'SELECT (indrelid = to_regclass(?))::int FROM pg_index WHERE indexrelid = to_regclass(?)',
            [$connection->quoteIdentifier($tableName), $connection->quoteIdentifier($name)]
        );
        return $isOwnIndex === '0' || $isOwnIndex === 0;
    }

    /**
     * Check whether an index is a full-text index; other index types (btree, hash) are all created as btree
     *
     * @param Index $index
     * @return bool
     */
    public function isFullText(Index $index): bool
    {
        return $index->getIndexType() === Index::FULLTEXT_INDEX;
    }

    /**
     * Get the declared default of a column
     *
     * @param Column $column
     * @return mixed
     */
    private function getDefault(Column $column)
    {
        return method_exists($column, 'getDefault') ? $column->getDefault() : null;
    }
}
