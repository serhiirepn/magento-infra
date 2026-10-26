<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Declaration\Schema\Db;

/**
 * Foreign key statement made of complete SQL statements
 *
 * Extends the core class, which StatementAggregator uses to keep dropping and adding a foreign key apart.
 */
class ReferenceStatement extends \Magento\Framework\Setup\Declaration\Schema\Db\ReferenceStatement
{
    use QueriesTrait;
}
