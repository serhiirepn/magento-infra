<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Validator;

use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Setup\Exception;
use Magento\Setup\Module\ConnectionFactory;

/**
 * Checks the database connection of the setup application (setup:install, setup:upgrade...) with PostgreSQL
 *
 * The core validator connects to the MySQL "information_schema" database and reads MySQL privilege tables.
 * A PostgreSQL connection is to one database, so this one connects to the Magento database itself.
 *
 * The setup application is not built by the Magento object manager: this class replaces the core one through
 * the alias in setup/config/autoload/pgsql.global.php.
 */
class DbValidator extends \Magento\Setup\Validator\DbValidator
{
    /**
     * Oldest supported PostgreSQL version (server_version_num): triggers use EXECUTE FUNCTION
     */
    public const VERSION_REQUIRED = 110000;

    /**
     * @var ConnectionFactory
     */
    private $connectionFactory;

    /**
     * @param ConnectionFactory $connectionFactory
     */
    public function __construct(ConnectionFactory $connectionFactory)
    {
        parent::__construct($connectionFactory);
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * Check that the database is accessible, the server is supported and the user may change the schema
     *
     * @param string $dbName
     * @param string $dbHost
     * @param string $dbUser
     * @param string $dbPass
     * @param array $driverOptions
     * @return bool
     * @throws Exception
     */
    public function checkDatabaseConnectionWithDriverOptions(
        $dbName,
        $dbHost,
        $dbUser,
        $dbPass = '',
        $driverOptions = []
    ) {
        try {
            $connection = $this->connectionFactory->create(
                [
                    ConfigOptionsListConstants::KEY_NAME => $dbName,
                    ConfigOptionsListConstants::KEY_HOST => $dbHost,
                    ConfigOptionsListConstants::KEY_USER => $dbUser,
                    ConfigOptionsListConstants::KEY_PASSWORD => $dbPass,
                    ConfigOptionsListConstants::KEY_ACTIVE => true,
                    ConfigOptionsListConstants::KEY_DRIVER_OPTIONS => $driverOptions,
                ]
            );
            $connection->getConnection();
        } catch (\Exception $e) {
            throw new Exception(
                "Database '{$dbName}' does not exist "
                . "or specified database server user does not have privileges to access this database: "
                . $e->getMessage()
            );
        }

        $version = (int)$connection->fetchOne('SHOW server_version_num');
        if ($version < self::VERSION_REQUIRED) {
            throw new Exception(
                sprintf('Sorry, but we support PostgreSQL version %d or later.', self::VERSION_REQUIRED / 10000)
            );
        }

        $hasPrivileges = $connection->fetchOne(
            "SELECT (has_database_privilege(current_database(), 'CREATE, TEMPORARY')"
            . " AND has_schema_privilege(current_schema(), 'CREATE, USAGE'))::int"
        );
        if (!$hasPrivileges) {
            throw new Exception(
                'Database user does not have enough privileges. Please make sure CREATE and TEMPORARY privileges'
                . " are granted on database '{$dbName}', and CREATE and USAGE privileges on its schema."
            );
        }

        return true;
    }
}
