<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema\Db;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Declaration\Schema\Db\DbSchemaWriterInterface;
use Magento\Framework\Setup\Declaration\Schema\Db\StatementAggregator;
use Magento\Framework\Setup\Declaration\Schema\Db\StatementFactory;
use Magento\Framework\Setup\Declaration\Schema\DryRunLogger;
use Magento\Framework\Setup\Declaration\Schema\Dto\Column;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraint;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Reference;
use Magento\Framework\Setup\Declaration\Schema\Dto\Index;
use Horobyna\Db\Adapter\Pdo\Pgsql;

/**
 * Writes declarative schema changes to a PostgreSQL database
 *
 * Counterpart of \Magento\Framework\Setup\Declaration\Schema\Db\MySQL\DbSchemaWriter. Element definitions are
 * ElementDefinition objects made by DefinitionProcessor. Every statement holds complete SQL statements (see
 * QueriesTrait): comments, indexes and "on update" triggers cannot be part of CREATE / ALTER TABLE in PostgreSQL.
 * The statements of a table are run in one transaction, PostgreSQL DDL being transactional.
 *
 * Like MySQL with FOREIGN_KEY_CHECKS=0 during setup, a foreign key added to an existing table does not check the
 * stored rows (NOT VALID), and a table is dropped with the foreign keys that reference it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DbSchemaWriter implements DbSchemaWriterInterface
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var StatementFactory
     */
    private $statementFactory;

    /**
     * @var DryRunLogger
     */
    private $dryRunLogger;

    /**
     * @var SqlBuilder
     */
    private $sqlBuilder;

    /**
     * @var TableInspector
     */
    private $tableInspector;

    /**
     * @param ResourceConnection $resourceConnection
     * @param StatementFactory $statementFactory creates Statement and ReferenceStatement of this namespace
     * @param DryRunLogger $dryRunLogger
     * @param SqlBuilder $sqlBuilder
     * @param TableInspector $tableInspector
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        StatementFactory $statementFactory,
        DryRunLogger $dryRunLogger,
        SqlBuilder $sqlBuilder,
        TableInspector $tableInspector
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->statementFactory = $statementFactory;
        $this->dryRunLogger = $dryRunLogger;
        $this->sqlBuilder = $sqlBuilder;
        $this->tableInspector = $tableInspector;
    }

    /**
     * @inheritdoc
     *
     * Constraints are part of CREATE TABLE; indexes, comments and "on update" triggers follow it.
     */
    public function createTable($tableName, $resource, array $definition, array $options)
    {
        $connection = $this->getConnection($resource);
        $fragments = [];
        $queries = [];
        foreach ($definition as $elementDefinition) {
            $element = $this->getElement($elementDefinition);
            if ($element instanceof Index) {
                $queries[] = $elementDefinition->getSql();
                continue;
            }
            $fragments[] = '  ' . $elementDefinition->getSql();
            if ($element instanceof Column) {
                array_push($queries, ...$this->getColumnPropertyQueries($element, $tableName));
            }
        }
        array_unshift(
            $queries,
            sprintf("CREATE TABLE %s (\n%s\n)", $connection->quoteIdentifier($tableName), implode(",\n", $fragments))
        );
        if ($this->sqlBuilder->normalizeComment($options['comment'] ?? null) !== null) {
            $queries[] = $connection->getTableCommentSql($tableName, $options['comment']);
        }

        return $this->createStatement($tableName, $tableName, self::CREATE_TYPE, $queries, $resource);
    }

    /**
     * @inheritdoc
     */
    public function dropTable($tableName, $resource)
    {
        $queries = [sprintf('DROP TABLE %s CASCADE', $this->getConnection($resource)->quoteIdentifier($tableName))];
        return $this->createStatement($tableName, $tableName, self::DROP_TYPE, $queries, $resource);
    }

    /**
     * @inheritdoc
     *
     * @param string $elementName
     * @param string $resource
     * @param string $tableName
     * @param ElementDefinition $elementDefinition
     * @param string $elementType
     * @return Statement|ReferenceStatement
     */
    public function addElement($elementName, $resource, $tableName, $elementDefinition, $elementType)
    {
        $element = $this->getElement($elementDefinition);
        $table = $this->getConnection($resource)->quoteIdentifier($tableName);
        if ($element instanceof Column) {
            $queries = $this->getAddColumnQueries($element, $elementDefinition->getSql(), $tableName);
        } elseif ($element instanceof Index) {
            $queries = [$elementDefinition->getSql()];
        } elseif ($element instanceof Reference) {
            $queries = [sprintf('ALTER TABLE %s ADD %s NOT VALID', $table, $elementDefinition->getSql())];
        } else {
            $queries = [sprintf('ALTER TABLE %s ADD %s', $table, $elementDefinition->getSql())];
        }

        return $this->createStatement($elementName, $tableName, self::ALTER_TYPE, $queries, $resource, $elementType);
    }

    /**
     * @inheritdoc
     *
     * Only the comment has a PostgreSQL counterpart.
     */
    public function modifyTableOption($tableName, $resource, $optionName, $optionValue)
    {
        $queries = [];
        if ($optionName === 'comment') {
            $queries[] = $this->getConnection($resource)->getTableCommentSql($tableName, (string)$optionValue);
        }
        return $this->createStatement($tableName, $tableName, self::ALTER_TYPE, $queries, $resource);
    }

    /**
     * @inheritdoc
     *
     * Only the properties that differ from the stored column are changed, so that e.g. a comment change does not
     * rewrite the table. A stored type is kept when it can hold the declared one (see SqlBuilder::isTypeCompatible()).
     *
     * @param string $columnName
     * @param string $resource
     * @param string $tableName
     * @param ElementDefinition $columnDefinition
     * @return Statement
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function modifyColumn($columnName, $resource, $tableName, $columnDefinition)
    {
        /** @var Column $column */
        $column = $this->getElement($columnDefinition);
        $connection = $this->getConnection($resource);
        $current = $connection->describeTable($tableName)[$columnName] ?? null;
        if ($current === null) {
            throw new \InvalidArgumentException(
                sprintf('Column "%s" does not exist in table "%s".', $columnName, $tableName)
            );
        }
        $currentType = $this->sqlBuilder->getDescribedType($current);
        $currentDefault = $this->sqlBuilder->normalizeDefault($current['DEFAULT'], $currentType);
        $wanted = $this->sqlBuilder->getColumnState($column);

        $alter = sprintf(
            'ALTER TABLE %s ALTER COLUMN %s ',
            $connection->quoteIdentifier($tableName),
            $connection->quoteIdentifier($columnName)
        );
        $queries = [];
        if ($current['IDENTITY'] && !$wanted['identity']) {
            // Either a SQL standard identity column or a serial one, whose default is nextval()
            $queries[] = $alter . ($connection->isIdentityColumn($tableName, $columnName)
                ? 'DROP IDENTITY IF EXISTS'
                : 'DROP DEFAULT');
        }
        if (!$this->sqlBuilder->isTypeCompatible($currentType, $wanted['type'])) {
            if (!$current['IDENTITY'] && $currentDefault !== null) {
                // The old default may not be valid for the new type
                $queries[] = $alter . 'DROP DEFAULT';
                $currentDefault = null;
            }
            $queries[] = $alter . sprintf(
                'TYPE %s USING %s',
                $wanted['type'],
                $this->getCastSql($connection->quoteIdentifier($columnName), $currentType, $wanted['type'])
            );
        }
        if (!$wanted['identity'] && $wanted['default'] !== $currentDefault) {
            $defaultSql = $this->sqlBuilder->getDefaultSql($column);
            $queries[] = $alter . ($defaultSql !== null ? 'SET DEFAULT ' . $defaultSql : 'DROP DEFAULT');
        }
        if (!$wanted['nullable'] && $current['NULLABLE']) {
            // MySQL stores the default, or the zero value of the type, in place of NULL
            $queries[] = sprintf(
                'UPDATE %s SET %s = %s WHERE %2$s IS NULL',
                $connection->quoteIdentifier($tableName),
                $connection->quoteIdentifier($columnName),
                $this->sqlBuilder->getDefaultSql($column) ?? $this->getZeroValueSql($connection, $wanted['type'])
            );
            $queries[] = $alter . 'SET NOT NULL';
        } elseif ($wanted['nullable'] && !$current['NULLABLE']) {
            $queries[] = $alter . 'DROP NOT NULL';
        }
        if ($wanted['identity'] && !$current['IDENTITY']) {
            $queries[] = $alter . 'ADD GENERATED BY DEFAULT AS IDENTITY';
            $queries[] = $connection->getSyncSequenceSql($tableName, $columnName);
        }
        if ($wanted['comment'] !== $this->getColumnComment($connection, $tableName, $columnName)) {
            $queries[] = $connection->getColumnCommentSql($tableName, $columnName, (string)$wanted['comment']);
        }
        $onUpdate = $connection->getOnUpdateTimestampColumns($tableName)[$columnName] ?? null;
        if ($wanted['onUpdate'] && $onUpdate === null) {
            array_push($queries, ...$connection->getOnUpdateTriggerStatements($tableName, $columnName));
        } elseif (!$wanted['onUpdate'] && $onUpdate !== null) {
            array_push($queries, ...$this->getDropOnUpdateQueries($connection, $tableName, $columnName, $onUpdate));
        }

        return $this->createStatement($columnName, $tableName, self::ALTER_TYPE, $queries, $resource);
    }

    /**
     * @inheritdoc
     *
     * Indexes and foreign keys are looked up by their declarative name (see TableInspector); an element that does
     * not exist yet, e.g. the temporary index of AddColumn, is dropped by name if it exists when the statement runs.
     */
    public function dropElement($resource, $elementName, $tableName, $type)
    {
        $connection = $this->getConnection($resource);
        $table = $connection->quoteIdentifier($tableName);
        $queries = [];
        if ($type === Column::TYPE) {
            $onUpdate = $connection->getOnUpdateTimestampColumns($tableName)[$elementName] ?? null;
            if ($onUpdate !== null) {
                $queries = $this->getDropOnUpdateQueries($connection, $tableName, $elementName, $onUpdate);
            }
            $queries[] = sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $connection->quoteIdentifier($elementName));
        } elseif ($type === Reference::TYPE) {
            $foreignKey = $this->tableInspector->getForeignKeys($tableName, $resource)[$elementName] ?? null;
            $queries[] = $foreignKey !== null
                ? sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $connection->quoteIdentifier($foreignKey['FK_NAME']))
                : sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', $table, $connection->quoteIdentifier($elementName));
        } else {
            $index = $this->tableInspector->getIndexes($tableName, $resource)[$elementName] ?? null;
            if ($index !== null) {
                $queries[] = $connection->getDropIndexSql($index, $tableName);
            } elseif ($type === Index::TYPE) {
                $queries[] = sprintf('DROP INDEX IF EXISTS %s', $connection->quoteIdentifier($elementName));
            } else {
                $queries[] = sprintf(
                    'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
                    $table,
                    $connection->quoteIdentifier($elementName)
                );
            }
        }

        return $this->createStatement($elementName, $tableName, self::ALTER_TYPE, $queries, $resource, $type);
    }

    /**
     * @inheritdoc
     *
     * A column added as identity is numbered by its sequence already, so only an existing identity column
     * needs it.
     */
    public function resetAutoIncrement($tableName, $resource)
    {
        $connection = $this->getConnection($resource);
        $connection->resetDdlCache($tableName);
        $identity = $connection->getAutoIncrementField($tableName);
        $queries = $identity ? [$connection->getSyncSequenceSql($tableName, $identity)] : [];

        return $this->createStatement(
            sprintf('RESET_AUTOINCREMENT_%s', $tableName),
            $tableName,
            self::ALTER_TYPE,
            $queries,
            $resource
        );
    }

    /**
     * @inheritdoc
     */
    public function compile(StatementAggregator $statementAggregator, $dryRun)
    {
        foreach ($statementAggregator->getStatementsBank() as $statementBank) {
            $queries = [];
            /** @var Statement|ReferenceStatement $statement */
            foreach ($statementBank as $statement) {
                array_push($queries, ...$statement->getQueries());
            }
            $connection = $this->getConnection($statement->getResource());

            if ($dryRun) {
                foreach ($queries as $query) {
                    $this->dryRunLogger->log($query . ';');
                }
                continue;
            }

            if ($queries) {
                $connection->runStatements($queries);
            }
            $connection->resetDdlCache($statement->getTableName());
            //Do post update, like SQL DML operations or etc...
            foreach ($statementBank as $bankStatement) {
                foreach ($bankStatement->getTriggers() as $trigger) {
                    call_user_func($trigger);
                }
            }
        }
    }

    /**
     * Get the statements that set the comment and "on update" of a new column
     *
     * @param Column $column
     * @param string $tableName
     * @return string[]
     */
    private function getColumnPropertyQueries(Column $column, string $tableName): array
    {
        $connection = $this->getConnection($column->getTable()->getResource());
        $state = $this->sqlBuilder->getColumnState($column);
        $queries = [];
        if ($state['comment'] !== null) {
            $queries[] = $connection->getColumnCommentSql($tableName, $column->getName(), $state['comment']);
        }
        if ($state['onUpdate']) {
            array_push($queries, ...$connection->getOnUpdateTriggerStatements($tableName, $column->getName()));
        }
        return $queries;
    }

    /**
     * Get the statements that add a column to an existing table
     *
     * Like MySQL, a NOT NULL column without default gets the zero value of its type in the stored rows.
     *
     * @param Column $column
     * @param string $columnSql
     * @param string $tableName
     * @return string[]
     */
    private function getAddColumnQueries(Column $column, string $columnSql, string $tableName): array
    {
        $connection = $this->getConnection($column->getTable()->getResource());
        $table = $connection->quoteIdentifier($tableName);
        $state = $this->sqlBuilder->getColumnState($column);
        $fillDefault = !$state['nullable'] && $state['default'] === null && !$state['identity'];
        if ($fillDefault) {
            $columnSql .= ' DEFAULT ' . $this->getZeroValueSql($connection, $state['type']);
        }
        $queries = [sprintf('ALTER TABLE %s ADD COLUMN %s', $table, $columnSql)];
        if ($fillDefault) {
            $queries[] = sprintf(
                'ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT',
                $table,
                $connection->quoteIdentifier($column->getName())
            );
        }

        return array_merge($queries, $this->getColumnPropertyQueries($column, $tableName));
    }

    /**
     * Get the statements that stop setting a column to the current timestamp on update
     *
     * A pgloader trigger may maintain several columns; it is replaced by adapter triggers for the others.
     *
     * @param Pgsql $connection
     * @param string $tableName
     * @param string $columnName
     * @param array $onUpdate Pgsql::getOnUpdateTimestampColumns() item
     * @return string[]
     */
    private function getDropOnUpdateQueries(Pgsql $connection, string $tableName, string $columnName, array $onUpdate)
    {
        if ($onUpdate['IS_ADAPTER']) {
            return [$connection->getDropOnUpdateTriggerSql($tableName, $columnName)];
        }
        $queries = [
            sprintf(
                'DROP TRIGGER %s ON %s',
                $connection->quoteIdentifier($onUpdate['TRIGGER_NAME']),
                $connection->quoteIdentifier($tableName)
            )
        ];
        foreach (array_diff($onUpdate['COLUMNS'], [$columnName]) as $otherColumn) {
            array_push($queries, ...$connection->getOnUpdateTriggerStatements($tableName, $otherColumn));
        }
        return $queries;
    }

    /**
     * Get the expression that converts a column to another type
     *
     * @param string $column quoted column name
     * @param string $fromType
     * @param string $toType
     * @return string
     */
    private function getCastSql(string $column, string $fromType, string $toType): string
    {
        $isText = fn ($type) => preg_match('/^(text|varchar|char)\b/', $type) === 1;
        if ($toType === 'bytea' && $isText($fromType)) {
            return sprintf("convert_to(%s, 'UTF8')", $column);
        }
        if ($fromType === 'bytea' && $isText($toType)) {
            return sprintf("convert_from(%s, 'UTF8')", $column);
        }
        return sprintf('%s::%s', $column, $toType);
    }

    /**
     * Get the value MySQL implicitly stores in a NOT NULL column without default
     *
     * @param Pgsql $connection
     * @param string $type
     * @return string
     */
    private function getZeroValueSql(Pgsql $connection, string $type): string
    {
        return $type === 'json' ? "'null'" : $connection->getZeroValueSql($type);
    }

    /**
     * Get the stored comment of a column
     *
     * @param Pgsql $connection
     * @param string $tableName
     * @param string $columnName
     * @return string|null
     */
    private function getColumnComment(Pgsql $connection, string $tableName, string $columnName): ?string
    {
        $comment = $connection->fetchOne(
            sprintf(
                'SELECT col_description(attrelid, attnum) FROM pg_attribute WHERE attrelid = to_regclass(%s)'
                . ' AND attname = ?',
                $connection->quote($connection->quoteIdentifier($tableName))
            ),
            [$columnName]
        );
        return $this->sqlBuilder->normalizeComment($comment ?: null);
    }

    /**
     * Get the element of a definition made by DefinitionProcessor
     *
     * @param mixed $definition
     * @return Column|Index|Constraint
     */
    private function getElement($definition)
    {
        if (!$definition instanceof ElementDefinition) {
            throw new \InvalidArgumentException(
                sprintf('Expected %s, got a MySQL definition: %s', ElementDefinition::class, $definition)
            );
        }
        return $definition->getElement();
    }

    /**
     * Create a statement made of complete SQL statements
     *
     * @param string $name
     * @param string $tableName
     * @param string $type
     * @param string[] $queries
     * @param string $resource
     * @param string|null $elementType
     * @return Statement|ReferenceStatement
     */
    private function createStatement(
        string $name,
        string $tableName,
        string $type,
        array $queries,
        string $resource,
        ?string $elementType = null
    ) {
        /** @var Statement|ReferenceStatement $statement */
        $statement = $this->statementFactory->create(
            $name,
            $tableName,
            $type,
            implode(";\n", $queries),
            $resource,
            $elementType
        );
        return $statement->setQueries($queries);
    }

    /**
     * Get the connection of a resource
     *
     * @param string $resource
     * @return Pgsql
     */
    private function getConnection(string $resource): Pgsql
    {
        return $this->resourceConnection->getConnection($resource);
    }
}
