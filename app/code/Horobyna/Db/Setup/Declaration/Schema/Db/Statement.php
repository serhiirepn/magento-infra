<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema\Db;

/**
 * Declarative schema statement made of complete SQL statements
 */
class Statement extends \Magento\Framework\Setup\Declaration\Schema\Db\Statement
{
    use QueriesTrait;
}
