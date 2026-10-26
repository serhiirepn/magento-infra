<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema;

use Magento\Framework\Setup\Declaration\Schema\Dto\Column;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Internal;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraints\Reference;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Index;
use Magento\Framework\Setup\Declaration\Schema\Dto\Table;
use Horobyna\Db\Setup\Declaration\Schema\Db\SqlBuilder;

/**
 * Compares declared elements with the ones read from PostgreSQL
 *
 * The core comparator compares all diff sensitive params, many of which PostgreSQL cannot store (unsigned,
 * padding, charset, collation, engine, index method) and would be reported as changed on every run. Elements
 * are compared by what they become in PostgreSQL instead (see SqlBuilder::getColumnState()), so e.g. a declared
 * "int unsigned" equals a stored bigint and a declared "boolean" equals a stored smallint.
 *
 * DiffManager::shouldBeModified() passes the declared element first and the stored one second.
 */
class Comparator extends \Magento\Framework\Setup\Declaration\Schema\Comparator
{
    /**
     * @var SqlBuilder
     */
    private $sqlBuilder;

    /**
     * @param SqlBuilder $sqlBuilder
     */
    public function __construct(SqlBuilder $sqlBuilder)
    {
        $this->sqlBuilder = $sqlBuilder;
    }

    /**
     * @inheritdoc
     */
    public function compare(ElementInterface $first, ElementInterface $second)
    {
        if ($first instanceof Column && $second instanceof Column) {
            return $this->compareColumns($first, $second);
        }
        if ($first instanceof Table && $second instanceof Table) {
            return $first->getResource() === $second->getResource()
                && $this->sqlBuilder->normalizeComment($first->getComment())
                    === $this->sqlBuilder->normalizeComment($second->getComment());
        }
        if ($first instanceof Index && $second instanceof Index) {
            return $first->getColumnNames() === $second->getColumnNames()
                && $this->sqlBuilder->isFullText($first) === $this->sqlBuilder->isFullText($second);
        }
        if ($first instanceof Internal && $second instanceof Internal) {
            return $first->getType() === $second->getType()
                && $first->getColumnNames() === $second->getColumnNames();
        }
        if ($first instanceof Reference && $second instanceof Reference) {
            return $this->getReferenceParams($first) === $this->getReferenceParams($second);
        }

        return parent::compare($first, $second);
    }

    /**
     * Compare a declared column with a stored one
     *
     * The stored column may have a wider integer type than declared.
     *
     * @param Column $declared
     * @param Column $stored
     * @return bool
     */
    private function compareColumns(Column $declared, Column $stored): bool
    {
        $wanted = $this->sqlBuilder->getColumnState($declared);
        $actual = $this->sqlBuilder->getColumnState($stored);
        if (!$this->sqlBuilder->isTypeCompatible($actual['type'], $wanted['type'])) {
            return false;
        }
        unset($wanted['type'], $actual['type']);
        return $wanted === $actual;
    }

    /**
     * Get the foreign key params that PostgreSQL stores
     *
     * @param Reference $reference
     * @return array
     */
    private function getReferenceParams(Reference $reference): array
    {
        $params = $reference->getDiffSensitiveParams();
        $params['onDelete'] = strtoupper((string)$params['onDelete']);
        return $params;
    }
}
