<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema\Db;

use Magento\Framework\Setup\Declaration\Schema\Db\DbDefinitionProcessorInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Column;
use Magento\Framework\Setup\Declaration\Schema\Dto\Constraint;
use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;
use Magento\Framework\Setup\Declaration\Schema\Dto\Index;

/**
 * Definition processor of every column, index and constraint type
 *
 * Replaces the MySQL processors in \Magento\Framework\Setup\Declaration\Schema\Db\DefinitionAggregator.
 * DbSchemaReader returns elements in their final form, so there is nothing to convert from a definition.
 */
class DefinitionProcessor implements DbDefinitionProcessorInterface
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
     * Get the definition of an element
     *
     * @param ElementInterface $element
     * @return ElementDefinition
     */
    public function toDefinition(ElementInterface $element)
    {
        if ($element instanceof Column) {
            $sql = $this->sqlBuilder->getColumnSql($element);
        } elseif ($element instanceof Index) {
            $sql = $this->sqlBuilder->getIndexSql($element);
        } elseif ($element instanceof Constraint) {
            $sql = $this->sqlBuilder->getConstraintSql($element);
        } else {
            throw new \InvalidArgumentException(sprintf('Cannot process element of type %s', $element->getType()));
        }
        return new ElementDefinition($element, $sql);
    }

    /**
     * @inheritdoc
     */
    public function fromDefinition(array $data)
    {
        return $data;
    }
}
