<?php
/**
 * PostgreSQL connection adapter
 */
namespace Horobyna\Db\Model\ResourceModel\Type\Db\Pdo;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection\ConnectionAdapterInterface;
use Magento\Framework\DB;
use Magento\Framework\DB\SelectFactory;
use Horobyna\Db\Adapter\Pdo\PgsqlFactory;

/**
 * Creates PostgreSQL connections from the "db/connection" configuration of app/etc/env.php
 *
 * Counterpart of \Magento\Framework\Model\ResourceModel\Type\Db\Pdo\Mysql. The host may contain the port
 * ("host:5432"), or the port may be given with the "port" option. Besides the libpq connection parameters,
 * "schema" sets search_path and "statement_savepoints" => false turns off per-statement savepoints.
 */
class Pgsql extends \Magento\Framework\Model\ResourceModel\Type\Db implements ConnectionAdapterInterface
{
    /**
     * @var array
     */
    protected $connectionConfig;

    /**
     * @var PgsqlFactory
     */
    private $pgsqlFactory;

    /**
     * Constructor
     *
     * @param array $config
     * @param PgsqlFactory|null $pgsqlFactory
     */
    public function __construct(
        array $config,
        ?PgsqlFactory $pgsqlFactory = null
    ) {
        $this->connectionConfig = $this->getValidConfig($config);
        $this->pgsqlFactory = $pgsqlFactory ?: ObjectManager::getInstance()->get(PgsqlFactory::class);
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    public function getConnection(?DB\LoggerInterface $logger = null, ?SelectFactory $selectFactory = null)
    {
        $connection = $this->getDbConnectionInstance($logger, $selectFactory);

        $profiler = $connection->getProfiler();
        if ($profiler instanceof DB\Profiler) {
            $profiler->setType($this->connectionConfig['type']);
            $profiler->setHost($this->connectionConfig['host']);
        }

        return $connection;
    }

    /**
     * Create and return database connection object instance
     *
     * @param DB\LoggerInterface|null $logger
     * @param SelectFactory|null $selectFactory
     * @return \Horobyna\Db\Adapter\Pdo\Pgsql
     */
    protected function getDbConnectionInstance(?DB\LoggerInterface $logger = null, ?SelectFactory $selectFactory = null)
    {
        return $this->pgsqlFactory->create(
            $this->getDbConnectionClassName(),
            $this->connectionConfig,
            $logger,
            $selectFactory
        );
    }

    /**
     * Retrieve DB connection class name
     *
     * @return string
     */
    protected function getDbConnectionClassName()
    {
        return \Horobyna\Db\Adapter\Pdo\Pgsql::class;
    }

    /**
     * Validates the config and adds default options, if any is missing
     *
     * MySQL-only options of a shared env.php are harmless: the adapter skips "SET NAMES" init statements
     * and ignores the engine.
     *
     * @param array $config
     * @return array
     */
    private function getValidConfig(array $config)
    {
        $default = ['type' => 'pdo_pgsql', 'active' => false];
        foreach ($default as $key => $value) {
            if (!isset($config[$key])) {
                $config[$key] = $value;
            }
        }
        $required = ['host'];
        foreach ($required as $name) {
            if (!isset($config[$name])) {
                throw new \InvalidArgumentException("PostgreSQL adapter: Missing required configuration option '$name'");
            }
        }

        $config['active'] = !(
            $config['active'] === 'false'
            || $config['active'] === false
            || $config['active'] === '0'
        );

        return $config;
    }
}
