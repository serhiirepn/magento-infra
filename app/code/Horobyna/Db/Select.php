<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select\SelectRenderer;

/**
 * Select that accepts any Zend-based database adapter
 *
 * \Magento\Framework\DB\Select type-hints its adapter as \Magento\Framework\DB\Adapter\Pdo\Mysql, which would force
 * the PostgreSQL adapter to extend the MySQL one. This subclass only relaxes the constructor: PHP does not check
 * constructor signatures against the parent, so instances still satisfy every \Magento\Framework\DB\Select type-hint.
 */
class Select extends \Magento\Framework\DB\Select
{
    /**
     * @param AdapterInterface&\Zend_Db_Adapter_Abstract $adapter
     * @param SelectRenderer $selectRenderer
     * @param array $parts
     */
    public function __construct(
        AdapterInterface&\Zend_Db_Adapter_Abstract $adapter,
        SelectRenderer $selectRenderer,
        $parts = []
    ) {
        // Same as the parent constructor, which cannot be called with a non-MySQL adapter
        self::$_partsInit = array_merge(self::$_partsInit, $parts);
        if (!isset(self::$_partsInit[self::STRAIGHT_JOIN])) {
            self::$_partsInit = [self::STRAIGHT_JOIN => false] + self::$_partsInit;
        }

        // The renderer property is private to the parent, which uses it in assemble() and __wakeup()
        \Closure::bind(
            function (SelectRenderer $selectRenderer) {
                $this->selectRenderer = $selectRenderer;
            },
            $this,
            \Magento\Framework\DB\Select::class
        )($selectRenderer);

        \Zend_Db_Select::__construct($adapter);
    }
}
