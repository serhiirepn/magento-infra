<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Adapter;

/**
 * Reports a fixed MySQL 8.0.29+ engine without querying the database
 *
 * The core provider reads the version with the MySQL-only "SHOW variables LIKE 'version'". Its callers only use
 * it to choose MySQL/MariaDB charset and collation defaults for the declarative schema, which do not apply to
 * PostgreSQL, so the most recent MySQL profile (utf8mb4, not MariaDB) is reported.
 */
class SqlVersionProvider extends \Magento\Framework\DB\Adapter\SqlVersionProvider
{
    /**
     * @inheritDoc
     */
    public function getSqlVersion(string $resource = \Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION): string
    {
        return self::MYSQL_8_0_VERSION;
    }

    /**
     * @inheritDoc
     */
    public function isMysqlGte8029(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getMariaDbSuffixKey(): string
    {
        return self::MARIA_DB_10_6_11_VERSION;
    }

    /**
     * @inheritDoc
     */
    public function isMariaDbEngine(): bool
    {
        return false;
    }
}
