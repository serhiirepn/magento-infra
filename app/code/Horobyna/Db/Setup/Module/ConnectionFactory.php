<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Setup\Module;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Magento\Framework\DB\Logger\Quiet;
use Magento\Setup\Model\ObjectManagerProvider;
use Horobyna\Db\Adapter\Pdo\PgsqlFactory;
use Horobyna\Db\Model\ResourceModel\Type\Db\Pdo\Pgsql;

/**
 * Connection factory of the setup application (setup:install, setup:upgrade...)
 *
 * The core factory creates MySQL connections without the object manager, so the preference of
 * \Magento\Framework\App\ResourceConnection\ConnectionAdapterInterface does not apply to it. The setup
 * application is not built by the Magento object manager either: this class replaces the core one through the
 * alias in setup/config/autoload/pgsql.global.php.
 */
class ConnectionFactory extends \Magento\Setup\Module\ConnectionFactory
{
    /**
     * @var ServiceLocatorInterface
     */
    private $serviceLocator;

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        parent::__construct($serviceLocator);
        $this->serviceLocator = $serviceLocator;
    }

    /**
     * Create a PostgreSQL connection
     *
     * @param array $connectionConfig
     * @return \Horobyna\Db\Adapter\Pdo\Pgsql
     */
    public function create(array $connectionConfig)
    {
        $objectManager = $this->serviceLocator->get(ObjectManagerProvider::class)->get();
        $resourceInstance = new Pgsql($connectionConfig, new PgsqlFactory($objectManager));
        return $resourceInstance->getConnection($this->serviceLocator->get(Quiet::class));
    }
}
