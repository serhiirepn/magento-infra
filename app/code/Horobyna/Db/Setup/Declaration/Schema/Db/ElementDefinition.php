<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema\Db;

use Magento\Framework\Setup\Declaration\Schema\Dto\ElementInterface;

/**
 * Definition of a schema element, as passed from DefinitionProcessor to DbSchemaWriter
 *
 * The MySQL writer gets SQL fragments it can join into one ALTER TABLE statement. PostgreSQL needs separate
 * statements for comments, indexes and "on update" triggers, and a column is changed property by property, so
 * the writer gets the element itself. The string value is the SQL fragment of the element.
 */
class ElementDefinition
{
    /**
     * @var ElementInterface
     */
    private $element;

    /**
     * @var string
     */
    private $sql;

    /**
     * @param ElementInterface $element
     * @param string $sql
     */
    public function __construct(ElementInterface $element, string $sql)
    {
        $this->element = $element;
        $this->sql = $sql;
    }

    /**
     * Get the element
     *
     * @return ElementInterface
     */
    public function getElement(): ElementInterface
    {
        return $this->element;
    }

    /**
     * Get the SQL fragment: a column or constraint definition, or a CREATE INDEX statement
     *
     * @return string
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * @inheritdoc
     */
    public function __toString(): string
    {
        return $this->sql;
    }
}
