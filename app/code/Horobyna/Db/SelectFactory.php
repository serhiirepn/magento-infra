<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Creates \Horobyna\Db\Select instances
 *
 * \Magento\Framework\DB\SelectFactory instantiates \Magento\Framework\DB\Select with "new", bypassing DI
 * preferences, and that class only accepts the MySQL adapter.
 */
class SelectFactory extends \Magento\Framework\DB\SelectFactory
{
    /**
     * Create a factory with the renderer and parts of another one, e.g. a core factory passed to the adapter
     *
     * @param \Magento\Framework\DB\SelectFactory $selectFactory
     * @return SelectFactory
     */
    public static function fromFactory(\Magento\Framework\DB\SelectFactory $selectFactory)
    {
        return $selectFactory instanceof self
            ? $selectFactory
            : new self($selectFactory->selectRenderer, $selectFactory->parts);
    }

    /**
     * @param AdapterInterface $adapter
     * @return Select
     */
    public function create(AdapterInterface $adapter)
    {
        return new Select($adapter, $this->selectRenderer, $this->parts);
    }
}
