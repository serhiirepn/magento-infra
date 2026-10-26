<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Adapter\Pdo;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Magento\Framework\DB\Adapter\TableNotFoundException;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\ExpressionConverter;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\Profiler;
use Magento\Framework\DB\Query\Generator as QueryGenerator;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\DB\Statement\Parameter;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Setup\SchemaListener;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;
use Horobyna\Db\SelectFactory as PgsqlSelectFactory;
use Horobyna\Db\Sql\EmptyNumberFixer;
use Horobyna\Db\Sql\FunctionMapper;
use Horobyna\Db\Sql\Scanner;
use Horobyna\Db\Sql\SyntaxMapper;
use Horobyna\Db\Sql\UnionValueCaster;
use Zend_Db_Adapter_Exception;
use Zend_Db_Statement_Exception;

// @codingStandardsIgnoreStart

/**
 * PostgreSQL database adapter
 *
 * Drop-in counterpart of \Magento\Framework\DB\Adapter\Pdo\Mysql. MySQL behaviour that Magento relies on is
 * emulated where PostgreSQL has an equivalent:
 * - backtick-quoted identifiers (hardcoded in Magento's select renderers) are rewritten to double quotes;
 * - INSERT IGNORE / ON DUPLICATE KEY UPDATE / REPLACE become INSERT ... ON CONFLICT;
 * - NULL or 0 inserted into an identity column generates a new value, and explicit values advance the sequence;
 * - DML statements inside a transaction run in a savepoint, so a failed statement does not abort the transaction;
 * - "ON UPDATE CURRENT_TIMESTAMP" columns are maintained by a trigger.
 *
 * The class does not extend the MySQL adapter, so no MySQL-specific code is inherited. \Magento\Framework\DB\Select
 * type-hints the MySQL adapter, so selects are built by \Horobyna\Db\SelectFactory, whose \Horobyna\Db\Select only
 * relaxes that constructor.
 *
 * @api
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Pgsql extends \Zend_Db_Adapter_Pdo_Pgsql implements AdapterInterface, ResetAfterRequestInterface
{
    // @codingStandardsIgnoreEnd

    public const TIMESTAMP_FORMAT      = 'Y-m-d H:i:s';
    public const DATETIME_FORMAT       = 'Y-m-d H:i:s';
    public const DATE_FORMAT           = 'Y-m-d';

    public const DDL_DESCRIBE          = 1;
    public const DDL_CREATE            = 2;
    public const DDL_INDEX             = 3;
    public const DDL_FOREIGN_KEY       = 4;
    private const DDL_EXISTS           = 5;
    public const DDL_CACHE_PREFIX      = 'DB_PDO_PGSQL_DDL';
    public const DDL_CACHE_TAG         = 'DB_PDO_PGSQL_DDL';

    /**
     * PostgreSQL silently truncates identifiers to NAMEDATALEN - 1 bytes
     */
    public const LENGTH_TABLE_NAME     = 63;
    public const LENGTH_INDEX_NAME     = 63;
    public const LENGTH_FOREIGN_NAME   = 63;

    /**
     * Maximum number of connection retries
     */
    public const MAX_CONNECTION_RETRIES = 10;

    /**
     * Trigger function that emulates MySQL "ON UPDATE CURRENT_TIMESTAMP"
     */
    public const ON_UPDATE_TIMESTAMP_FUNCTION = 'magento_on_update_current_timestamp';

    /**
     * MySQL statements that only set user variables, checks or limits that have no PostgreSQL counterpart, e.g.
     * "SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0", "SET SESSION max_heap_table_size = 67108864"
     */
    private const MYSQL_SESSION_SET_PATTERN = '/^\s*SET\s+(@|(?:SESSION\s+)?(?:UNIQUE_CHECKS|FOREIGN_KEY_CHECKS'
        . '|tmp_table_size|max_heap_table_size|group_concat_max_len)\s*=)/i';

    /**
     * Values reported for the MySQL system variables Magento reads (SELECT @@max_heap_table_size...)
     *
     * PostgreSQL has no memory tables and no group_concat limit, so the limits are ones Magento does not try to
     * raise or warn about; sequences advance by one. There is no packet limit, but import sizes its multi-row
     * INSERTs by max_allowed_packet and PostgreSQL allows at most 65535 parameters per statement, so it is kept
     * small (1 MB, import's own fallback).
     */
    private const MYSQL_SYSTEM_VARIABLES = [
        'max_heap_table_size' => '67108864',
        'tmp_table_size' => '67108864',
        'innodb_buffer_pool_size' => '9223372036854775807',
        'auto_increment_increment' => '1',
        'auto_increment_offset' => '1',
        'max_allowed_packet' => '1048576',
    ];

    /**
     * MySQL "SHOW [SESSION|GLOBAL] VARIABLES [LIKE 'pattern']", answered from MYSQL_SYSTEM_VARIABLES
     */
    private const MYSQL_SHOW_VARIABLES_PATTERN = '/^\s*SHOW\s+(?:(?:SESSION|GLOBAL)\s+)?VARIABLES'
        . '(?:\s+LIKE\s+([\'"])(.*?)\1)?\s*;?\s*$/is';

    /**
     * Name as MySQL code writes it: [qualifier.]name, each part plain, `quoted` or "quoted"
     */
    private const MYSQL_QUALIFIED_NAME = '((?:`[^`]+`|"(?:[^"]|"")+"|[\w$]+)(?:\.(?:`[^`]+`|"(?:[^"]|"")+"|[\w$]+))?)';

    /**
     * MySQL "SHOW TRIGGERS [FROM db] [LIKE 'table']"
     */
    private const MYSQL_SHOW_TRIGGERS_PATTERN = '/^\s*SHOW\s+TRIGGERS(?:\s+(?:FROM|IN)\s+\S+)?'
        . '(?:\s+LIKE\s+([\'"])(.*?)\1)?\s*;?\s*$/is';

    /**
     * MySQL "SHOW CREATE TRIGGER name"
     */
    private const MYSQL_SHOW_CREATE_TRIGGER_PATTERN = '/^\s*SHOW\s+CREATE\s+TRIGGER\s+' . self::MYSQL_QUALIFIED_NAME
        . '\s*;?\s*$/is';

    /**
     * MySQL "DROP TRIGGER [IF EXISTS] name", without the PostgreSQL "ON table"
     */
    private const MYSQL_DROP_TRIGGER_PATTERN = '/^\s*DROP\s+TRIGGER\s+(IF\s+EXISTS\s+)?' . self::MYSQL_QUALIFIED_NAME
        . '\s*;?\s*$/is';

    /**
     * MySQL "CREATE [TEMPORARY] TABLE [IF NOT EXISTS] new LIKE old", also with "(LIKE old)"
     */
    private const MYSQL_CREATE_TABLE_LIKE_PATTERN = '/^\s*CREATE\s+(TEMPORARY\s+)?TABLE\s+(IF\s+NOT\s+EXISTS\s+)?'
        . self::MYSQL_QUALIFIED_NAME . '\s+(\(\s*)?LIKE\s+' . self::MYSQL_QUALIFIED_NAME . '\s*(?(4)\))\s*;?\s*$/is';

    /**
     * Query that does nothing, run instead of statements that have no PostgreSQL counterpart
     */
    private const NO_OP_QUERY = 'SELECT NULL WHERE FALSE';

    /**
     * Savepoint used to isolate a single statement inside a transaction
     */
    private const STATEMENT_SAVEPOINT = 'magento_statement';

    /**
     * Connection parameters understood by libpq; anything else in the config must stay out of the DSN
     */
    private const DSN_KEYS = [
        'host', 'port', 'dbname', 'sslmode', 'sslcert', 'sslkey', 'sslrootcert', 'connect_timeout', 'application_name',
    ];

    /**
     * SQLSTATE codes that mean the connection is gone
     */
    private const CONNECTION_ERROR_STATES = ['57P01', '57P02', '57P03'];

    /**
     * Messages libpq reports (without SQLSTATE) when the server connection is lost
     */
    private const CONNECTION_ERROR_MESSAGES = [
        'server closed the connection unexpectedly',
        'no connection to the server',
        'terminating connection',
        'SSL connection has been closed unexpectedly',
    ];

    /**
     * pg_type.typname => MySQL-compatible DATA_TYPE reported by describeTable()
     */
    private const DATA_TYPE_MAP = [
        'bool'        => 'bool',
        'int2'        => 'smallint',
        'int4'        => 'int',
        'int8'        => 'bigint',
        'numeric'     => 'decimal',
        'float4'      => 'float',
        'float8'      => 'float',
        'bpchar'      => 'char',
        'varchar'     => 'varchar',
        'text'        => 'text',
        'bytea'       => 'blob',
        'date'        => 'date',
        'timestamp'   => 'timestamp',
        'timestamptz' => 'timestamp',
    ];

    /**
     * PostgreSQL reserved words (incl. those that cannot be used as table names) that MySQL allows as aliases
     */
    private const RESERVED_WORDS = [
        'all', 'analyse', 'analyze', 'and', 'any', 'array', 'as', 'asc', 'asymmetric', 'authorization', 'binary',
        'both', 'case', 'cast', 'check', 'collate', 'collation', 'column', 'concurrently', 'constraint', 'create',
        'cross', 'current_catalog', 'current_date', 'current_role', 'current_schema', 'current_time',
        'current_timestamp', 'current_user', 'default', 'deferrable', 'desc', 'distinct', 'do', 'else', 'end',
        'except', 'false', 'fetch', 'for', 'foreign', 'freeze', 'from', 'full', 'grant', 'group', 'having', 'ilike',
        'in', 'initially', 'inner', 'intersect', 'into', 'is', 'isnull', 'join', 'lateral', 'leading', 'left', 'like',
        'limit', 'localtime', 'localtimestamp', 'natural', 'not', 'notnull', 'null', 'offset', 'on', 'only', 'or',
        'order', 'outer', 'overlaps', 'placing', 'primary', 'references', 'returning', 'right', 'select',
        'session_user', 'similar', 'some', 'symmetric', 'system_user', 'table', 'tablesample', 'then', 'to',
        'trailing', 'true', 'union', 'unique', 'user', 'using', 'variadic', 'verbose', 'when', 'where', 'window',
        'with',
    ];

    /**
     * pg_constraint action codes => FK action
     */
    private const FK_ACTION_MAP = [
        'a' => AdapterInterface::FK_ACTION_NO_ACTION,
        'r' => AdapterInterface::FK_ACTION_RESTRICT,
        'c' => AdapterInterface::FK_ACTION_CASCADE,
        'n' => AdapterInterface::FK_ACTION_SET_NULL,
        'd' => AdapterInterface::FK_ACTION_SET_DEFAULT,
    ];

    /**
     * PDO type.
     *
     * @var string
     */
    protected $_pdoType = 'pgsql';

    /**
     * Keys are UPPERCASE SQL datatypes or the constants
     * Zend_Db::INT_TYPE, Zend_Db::BIGINT_TYPE, or Zend_Db::FLOAT_TYPE.
     *
     * @var array
     */
    protected $_numericDataTypes = [
        \Zend_Db::INT_TYPE    => \Zend_Db::INT_TYPE,
        \Zend_Db::BIGINT_TYPE => \Zend_Db::BIGINT_TYPE,
        \Zend_Db::FLOAT_TYPE  => \Zend_Db::FLOAT_TYPE,
        'INTEGER'             => \Zend_Db::INT_TYPE,
        'INT'                 => \Zend_Db::INT_TYPE,
        'SERIAL'              => \Zend_Db::INT_TYPE,
        'SMALLINT'            => \Zend_Db::INT_TYPE,
        'BIGINT'              => \Zend_Db::BIGINT_TYPE,
        'BIGSERIAL'           => \Zend_Db::BIGINT_TYPE,
        'DECIMAL'             => \Zend_Db::FLOAT_TYPE,
        'DOUBLE PRECISION'    => \Zend_Db::FLOAT_TYPE,
        'NUMERIC'             => \Zend_Db::FLOAT_TYPE,
        'REAL'                => \Zend_Db::FLOAT_TYPE,
    ];

    /**
     * Default class name for a DB statement.
     *
     * @var string
     */
    protected $_defaultStmtClass = \Horobyna\Db\Statement\Pdo\Pgsql::class;

    /**
     * Current Transaction Level
     *
     * @var int
     */
    protected $_transactionLevel    = 0;

    /**
     * Whether transaction was rolled back or not
     *
     * @var bool
     */
    protected $_isRolledBack = false;

    /**
     * Set attribute to connection flag
     *
     * @var bool
     */
    protected $_connectionFlagsSet  = false;

    /**
     * Tables DDL cache
     *
     * @var array
     */
    protected $_ddlCache            = [];

    /**
     * Cache frontend adapter instance
     *
     * @var FrontendInterface
     */
    protected $_cacheAdapter;

    /**
     * DDL cache allowing flag
     * @var bool
     */
    protected $_isDdlCacheAllowed = true;

    /**
     * PostgreSQL column - Table DDL type pairs
     *
     * Booleans are kept as smallint because Magento compares them with integer literals.
     *
     * @var array
     */
    protected $_ddlColumnTypes      = [
        Table::TYPE_BOOLEAN       => 'smallint',
        Table::TYPE_SMALLINT      => 'smallint',
        Table::TYPE_INTEGER       => 'integer',
        Table::TYPE_BIGINT        => 'bigint',
        Table::TYPE_FLOAT         => 'double precision',
        Table::TYPE_DECIMAL       => 'numeric',
        Table::TYPE_NUMERIC       => 'numeric',
        Table::TYPE_DATE          => 'date',
        Table::TYPE_TIMESTAMP     => 'timestamp',
        Table::TYPE_DATETIME      => 'timestamp',
        Table::TYPE_TEXT          => 'text',
        Table::TYPE_BLOB          => 'bytea',
        Table::TYPE_VARBINARY     => 'bytea',
    ];

    /**
     * Allowed interval units array
     *
     * @var array
     */
    protected $_intervalUnits = [
        self::INTERVAL_YEAR     => 'YEAR',
        self::INTERVAL_MONTH    => 'MONTH',
        self::INTERVAL_DAY      => 'DAY',
        self::INTERVAL_HOUR     => 'HOUR',
        self::INTERVAL_MINUTE   => 'MINUTE',
        self::INTERVAL_SECOND   => 'SECOND',
    ];

    /**
     * Hook callback to modify queries. Kept for compatibility with the MySQL adapter.
     *
     * @var array|null
     */
    protected $_queryHook = null;

    /**
     * @var StringUtils
     */
    protected $string;

    /**
     * @var DateTime
     */
    protected $dateTime;

    /**
     * @var PgsqlSelectFactory
     */
    protected $selectFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Map that links SQLSTATE to corresponding Magento exception
     *
     * @var Zend_Db_Adapter_Exception[]
     */
    private $exceptionMap;

    /**
     * @var QueryGenerator
     */
    private $queryGenerator;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var FunctionMapper
     */
    private $functionMapper;

    /**
     * @var UnionValueCaster
     */
    private $unionValueCaster;

    /**
     * @var EmptyNumberFixer
     */
    private $emptyNumberFixer;

    /**
     * @var Scanner
     */
    private $scanner;

    /**
     * @var SyntaxMapper
     */
    private $syntaxMapper;

    /**
     * @var SchemaListener
     */
    private $schemaListener;

    /**
     * Process id that the connection is associated with
     *
     * @var int|null
     */
    private ?int $pid = null;

    /**
     * Parent process's database connection
     *
     * @var array
     */
    private $parentConnections = [];

    /**
     * Identity values returned by the last insert into each table
     *
     * @var array
     */
    private $lastInsertIds = [];

    /**
     * Identity value returned by the last insert into any table
     *
     * @var string|null
     */
    private $lastInsertId = null;

    /**
     * Whether the adapter itself is issuing an INSERT (so that it does not invalidate its own last insert ids)
     *
     * @var bool
     */
    private $isAdapterInsert = false;

    /**
     * Between startSetup() and endSetup() an explicit 0 is stored in identity columns, as MySQL does in setup
     *
     * @var bool
     */
    private $isSetupMode = false;

    /**
     * Whether startSetup() switched session_replication_role to disable foreign key checks
     *
     * @var bool
     */
    private $isReplicationRoleChanged = false;

    /**
     * Run INSERT/UPDATE/DELETE inside a transaction in a savepoint, so a failed statement can be caught and
     * the transaction continued, as with MySQL. Configured with the "statement_savepoints" option.
     *
     * @var bool
     */
    private $useStatementSavepoints;

    /**
     * Constructor
     *
     * @param StringUtils $string
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param SelectFactory $selectFactory a core factory is converted to \Horobyna\Db\SelectFactory
     * @param array $config
     * @param SerializerInterface|null $serializer
     * @param FunctionMapper|null $functionMapper
     * @param UnionValueCaster|null $unionValueCaster
     * @param EmptyNumberFixer|null $emptyNumberFixer
     * @param Scanner|null $scanner
     * @param SyntaxMapper|null $syntaxMapper
     */
    public function __construct(
        StringUtils $string,
        DateTime $dateTime,
        LoggerInterface $logger,
        SelectFactory $selectFactory,
        array $config = [],
        ?SerializerInterface $serializer = null,
        ?FunctionMapper $functionMapper = null,
        ?UnionValueCaster $unionValueCaster = null,
        ?EmptyNumberFixer $emptyNumberFixer = null,
        ?Scanner $scanner = null,
        ?SyntaxMapper $syntaxMapper = null
    ) {
        $this->pid = getmypid();
        $this->string = $string;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
        $this->selectFactory = PgsqlSelectFactory::fromFactory($selectFactory);
        $this->serializer = $serializer ?: ObjectManager::getInstance()->get(SerializerInterface::class);
        $this->functionMapper = $functionMapper ?: ObjectManager::getInstance()->get(FunctionMapper::class);
        $this->unionValueCaster = $unionValueCaster ?: ObjectManager::getInstance()->get(UnionValueCaster::class);
        $this->emptyNumberFixer = $emptyNumberFixer ?: ObjectManager::getInstance()->get(EmptyNumberFixer::class);
        $this->scanner = $scanner ?? new Scanner();
        $this->syntaxMapper = $syntaxMapper ?? new SyntaxMapper($this->scanner);
        $this->exceptionMap = [
            // unique_violation
            '23505' => DuplicateException::class,
            // deadlock_detected
            '40P01' => DeadlockException::class,
            // lock_not_available
            '55P03' => LockWaitException::class,
            // undefined_table
            '42P01' => TableNotFoundException::class,
        ];
        $this->useStatementSavepoints = !isset($config['statement_savepoints'])
            || !in_array($config['statement_savepoints'], [false, 'false', '0', 0], true);
        unset($config['statement_savepoints']);
        try {
            \Zend_Db_Adapter_Abstract::__construct($config);
        } catch (Zend_Db_Adapter_Exception $e) {
            throw new \InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @inheritdoc
     */
    public function _resetState() : void
    {
        $this->_transactionLevel = 0;
        $this->_isRolledBack = false;
        $this->_connectionFlagsSet = false;
        $this->_ddlCache = [];
        $this->_isDdlCacheAllowed = true;
        $this->_queryHook = null;
        $this->lastInsertIds = [];
        $this->lastInsertId = null;
        $this->isSetupMode = false;
        $this->isReplicationRoleChanged = false;
        $this->avoidReusingParentProcessConnection();
        $this->closeConnection();
    }

    /**
     * Begin new DB transaction for connection
     *
     * @return $this
     * @throws \Exception
     */
    public function beginTransaction()
    {
        if ($this->_isRolledBack) {
            // phpcs:ignore Magento2.Exceptions.DirectThrow.FoundDirectThrow
            throw new \Exception(AdapterInterface::ERROR_ROLLBACK_INCOMPLETE_MESSAGE);
        }
        if ($this->_transactionLevel === 0) {
            $this->logger->startTimer();
            try {
                $this->performQuery(function () {
                    \Zend_Db_Adapter_Abstract::beginTransaction();
                });
            } finally {
                $this->logger->logStats(LoggerInterface::TYPE_TRANSACTION, 'BEGIN');
            }
        }
        ++$this->_transactionLevel;
        return $this;
    }

    /**
     * Commit DB transaction
     *
     * @return $this
     * @throws \Exception
     */
    public function commit()
    {
        if ($this->_transactionLevel === 1 && !$this->_isRolledBack) {
            $this->logger->startTimer();
            \Zend_Db_Adapter_Abstract::commit();
            $this->logger->logStats(LoggerInterface::TYPE_TRANSACTION, 'COMMIT');
        } elseif ($this->_transactionLevel === 0) {
            // phpcs:ignore Magento2.Exceptions.DirectThrow.FoundDirectThrow
            throw new \Exception(AdapterInterface::ERROR_ASYMMETRIC_COMMIT_MESSAGE);
        } elseif ($this->_isRolledBack) {
            // phpcs:ignore Magento2.Exceptions.DirectThrow.FoundDirectThrow
            throw new \Exception(AdapterInterface::ERROR_ROLLBACK_INCOMPLETE_MESSAGE);
        }
        --$this->_transactionLevel;
        return $this;
    }

    /**
     * Rollback DB transaction
     *
     * @return $this
     * @throws \Exception
     */
    public function rollBack()
    {
        if ($this->_transactionLevel === 1) {
            $this->logger->startTimer();
            \Zend_Db_Adapter_Abstract::rollBack();
            $this->_isRolledBack = false;
            $this->logger->logStats(LoggerInterface::TYPE_TRANSACTION, 'ROLLBACK');
        } elseif ($this->_transactionLevel === 0) {
            // phpcs:ignore Magento2.Exceptions.DirectThrow.FoundDirectThrow
            throw new \Exception(AdapterInterface::ERROR_ASYMMETRIC_ROLLBACK_MESSAGE);
        } else {
            $this->_isRolledBack = true;
        }
        --$this->_transactionLevel;
        return $this;
    }

    /**
     * Get adapter transaction level state. Return 0 if all transactions are complete
     *
     * @return int
     */
    public function getTransactionLevel()
    {
        return $this->_transactionLevel;
    }

    /**
     * Convert date to DB format
     *
     * @param int|string|\DateTimeInterface $date
     * @return \Zend_Db_Expr
     */
    public function convertDate($date)
    {
        return $this->formatDate($date, false);
    }

    /**
     * Convert date and time to DB format
     *
     * @param int|string|\DateTimeInterface $datetime
     * @return \Zend_Db_Expr
     */
    public function convertDateTime($datetime)
    {
        return $this->formatDate($datetime, true);
    }

    /**
     * If the connection is associated to a different process id, then we need to not use it.
     *
     * @return void
     */
    private function avoidReusingParentProcessConnection()
    {
        if (getmypid() != $this->pid) {
            // Note: we hide parent's connection into parentConnections so that the destructor isn't called on it.
            // Because if destructor is called, it causes parent's connection to die
            // We store in array, if parent is also hiding its parent's connection
            $this->parentConnections[] = $this->_connection;
            $this->_connection = null;
            $this->pid = getmypid();
        }
    }

    /**
     * Creates a PDO object and connects to the database.
     *
     * @return void
     * @throws Zend_Db_Adapter_Exception
     * @throws Zend_Db_Statement_Exception
     */
    protected function _connect()
    {
        $this->avoidReusingParentProcessConnection();
        if ($this->_connection) {
            return;
        }

        if (!extension_loaded('pdo_pgsql')) {
            throw new Zend_Db_Adapter_Exception('pdo_pgsql extension is not installed');
        }

        if (!isset($this->_config['host'])) {
            throw new Zend_Db_Adapter_Exception('No host configured to connect');
        }

        // Accept Magento's "host:port" notation; a path is a unix socket directory and is passed as is
        if (strpos($this->_config['host'], '/') === false && strpos($this->_config['host'], ':') !== false) {
            list($this->_config['host'], $this->_config['port']) = explode(':', $this->_config['host'], 2);
        }

        if (!isset($this->_config['driver_options'][\PDO::ATTR_STRINGIFY_FETCHES])) {
            $this->_config['driver_options'][\PDO::ATTR_STRINGIFY_FETCHES] = true;
        }

        $this->logger->startTimer();
        \Zend_Db_Adapter_Pdo_Abstract::_connect();
        $this->logger->logStats(LoggerInterface::TYPE_CONNECT, '');

        $this->_connection->exec("SET client_encoding TO 'UTF8'");
        // As we use default value CURRENT_TIMESTAMP for TIMESTAMP type columns we need to set GMT timezone
        $this->_connection->exec("SET TIME ZONE 'UTC'");

        if (!empty($this->_config['schema'])) {
            $this->_connection->exec('SET search_path TO ' . $this->quoteIdentifier($this->_config['schema']));
        }

        if (!$this->_connectionFlagsSet) {
            // Emulated prepares let PostgreSQL infer parameter types from the quoted literals, as MySQL does
            $this->_connection->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            $this->_connectionFlagsSet = true;
        }

        if (isset($this->_config['initStatements'])) {
            $statements = $this->_splitMultiQuery($this->_config['initStatements']);
            foreach ($statements as $statement) {
                // MySQL "SET NAMES utf8" is not valid PostgreSQL; the client encoding is UTF8 already
                if (preg_match('/^SET\s+NAMES\b/i', $statement)) {
                    continue;
                }
                $this->_query($statement);
            }
        }
    }

    /**
     * Build the PDO DSN from the libpq connection parameters only
     *
     * The generic implementation puts every config key into the DSN, and libpq rejects unknown ones
     * such as Magento's "model", "engine" or "initStatements".
     *
     * @return string
     */
    protected function _dsn()
    {
        $dsn = [];
        foreach (self::DSN_KEYS as $key) {
            if (isset($this->_config[$key]) && $this->_config[$key] !== '') {
                $dsn[] = $key . '=' . $this->_config[$key];
            }
        }

        return $this->_pdoType . ':' . implode(';', $dsn);
    }

    /**
     * Returns the symbol the adapter uses for delimiting identifiers.
     *
     * @return string
     */
    public function getQuoteIdentifierSymbol()
    {
        return '"';
    }

    /**
     * Closes the connection.
     *
     * @return void
     */
    public function closeConnection()
    {
        \Zend_Db_Adapter_Pdo_Abstract::closeConnection();
    }

    /**
     * Return the most recent value from the specified sequence in the database.
     *
     * @param string $sequenceName
     * @return string
     */
    public function lastSequenceId($sequenceName)
    {
        return $this->fetchOne('SELECT CURRVAL(?)', [$this->quoteIdentifier($sequenceName, true)]);
    }

    /**
     * Generate a new value from the specified sequence in the database, and return it.
     *
     * @param string $sequenceName
     * @return string
     */
    public function nextSequenceId($sequenceName)
    {
        return $this->fetchOne('SELECT NEXTVAL(?)', [$this->quoteIdentifier($sequenceName, true)]);
    }

    /**
     * Retrieve Create Table SQL
     *
     * PostgreSQL has no SHOW CREATE TABLE: the statement is built from the catalog, with columns and constraints
     * but without indexes. describeTable(), getIndexList() and getForeignKeys() expose the same information in a
     * structured form; Magento only inspects this statement for MySQL charsets (setup, mview changelogs).
     *
     * @param string $tableName
     * @param string $schemaName
     * @return string
     */
    public function getCreateTable($tableName, $schemaName = null)
    {
        $cacheKey = $this->_getTableName($tableName, $schemaName);
        $ddl = $this->loadDdlCache($cacheKey, self::DDL_CREATE);
        if ($ddl === false) {
            $regclass = $this->getRegclassSql($tableName, $schemaName);
            $definitions = [];
            $columns = $this->fetchAll(
                sprintf(
                    'SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull::int AS notnull,'
                    . ' a.attidentity, pg_get_expr(d.adbin, d.adrelid) AS default_value'
                    . ' FROM pg_attribute AS a'
                    . ' LEFT JOIN pg_attrdef AS d ON d.adrelid = a.attrelid AND d.adnum = a.attnum'
                    . ' WHERE a.attrelid = %s AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum',
                    $regclass
                )
            );
            foreach ($columns as $column) {
                $identity = ['a' => ' GENERATED ALWAYS AS IDENTITY', 'd' => ' GENERATED BY DEFAULT AS IDENTITY'];
                $definitions[] = sprintf(
                    '  %s %s%s%s%s',
                    $this->quoteIdentifier($column['attname']),
                    $column['type'],
                    $identity[$column['attidentity']] ?? '',
                    $column['notnull'] ? ' NOT NULL' : '',
                    $column['default_value'] !== null ? ' DEFAULT ' . $column['default_value'] : ''
                );
            }
            $constraints = $this->fetchPairs(
                sprintf(
                    'SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = %s'
                    . ' ORDER BY contype <> \'p\', conname',
                    $regclass
                )
            );
            foreach ($constraints as $name => $definition) {
                $definitions[] = sprintf('  CONSTRAINT %s %s', $this->quoteIdentifier($name), $definition);
            }
            $ddl = $definitions
                ? sprintf(
                    "CREATE TABLE %s (\n%s\n)",
                    $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
                    implode(",\n", $definitions)
                )
                : '';
            // Do not remember a missing table: it may be created by a raw query that does not reset the cache
            if ($ddl !== '') {
                $this->saveDdlCache($cacheKey, self::DDL_CREATE, $ddl);
            }
        }

        return $ddl;
    }

    /**
     * Run RAW Query
     *
     * @param string $sql
     * @return \Zend_Db_Statement_Interface
     * @throws \PDOException
     */
    public function rawQuery($sql)
    {
        try {
            $result = $this->query($sql);
        } catch (Zend_Db_Statement_Exception $e) {
            // Convert to \PDOException to maintain backwards compatibility with usage of MySQL adapter
            $e = $e->getPrevious();
            if (!($e instanceof \PDOException)) {
                $e = new \PDOException($e->getMessage(), $e->getCode());
            }
            throw $e;
        }

        return $result;
    }

    /**
     * Run RAW query and Fetch First row
     *
     * @param string $sql
     * @param string|int $field
     * @return mixed|null
     */
    public function rawFetchRow($sql, $field = null)
    {
        $result = $this->rawQuery($sql);
        if (!$result) {
            return false;
        }

        $row = $result->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        if (empty($field)) {
            return $row;
        } else {
            return $row[$field] ?? false;
        }
    }

    /**
     * Special handling for PDO query().
     *
     * PostgreSQL DDL is transactional, so unlike MySQL there is no restriction on DDL inside transactions.
     *
     * @param string|\Magento\Framework\DB\Select $sql The SQL statement with placeholders.
     * @param mixed $bind An array of data or data itself to bind to the placeholders.
     * @return \Zend_Db_Statement_Pdo|void
     * @throws Zend_Db_Adapter_Exception To re-throw \PDOException.
     * @throws Zend_Db_Statement_Exception
     */
    protected function _query($sql, $bind = [])
    {
        $result = null;
        try {
            $this->_prepareQuery($sql, $bind);
            if (!$this->isAdapterInsert && preg_match('/^\s*insert\b/i', $sql)) {
                // An INSERT the adapter cannot see the identity of; fall back to the sequence in lastInsertId()
                $this->lastInsertIds = [];
                $this->lastInsertId = null;
            }
            $this->logger->startTimer();
            // An empty string may be rejected as a number (see EmptyNumberFixer); inside a transaction such a
            // statement runs in a savepoint, so that it can be retried
            $mayHaveEmptyNumber = strpos($sql, "''") !== false
                || in_array('', $bind, true)
                || in_array(null, $bind, true);
            $useSavepoint = $this->useStatementSavepoints
                && ($mayHaveEmptyNumber || preg_match('/^\s*(insert|update|delete)\b/i', $sql));
            for ($attempt = 1;; $attempt++) {
                $execute = fn () => \Zend_Db_Adapter_Pdo_Abstract::query($sql, $bind);
                try {
                    $result = $this->performQuery($useSavepoint ? fn () => $this->runInSavepoint($execute) : $execute);
                    break;
                } catch (Zend_Db_Statement_Exception $e) {
                    $fixed = $mayHaveEmptyNumber && $attempt <= 10
                        ? $this->emptyNumberFixer->fix($sql, $bind, $e->getMessage())
                        : null;
                    if ($fixed === null) {
                        throw $e;
                    }
                    list($sql, $bind) = $fixed;
                }
            }
        } finally {
            $this->logger->logStats(LoggerInterface::TYPE_QUERY, $sql, $bind, $result);
        }

        return $result;
    }

    /**
     * Execute query and reconnect if needed.
     *
     * A lost connection is only re-established outside of a transaction: inside one the work done so far
     * is gone with the connection, and silently continuing would commit half a transaction.
     *
     * @param callable $queryExecutor
     * @return \Zend_Db_Statement_Pdo|void
     */
    private function performQuery(callable $queryExecutor)
    {
        $triesCount = 0;
        do {
            $retry = false;
            try {
                return $queryExecutor();
            } catch (\Exception $e) {
                // Finalize broken query
                $profiler = $this->getProfiler();
                if ($profiler instanceof Profiler) {
                    $profiler->queryEndLast();
                }

                $pdoException = null;
                if ($e instanceof \PDOException) {
                    $pdoException = $e;
                } elseif (($e instanceof Zend_Db_Statement_Exception || $e instanceof Zend_Db_Adapter_Exception)
                    && ($e->getPrevious() instanceof \PDOException)
                ) {
                    $pdoException = $e->getPrevious();
                }

                $isConnectionError = $pdoException && $this->isConnectionError($pdoException);
                // Check to reconnect
                if ($isConnectionError && $this->_transactionLevel === 0 && $triesCount < self::MAX_CONNECTION_RETRIES) {
                    $retry = true;
                    $triesCount++;
                    $this->closeConnection();
                    $this->_connect();
                }

                if (!$retry) {
                    $this->logger->critical($e);
                    // rethrow custom exception if needed
                    if ($pdoException) {
                        $sqlState = $this->getSqlState($pdoException);
                        $customExceptionClass = $isConnectionError
                            ? ConnectionException::class
                            : ($this->exceptionMap[$sqlState] ?? null);
                        if ($customExceptionClass !== null) {
                            /** @var Zend_Db_Adapter_Exception $customException */
                            $customException = new $customExceptionClass(
                                $e->getMessage(),
                                (int)($pdoException->errorInfo[1] ?? 0),
                                $e
                            );
                            throw $customException;
                        }
                    }
                    throw $e;
                }
            }
        } while ($retry);
    }

    /**
     * Get SQLSTATE of PDO exception
     *
     * @param \PDOException $exception
     * @return string
     */
    private function getSqlState(\PDOException $exception): string
    {
        return (string)($exception->errorInfo[0] ?? $exception->getCode());
    }

    /**
     * Check whether PDO exception means the connection to the server is lost
     *
     * @param \PDOException $exception
     * @return bool
     */
    private function isConnectionError(\PDOException $exception): bool
    {
        $sqlState = $this->getSqlState($exception);
        if (strncmp($sqlState, '08', 2) === 0 || in_array($sqlState, self::CONNECTION_ERROR_STATES, true)) {
            return true;
        }
        foreach (self::CONNECTION_ERROR_MESSAGES as $message) {
            if (stripos($exception->getMessage(), $message) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run callback in a savepoint when inside a transaction
     *
     * In PostgreSQL any error aborts the whole transaction. A savepoint confines the error to the statement,
     * which matches MySQL behaviour that Magento code relies on (e.g. catching DuplicateException).
     *
     * @param callable $callback
     * @return mixed
     * @throws \Throwable
     */
    private function runInSavepoint(callable $callback)
    {
        if ($this->_transactionLevel === 0 || !$this->_connection || !$this->_connection->inTransaction()) {
            return $callback();
        }

        $this->_connection->exec('SAVEPOINT ' . self::STATEMENT_SAVEPOINT);
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            try {
                $this->_connection->exec('ROLLBACK TO SAVEPOINT ' . self::STATEMENT_SAVEPOINT);
            } catch (\PDOException) {
                // The connection is broken; the original exception is the one worth reporting
            }
            throw $e;
        }
        $this->_connection->exec('RELEASE SAVEPOINT ' . self::STATEMENT_SAVEPOINT);

        return $result;
    }

    /**
     * Special handling for PDO query().
     *
     * All bind parameter names must begin with ':'.
     *
     * @param string|\Magento\Framework\DB\Select $sql The SQL statement with placeholders.
     * @param mixed $bind An array of data or data itself to bind to the placeholders.
     * @return \Zend_Db_Statement_Pdo|void
     * @throws Zend_Db_Adapter_Exception To re-throw \PDOException.
     * @throws LocalizedException In case multiple queries are attempted at once, to protect from SQL injection
     */
    public function query($sql, $bind = [])
    {
        if ($sql !== null &&
            strpos(rtrim((string)$sql, " \t\n\r\0;"), ';') !== false &&
            count($this->_splitMultiQuery((string)$sql)) > 1
        ) {
            throw new \Magento\Framework\Exception\LocalizedException(
                new Phrase("Multiple queries can't be executed. Run a single query and try again.")
            );
        }
        return $this->_query($sql, $bind);
    }

    /**
     * Allows multiple queries
     *
     * Allows multiple queries -- to safeguard against SQL injection, USE CAUTION and verify that input
     * cannot be tampered with.
     *
     * @param string|\Magento\Framework\DB\Select $sql The SQL statement with placeholders.
     * @param mixed $bind An array of data or data itself to bind to the placeholders.
     * @return \Zend_Db_Statement_Pdo|void
     * @throws Zend_Db_Adapter_Exception To re-throw \PDOException.
     * @deprecated
     * @see _query
     */
    public function multiQuery($sql, $bind = [])
    {
        return $this->_query($sql, $bind);
    }

    /**
     * Prepares SQL query
     *
     * Converts MySQL backtick identifiers to PostgreSQL ones, rewrites MySQL functions (see FunctionMapper),
     * skips MySQL session variable assignments, prefixes named binds with ':' and binds booleans
     * as integers (PDO binds false as an empty string, which PostgreSQL does not cast to a number).
     *
     * @param \Magento\Framework\DB\Select|string $sql
     * @param mixed $bind
     * @return $this
     */
    protected function _prepareQuery(&$sql, &$bind = [])
    {
        if ($sql instanceof Select) {
            $sql = $this->inlineOrderAliases($sql);
            $sql = $this->aggregateUngroupedOrders($sql);
            $sql = $this->selectOrderExpressionsForDistinct($sql);
            $sql = $this->fixUngroupedColumns($sql);
        }
        if (preg_match(self::MYSQL_SESSION_SET_PATTERN, (string) $sql)) {
            $sql = self::NO_OP_QUERY;
        } elseif (preg_match('/^\s*SELECT\s+@@/i', (string) $sql)) {
            $sql = $this->translateSystemVariables((string) $sql);
        } elseif (preg_match(self::MYSQL_SHOW_VARIABLES_PATTERN, (string) $sql, $matches)) {
            $sql = $this->getShowVariablesSql($matches[2] ?? null);
        } elseif (preg_match('/^\s*(?:SHOW|DROP)\s+(?:CREATE\s+)?TRIGGERS?\b/i', (string) $sql)) {
            $sql = $this->translateTriggerStatement((string) $sql);
        } elseif (preg_match(self::MYSQL_CREATE_TABLE_LIKE_PATTERN, (string) $sql, $matches)) {
            $sql = $this->getCreateTableLikeSql(!empty($matches[1]), !empty($matches[2]), $matches[3], $matches[5]);
        }
        $sql = $this->translateInsertIgnore($this->translateIdentifiers((string) $sql));
        $sql = $this->translateOnDuplicateKeyUpdate($sql);
        $sql = $this->syntaxMapper->map($sql);
        $sql = $this->functionMapper->map($sql);
        // EAV attribute values of different types are united; see UnionValueCaster
        $sql = $this->unionValueCaster->cast($sql);
        if (!is_array($bind)) {
            $bind = [$bind];
        }

        if ($bind) {
            foreach ($bind as $k => $v) {
                if (is_bool($v)) {
                    $v = (int)$v;
                    $bind[$k] = $v;
                }
                if (!is_int($k)) {
                    if ($k[0] != ':') {
                        $bind[":{$k}"] = $v;
                        unset($bind[$k]);
                    }
                }
            }
        }

        // Special query hook
        if ($this->_queryHook) {
            $object = $this->_queryHook['object'];
            $method = $this->_queryHook['method'];
            $object->$method($sql, $bind);
        }

        return $this;
    }

    /**
     * Replace the MySQL system variables of a SELECT with their values, see MYSQL_SYSTEM_VARIABLES
     *
     * E.g. "SELECT @@session.max_heap_table_size" => "SELECT '67108864'". Unknown variables are left, so that
     * the query fails.
     *
     * @param string $sql
     * @return string
     */
    private function translateSystemVariables(string $sql): string
    {
        return preg_replace_callback(
            '/@@(?:session\.|global\.)?(\w+)/i',
            function ($matches) {
                $name = strtolower($matches[1]);
                return isset(self::MYSQL_SYSTEM_VARIABLES[$name])
                    ? $this->quote(self::MYSQL_SYSTEM_VARIABLES[$name])
                    : $matches[0];
            },
            rtrim($sql, " \t\n\r;")
        );
    }

    /**
     * Build a query returning the MYSQL_SYSTEM_VARIABLES matching a LIKE pattern, as SHOW VARIABLES does
     *
     * Rows have the MySQL column names Variable_name and Value; unknown variables give no rows.
     *
     * @param string|null $pattern LIKE pattern, null for all variables
     * @return string
     */
    private function getShowVariablesSql(?string $pattern): string
    {
        $regex = $pattern === null ? null : '/^' . strtr(
            preg_quote($pattern, '/'),
            ['%' => '.*', '_' => '.', '\\\\%' => '%', '\\\\_' => '_']
        ) . '$/i';
        $rows = [];
        foreach (self::MYSQL_SYSTEM_VARIABLES as $name => $value) {
            if ($regex === null || preg_match($regex, $name)) {
                $rows[] = sprintf('(%s, %s)', $this->quote($name), $this->quote($value));
            }
        }

        return $rows
            ? sprintf('SELECT * FROM (VALUES %s) AS v("Variable_name", "Value")', implode(', ', $rows))
            : 'SELECT NULL AS "Variable_name", NULL AS "Value" WHERE FALSE';
    }

    /**
     * Translate the MySQL trigger statements Magento uses to save and restore triggers around table changes
     *
     * Code reads triggers with SHOW TRIGGERS LIKE 'table', saves "SQL Original Statement" of SHOW CREATE TRIGGER,
     * drops them with DROP TRIGGER [IF EXISTS] name and later runs the saved statement (catalog rule indexer table
     * swap, multiselect attribute migration).
     *
     * PostgreSQL trigger names are only unique per table (pgloader names every ON UPDATE CURRENT_TIMESTAMP
     * trigger "on_update_current_timestamp"), so SHOW TRIGGERS reports names qualified with the table,
     * "table.trigger", and the other statements accept that form. An unqualified name that matches triggers on
     * several tables is an error instead of a guess. DROP TRIGGER keeps the trigger function, so that the saved
     * CREATE TRIGGER statement (pg_get_triggerdef()) can be run again.
     *
     * @param string $sql
     * @return string the statement unchanged if it is not one of the MySQL forms
     * @throws Zend_Db_Statement_Exception for a missing or ambiguous trigger
     */
    private function translateTriggerStatement(string $sql): string
    {
        if (preg_match(self::MYSQL_SHOW_TRIGGERS_PATTERN, $sql, $matches)) {
            return 'SELECT quote_ident(cl.relname) || \'.\' || quote_ident(tg.tgname) AS "Trigger",'
                . " CONCAT_WS(' OR ', CASE WHEN tg.tgtype::int & 4 <> 0 THEN 'INSERT' END,"
                . " CASE WHEN tg.tgtype::int & 16 <> 0 THEN 'UPDATE' END,"
                . " CASE WHEN tg.tgtype::int & 8 <> 0 THEN 'DELETE' END,"
                . " CASE WHEN tg.tgtype::int & 32 <> 0 THEN 'TRUNCATE' END) AS \"Event\","
                . ' cl.relname AS "Table", pg_get_triggerdef(tg.oid) AS "Statement",'
                . " CASE WHEN tg.tgtype::int & 2 <> 0 THEN 'BEFORE' WHEN tg.tgtype::int & 64 <> 0 THEN 'INSTEAD OF'"
                . " ELSE 'AFTER' END AS \"Timing\""
                . ' FROM pg_trigger AS tg JOIN pg_class AS cl ON cl.oid = tg.tgrelid'
                . ' WHERE NOT tg.tgisinternal AND pg_table_is_visible(cl.oid)'
                . (isset($matches[2]) ? ' AND cl.relname LIKE ' . $this->quote($matches[2]) : '')
                . ' ORDER BY cl.relname, tg.tgname';
        }

        $isCreate = (bool)preg_match(self::MYSQL_SHOW_CREATE_TRIGGER_PATTERN, $sql, $matches);
        $isDrop = !$isCreate && preg_match(self::MYSQL_DROP_TRIGGER_PATTERN, $sql, $matches);
        if (!$isCreate && !$isDrop) {
            return $sql;
        }
        $name = $isCreate ? $matches[1] : $matches[2];
        $triggers = $this->findTriggers($name);
        if (count($triggers) > 1) {
            throw new Zend_Db_Statement_Exception(sprintf(
                'Trigger name %s is ambiguous: it exists on %s; qualify it with the table',
                $name,
                implode(', ', array_column($triggers, 'table_name'))
            ));
        }
        if (!$triggers) {
            if ($isDrop && !empty($matches[1])) {
                return self::NO_OP_QUERY;
            }
            throw new Zend_Db_Statement_Exception(sprintf('Trigger %s does not exist', $name));
        }

        $trigger = reset($triggers);
        return $isCreate
            ? sprintf(
                'SELECT %s AS "Trigger", \'\' AS "sql_mode", pg_get_triggerdef(%d) AS "SQL Original Statement"',
                $this->quote($trigger['tgname']),
                $trigger['oid']
            )
            : sprintf('DROP TRIGGER %s ON %s', $this->quoteIdentifier($trigger['tgname']), $trigger['table_name']);
    }

    /**
     * Build the PostgreSQL counterpart of MySQL CREATE TABLE new LIKE old
     *
     * The copy gets columns, defaults, indexes and constraints except foreign keys, as in MySQL. Auto-increment
     * columns get a sequence of their own, so the old table can be dropped (indexer table swaps): identity
     * columns are copied as such, nextval() defaults of serial columns (pgloader) become identity columns.
     *
     * @param bool $temporary
     * @param bool $ifNotExists
     * @param string $newName
     * @param string $oldName
     * @return string
     */
    private function getCreateTableLikeSql(bool $temporary, bool $ifNotExists, string $newName, string $oldName): string
    {
        $newTable = implode('.', array_map([$this, 'quoteIdentifier'], $this->splitMysqlName($newName)));
        $oldTable = implode('.', array_map([$this, 'quoteIdentifier'], $this->splitMysqlName($oldName)));
        $alterColumn = 'ALTER TABLE ' . $newTable . ' ALTER COLUMN %I ';

        return 'DO $horobyna_like$ DECLARE col record; BEGIN'
            . ($ifNotExists
                ? sprintf(' IF to_regclass(%s) IS NOT NULL THEN RETURN; END IF;', $this->quote($newTable))
                : '')
            . sprintf(' CREATE %sTABLE %s (LIKE %s INCLUDING ALL);', $temporary ? 'TEMPORARY ' : '', $newTable, $oldTable)
            . ' FOR col IN SELECT a.attname FROM pg_attribute AS a'
            . ' JOIN pg_attrdef AS d ON d.adrelid = a.attrelid AND d.adnum = a.attnum'
            . sprintf(' WHERE a.attrelid = to_regclass(%s) AND a.attnum > 0', $this->quote($newTable))
            . " AND NOT a.attisdropped AND a.attnotnull AND a.attidentity = ''"
            . " AND pg_get_expr(d.adbin, d.adrelid) LIKE 'nextval(%' LOOP"
            . sprintf(' EXECUTE format(%s, col.attname);', $this->quote($alterColumn . 'DROP DEFAULT'))
            . sprintf(
                ' EXECUTE format(%s, col.attname);',
                $this->quote($alterColumn . 'ADD GENERATED BY DEFAULT AS IDENTITY')
            )
            . ' END LOOP; END $horobyna_like$';
    }

    /**
     * Split a name as MySQL code writes it into its unquoted parts
     *
     * Unquoted parts are folded to lower case, as PostgreSQL does.
     *
     * @param string $name e.g. db.`table`, "table".trigger
     * @return string[]
     */
    private function splitMysqlName(string $name): array
    {
        preg_match_all('/`([^`]+)`|"((?:[^"]|"")+)"|([\w$]+)/', $name, $parts, PREG_SET_ORDER);

        return array_map(
            fn ($part) => isset($part[3]) ? strtolower($part[3]) : (isset($part[2]) && $part[2] !== ''
                ? str_replace('""', '"', $part[2])
                : $part[1]),
            $parts
        );
    }

    /**
     * Find triggers by a MySQL trigger name, see translateTriggerStatement()
     *
     * A qualifier is a table name (as SHOW TRIGGERS reports it) or a schema name (MySQL database.trigger).
     * Unquoted parts are folded to lower case, as PostgreSQL does.
     *
     * @param string $name
     * @return array[] rows with oid, tgname and table_name (quoted, schema-qualified when not on the search path)
     */
    private function findTriggers(string $name): array
    {
        $parts = $this->splitMysqlName($name);
        $triggerName = array_pop($parts);
        $qualifier = array_pop($parts);

        $sql = 'SELECT tg.oid, tg.tgname, tg.tgrelid::regclass::text AS table_name FROM pg_trigger AS tg'
            . ' JOIN pg_class AS cl ON cl.oid = tg.tgrelid JOIN pg_namespace AS ns ON ns.oid = cl.relnamespace'
            . ' WHERE NOT tg.tgisinternal AND tg.tgname = ?'
            . ($qualifier === null
                ? ' AND pg_table_is_visible(cl.oid)'
                : ' AND ((cl.relname = ? AND pg_table_is_visible(cl.oid)) OR ns.nspname = ?)');
        $bind = $qualifier === null ? [$triggerName] : [$triggerName, $qualifier, $qualifier];

        return $this->fetchAll($sql, $bind);
    }

    /**
     * Replace references to column aliases inside ORDER BY expressions with the aliased expressions
     *
     * MySQL resolves select list aliases inside ORDER BY expressions (e.g. FIELD(status, 2, 3) where status is
     * a computed column), PostgreSQL only accepts an alias as the whole ORDER BY item.
     *
     * @param Select $select
     * @return Select a modified clone, or the select itself when nothing is replaced
     */
    private function inlineOrderAliases(Select $select): Select
    {
        $orders = $select->getPart(Select::ORDER);
        if (!$orders) {
            return $select;
        }
        $aliases = $this->getColumnAliasExpressions($select);
        if (!$aliases) {
            return $select;
        }
        $pattern = '/(?<![\w."`$\'])("?)\b(' . implode('|', array_map('preg_quote', array_keys($aliases)))
            . ')\b\1(?![\w"(.\'])/';
        $inline = function (\Zend_Db_Expr $expression) use ($pattern, $aliases) {
            $sql = $expression->__toString();
            if (isset($aliases[trim($sql, ' "')])) {
                return $expression;
            }
            $inlined = preg_replace_callback($pattern, fn ($matches) => '(' . $aliases[$matches[2]] . ')', $sql);
            return $inlined === $sql ? $expression : new \Zend_Db_Expr($inlined);
        };

        $isChanged = false;
        foreach ($orders as $key => $order) {
            if ($order instanceof \Zend_Db_Expr) {
                $orders[$key] = $inline($order);
            } elseif (is_array($order) && $order[0] instanceof \Zend_Db_Expr) {
                $orders[$key] = [$inline($order[0]), $order[1]];
            }
            $isChanged = $isChanged || $orders[$key] !== $order;
        }
        if (!$isChanged) {
            return $select;
        }

        $select = clone $select;
        $select->setPart(Select::ORDER, $orders);
        return $select;
    }

    /**
     * Make a grouped select valid when it selects columns that are not grouped
     *
     * MySQL (without ONLY_FULL_GROUP_BY) returns a value of any row of the group for such columns, PostgreSQL
     * requires them to be grouped or aggregated. PostgreSQL accepts table.* when the primary key of the table is
     * grouped (one row per group), e.g. product collections grouped by e.entity_id. Then the other ungrouped
     * columns and expressions are wrapped in MIN(), a value of the group as well; a condition is aggregated as
     * an integer, which is also what MySQL returns for it.
     * A select that only picks one row per group (no aggregates, e.g. SELECT t.* ... GROUP BY attribute_id) and
     * is not ordered becomes SELECT DISTINCT ON (group).
     * Subselects in the FROM part are handled the same way. Selects with HAVING or UNION are left as they are.
     *
     * @param Select $select
     * @return Select|string the select, or its SQL when it was rewritten with DISTINCT ON
     */
    private function fixUngroupedColumns(Select $select)
    {
        $from = $select->getPart(Select::FROM);
        $isFromChanged = false;
        foreach ($from as $correlationName => $table) {
            if ($table['tableName'] instanceof Select) {
                $fixed = $this->fixUngroupedColumns($table['tableName']);
                if ($fixed !== $table['tableName']) {
                    $from[$correlationName]['tableName'] = is_string($fixed)
                        ? new \Zend_Db_Expr('(' . $fixed . ')')
                        : $fixed;
                    $isFromChanged = true;
                }
            }
        }
        if ($isFromChanged) {
            $select = clone $select;
            $select->setPart(Select::FROM, $from);
        }

        $groups = $select->getPart(Select::GROUP);
        if (!$groups || $select->getPart(Select::HAVING) || $select->getPart(Select::UNION)) {
            return $select;
        }
        $normalize = fn ($name) => strtolower(str_replace(['"', '`', ' '], '', (string)$name));
        $grouped = array_map($normalize, $groups);
        $isGrouped = function (?string $correlationName, string $column) use ($grouped, $normalize) {
            $name = $normalize($column);
            return in_array($name, $grouped, true)
                || ($correlationName && in_array($normalize($correlationName) . '.' . $name, $grouped, true));
        };
        $aggregatePattern = '/\b(COUNT|SUM|MIN|MAX|AVG|GROUP_CONCAT|STRING_AGG|ARRAY_AGG|BIT_AND|BIT_OR|BOOL_AND|BOOL_OR'
            . '|STDDEV\w*|VARIANCE|VAR_\w+)\s*\(/i';

        $columns = $select->getPart(Select::COLUMNS);
        $hasAggregate = false;
        $hasUngrouped = false;
        $areStarsGrouped = true;
        foreach ($columns as list($correlationName, $column)) {
            if ($column instanceof \Zend_Db_Expr) {
                $isAggregate = (bool)preg_match($aggregatePattern, $column->__toString());
                $hasAggregate = $hasAggregate || $isAggregate;
                $hasUngrouped = $hasUngrouped || !$isAggregate;
            } elseif ($column === '*') {
                $hasUngrouped = true;
                $areStarsGrouped = $areStarsGrouped && $this->isPrimaryKeyGrouped($from[$correlationName] ?? null, $correlationName, $isGrouped);
            } elseif (!$isGrouped($correlationName, $column)) {
                $hasUngrouped = true;
            }
        }
        if (!$hasUngrouped) {
            return $select;
        }

        if (!$areStarsGrouped) {
            if ($hasAggregate || $select->getPart(Select::ORDER)) {
                return $select;
            }
            $distinctOn = [];
            foreach ($groups as $group) {
                $distinctOn[] = $group instanceof \Zend_Db_Expr ? $group->__toString() : $this->quoteIdentifier($group, true);
            }
            $select = clone $select;
            $select->reset(Select::GROUP);
            $select->reset(Select::DISTINCT);
            return preg_replace(
                '/^\s*SELECT\b/i',
                'SELECT DISTINCT ON (' . implode(', ', $distinctOn) . ')',
                $select->assemble(),
                1
            );
        }

        $isChanged = false;
        foreach ($columns as $key => list($correlationName, $column, $alias)) {
            if ($column === '*') {
                continue;
            }
            if ($column instanceof \Zend_Db_Expr) {
                $expression = $column->__toString();
                if (preg_match($aggregatePattern, $expression) || $alias === null) {
                    continue;
                }
                $aggregated = $this->functionMapper->isCondition($expression)
                    ? sprintf('MIN(((%s))::integer)', $expression)
                    : sprintf('MIN(%s)', $expression);
            } elseif ($isGrouped($correlationName, $column)) {
                continue;
            } else {
                $aggregated = sprintf(
                    'MIN(%s)',
                    $this->quoteIdentifier($correlationName ? [$correlationName, $column] : $column)
                );
            }
            $columns[$key] = [null, new \Zend_Db_Expr($aggregated), $alias ?: $column];
            $isChanged = true;
        }
        if ($isChanged) {
            $select = clone $select;
            $select->setPart(Select::COLUMNS, $columns);
        }
        return $select;
    }

    /**
     * Check whether the primary key of a table of a select is grouped
     *
     * @param array|null $table FROM part entry
     * @param string|null $correlationName
     * @param callable $isGrouped
     * @return bool
     */
    private function isPrimaryKeyGrouped($table, $correlationName, callable $isGrouped): bool
    {
        if (!$table || !is_string($table['tableName'])) {
            return false;
        }
        $primary = $this->getIndexList($table['tableName'], $table['schema'])['PRIMARY']['COLUMNS_LIST'] ?? [];
        foreach ($primary as $column) {
            if (!$isGrouped($correlationName, $column)) {
                return false;
            }
        }
        return (bool)$primary;
    }

    /**
     * Wrap ORDER BY columns of a grouped select that are not grouped in MIN()
     *
     * MySQL (without ONLY_FULL_GROUP_BY) orders by a value of any row of the group, PostgreSQL requires ungrouped
     * columns to be aggregated (e.g. attributes grouped by attribute_id and ordered by the joined sort_order).
     * MIN() picks a value of the group as well; for a column with one value per group it is that value.
     *
     * @param Select $select
     * @return Select a modified clone, or the select itself when nothing is wrapped
     */
    private function aggregateUngroupedOrders(Select $select): Select
    {
        $groups = $select->getPart(Select::GROUP);
        $orders = $select->getPart(Select::ORDER);
        if (!$groups || !$orders) {
            return $select;
        }
        $normalize = fn ($name) => strtolower(str_replace(['"', '`', ' '], '', (string)$name));
        $grouped = array_map($normalize, $groups);
        // Output column names: aliases, selected columns and the columns of selected table.* (ORDER BY code
        // with code from e.* refers to that output column and must not become an aggregate)
        $aliases = [];
        $from = $select->getPart(Select::FROM);
        foreach ($select->getPart(Select::COLUMNS) as list($correlationName, $column, $alias)) {
            if ($column === '*') {
                $table = $from[$correlationName] ?? null;
                if ($table && is_string($table['tableName'])) {
                    foreach (array_keys($this->describeTable($table['tableName'], $table['schema'])) as $name) {
                        $aliases[$normalize($name)] = true;
                    }
                }
                continue;
            }
            $aliases[$normalize($alias ?? (is_string($column) ? $column : ''))] = true;
        }

        $isChanged = false;
        foreach ($orders as $key => $order) {
            // Collections order by Zend_Db_Expr('column ASC'); other expressions are left as they are
            if ($order instanceof \Zend_Db_Expr
                && preg_match('/^\s*([\w."`]+)(?:\s+(ASC|DESC))?\s*$/i', $order->__toString(), $matches)
            ) {
                $order = [str_replace(['"', '`'], '', $matches[1]), strtoupper($matches[2] ?? Select::SQL_ASC)];
            }
            if (!is_array($order) || !is_string($order[0])) {
                continue;
            }
            $column = $normalize($order[0]);
            $isGrouped = in_array($column, $grouped, true);
            if (!$isGrouped && strpos($column, '.') === false) {
                foreach ($grouped as $groupColumn) {
                    $isGrouped = $isGrouped || substr($groupColumn, -strlen($column) - 1) === '.' . $column;
                }
            }
            if ($isGrouped || isset($aliases[$column]) || !preg_match('/^[\w.]+$/', $column)) {
                continue;
            }
            $orders[$key] = [new \Zend_Db_Expr(sprintf('MIN(%s)', $this->quoteIdentifier($order[0]))), $order[1]];
            $isChanged = true;
        }
        if (!$isChanged) {
            return $select;
        }

        $select = clone $select;
        $select->setPart(Select::ORDER, $orders);
        return $select;
    }

    /**
     * Add ORDER BY expressions of a SELECT DISTINCT to its columns
     *
     * PostgreSQL requires ORDER BY expressions of SELECT DISTINCT to appear in the select list, MySQL does not
     * (e.g. product collections ordered by FIELD(e.sku, ...)). Each expression is selected as mage_order_N and
     * the select is ordered by that alias. The expressions depend on the selected rows, so DISTINCT still
     * returns the same rows. Aliases inside the expressions are already replaced by inlineOrderAliases().
     *
     * @param Select $select
     * @return Select
     */
    private function selectOrderExpressionsForDistinct(Select $select): Select
    {
        if (!$select->getPart(Select::DISTINCT)
            || !$select->getPart(Select::ORDER)
            || $select->getPart(Select::GROUP)
            || $select->getPart(Select::UNION)
        ) {
            return $select;
        }

        $select = clone $select;
        $orders = [];
        $count = 0;
        foreach ($select->getPart(Select::ORDER) as $order) {
            if ($order instanceof \Zend_Db_Expr) {
                // A Zend_Db_Expr order keeps its direction inside the expression
                $expression = trim($order->__toString());
                $direction = null;
                if (preg_match('/^(.*?)\s*\b(ASC|DESC)$/is', $expression, $matches)) {
                    list(, $expression, $direction) = $matches;
                }
            } elseif (is_array($order) && $order[0] instanceof \Zend_Db_Expr) {
                list($expression, $direction) = [$order[0]->__toString(), $order[1]];
            } else {
                $orders[] = $order;
                continue;
            }
            $alias = 'mage_order_' . ++$count;
            $select->columns([$alias => new \Zend_Db_Expr($expression)]);
            $orders[] = [$alias, strtoupper($direction ?? Select::SQL_ASC)];
        }
        $select->setPart(Select::ORDER, $orders);

        return $select;
    }

    /**
     * Get SQL of the aliased columns of a select, alias => expression
     *
     * @param Select $select
     * @return string[]
     */
    private function getColumnAliasExpressions(Select $select): array
    {
        $aliases = [];
        foreach ($select->getPart(Select::COLUMNS) as list($correlationName, $column, $alias)) {
            if ($alias === null || $alias === '') {
                continue;
            }
            if ($column instanceof \Zend_Db_Expr) {
                $aliases[$alias] = $column->__toString();
            } elseif ($column !== $alias || $correlationName) {
                $aliases[$alias] = $this->quoteIdentifier($correlationName ? [$correlationName, $column] : $column);
            }
        }
        return $aliases;
    }

    /**
     * Translate MySQL identifiers, leaving literals, quoted identifiers and comments intact
     *
     * - `identifiers` become "identifiers": Magento hardcodes backticks in its select renderers and raw queries;
     * - reserved words used as unquoted table aliases (user.user_id) are quoted: MySQL accepts them,
     *   PostgreSQL never accepts a reserved word in front of a dot.
     *
     * @param string $sql
     * @return string
     */
    private function translateIdentifiers(string $sql): string
    {
        $qualifierPattern = '(?<![\w."`$])\b(' . implode('|', self::RESERVED_WORDS) . ')(?=\.[\w"*])';
        if (strpos($sql, '`') === false && !preg_match('/' . $qualifierPattern . '/i', $sql)) {
            return $sql;
        }

        return preg_replace_callback(
            '/(?<![\w])[Ee]\'(?:\\\\.|[^\'\\\\]|\'\')*\''   // escape string literal
            . '|\'(?:[^\']|\'\')*\''                       // string literal
            . '|"(?:[^"]|"")*"'                            // quoted identifier
            . '|--[^\n]*'                                  // line comment
            . '|\/\*.*?\*\/'                               // block comment
            . '|\$((?:[A-Za-z_]\w*)?)\$.*?\$\1\$'          // dollar-quoted string
            . '|`((?:[^`]|``)*)`'                          // MySQL identifier
            . '|' . $qualifierPattern . '/is',             // reserved word as table alias
            function ($matches) {
                if (isset($matches[3]) && $matches[3] !== '') {
                    return '"' . strtolower($matches[3]) . '"';
                }
                if ($matches[0][0] !== '`') {
                    return $matches[0];
                }
                return '"' . str_replace(['``', '"'], ['`', '""'], $matches[2]) . '"';
            },
            $sql
        );
    }

    /**
     * Sets (removes) query hook.
     *
     * $hook must be either array with 'object' and 'method' entries, or null to remove hook.
     * Previous hook is returned.
     *
     * @param array $hook
     * @return array|null
     */
    public function setQueryHook($hook)
    {
        $prev = $this->_queryHook;
        $this->_queryHook = $hook;
        return $prev;
    }

    /**
     * Split multi statement query
     *
     * @param string $sql
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function _splitMultiQuery($sql)
    {
        $parts = preg_split(
            '#(;|\'|"|\\\\|//|--|\n|/\*|\*/)#',
            $sql,
            -1,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
        );

        $q      = false;
        $c      = false;
        $stmts  = [];
        $s      = '';

        foreach ($parts as $i => $part) {
            // strings
            if (($part === "'" || $part === '"') && ($i === 0 || $parts[$i-1] !== '\\')) {
                if ($q === false) {
                    $q = $part;
                } elseif ($q === $part) {
                    $q = false;
                }
            }

            // single line comments
            if (($part === '//' || $part === '--') && ($i === 0 || $parts[$i-1] === "\n")) {
                $c = $part;
            } elseif ($part === "\n" && ($c === '//' || $c === '--')) {
                $c = false;
            }

            // multi line comments
            if ($part === '/*' && $c === false) {
                $c = '/*';
            } elseif ($part === '*/' && $c === '/*') {
                $c = false;
            }

            // statements
            if ($part === ';' && $q === false && $c === false) {
                if (trim($s) !== '') {
                    $stmts[] = trim($s);
                    $s = '';
                }
            } else {
                $s .= $part;
            }
        }
        if (trim($s) !== '') {
            $stmts[] = trim($s);
        }

        return $stmts;
    }

    /**
     * Quote a raw string
     *
     * Numbers are quoted as literals too: PostgreSQL casts an untyped literal to the type of the column it is
     * compared with, while a number compared with a text column is an error ("operator does not exist").
     *
     * @param mixed $value
     * @return string
     */
    protected function _quote($value)
    {
        if (is_bool($value)) {
            $value = (int)$value;
        }
        if (is_int($value) || is_float($value)) {
            return "'" . $value . "'";
        }
        return \Zend_Db_Adapter_Pdo_Abstract::_quote($value);
    }

    /**
     * Drop the Foreign Key from table
     *
     * @param string $tableName
     * @param string $fkName
     * @param string $schemaName
     * @return $this
     */
    public function dropForeignKey($tableName, $fkName, $schemaName = null)
    {
        $foreignKeys = $this->getForeignKeys($tableName, $schemaName);
        $fkName = $fkName !== null ? strtoupper($this->truncateIdentifier($fkName)) : '';
        if (substr($fkName, 0, 3) == 'FK_') {
            $fkName = substr($fkName, 3);
        }
        foreach ([$fkName, 'FK_' . $fkName] as $key) {
            if (isset($foreignKeys[$key])) {
                $sql = sprintf(
                    'ALTER TABLE %s DROP CONSTRAINT %s',
                    $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
                    $this->quoteIdentifier($foreignKeys[$key]['FK_NAME'])
                );
                $this->resetDdlCache($tableName, $schemaName);
                $this->rawQuery($sql);
                $this->getSchemaListener()->dropForeignKey($tableName, $fkName);
            }
        }
        return $this;
    }

    /**
     * Prepare table before add constraint foreign key
     *
     * Rows with a NULL key are kept, since they do not violate the constraint.
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $refTableName
     * @param string $refColumnName
     * @param string $onDelete
     * @return $this
     */
    public function purgeOrphanRecords(
        $tableName,
        $columnName,
        $refTableName,
        $refColumnName,
        $onDelete = AdapterInterface::FK_ACTION_CASCADE
    ) {
        $onDelete = strtoupper($onDelete);
        $orphanCondition = sprintf(
            'p.%1$s IS NOT NULL AND NOT EXISTS (SELECT 1 FROM %2$s AS r WHERE r.%3$s = p.%1$s)',
            $this->quoteIdentifier($columnName),
            $this->quoteIdentifier($refTableName),
            $this->quoteIdentifier($refColumnName)
        );
        if ($onDelete == AdapterInterface::FK_ACTION_CASCADE
            || $onDelete == AdapterInterface::FK_ACTION_RESTRICT
        ) {
            $sql = sprintf(
                'DELETE FROM %s AS p WHERE %s',
                $this->quoteIdentifier($tableName),
                $orphanCondition
            );
            $this->rawQuery($sql);
        } elseif ($onDelete == AdapterInterface::FK_ACTION_SET_NULL) {
            $sql = sprintf(
                'UPDATE %s AS p SET %s = NULL WHERE %s',
                $this->quoteIdentifier($tableName),
                $this->quoteIdentifier($columnName),
                $orphanCondition
            );
            $this->rawQuery($sql);
        }

        return $this;
    }

    /**
     * Check does table column exist
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $schemaName
     * @return bool
     */
    public function tableColumnExists($tableName, $columnName, $schemaName = null)
    {
        $describe = $this->describeTable($tableName, $schemaName);
        foreach ($describe as $column) {
            if ($column['COLUMN_NAME'] == $columnName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Adds new column to table.
     *
     * Generally $defintion must be array with column data to keep this call cross-DB compatible.
     * Using string as $definition is allowed only for concrete DB adapter.
     * Adds primary key if needed.
     * A NOT NULL column without default is filled with the type's zero value, as MySQL does.
     *
     * @param string $tableName
     * @param string $columnName
     * @param array|string $definition string specific or universal array DB Server definition
     * @param string $schemaName
     * @return true|\Zend_Db_Statement_Pdo
     * @throws \Zend_Db_Exception
     */
    public function addColumn($tableName, $columnName, $definition, $schemaName = null)
    {
        $this->getSchemaListener()->addColumn($tableName, $columnName, $definition);
        if ($this->tableColumnExists($tableName, $columnName, $schemaName)) {
            return true;
        }

        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $column = $this->quoteIdentifier($columnName);
        $statements = [];
        if (is_array($definition)) {
            $definition = array_change_key_case($definition, CASE_UPPER);
            if (empty($definition['COMMENT'])) {
                throw new \Zend_Db_Exception("Impossible to create a column without comment.");
            }
            $spec = $this->getColumnSpec($definition);
            $fillDefault = !$spec['nullable'] && $spec['default'] === null && !$spec['identity'];
            if ($fillDefault) {
                $spec['default'] = $this->getZeroValueSql($spec['type']);
            }
            $statements[] = sprintf(
                'ALTER TABLE %s ADD COLUMN %s %s%s',
                $table,
                $column,
                $this->renderColumnSpec($spec),
                !empty($definition['PRIMARY']) ? sprintf(', ADD PRIMARY KEY (%s)', $column) : ''
            );
            if ($fillDefault) {
                $statements[] = sprintf('ALTER TABLE %s ALTER COLUMN %s DROP DEFAULT', $table, $column);
            }
            $statements[] = $this->getColumnCommentSql($tableName, $columnName, $spec['comment'], $schemaName);
            if ($spec['on_update']) {
                $statements = array_merge(
                    $statements,
                    $this->getOnUpdateTriggerStatements($tableName, $columnName, $schemaName)
                );
            }
        } else {
            $statements[] = sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition);
        }

        $result = $this->runStatements($statements);
        $this->resetDdlCache($tableName, $schemaName);

        return $result;
    }

    /**
     * Delete table column
     *
     * PostgreSQL drops every index and foreign key that uses the column. Multi-column indexes are created
     * again without the column, as MySQL keeps them.
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $schemaName
     * @return true|\Zend_Db_Statement_Pdo
     */
    public function dropColumn($tableName, $columnName, $schemaName = null)
    {
        if (!$this->tableColumnExists($tableName, $columnName, $schemaName)) {
            return true;
        }
        $this->getSchemaListener()->dropColumn($tableName, $columnName);

        foreach ($this->getForeignKeys($tableName, $schemaName) as $fkProp) {
            if ($fkProp['COLUMN_NAME'] == $columnName) {
                $this->getSchemaListener()->dropForeignKey($tableName, $fkProp['FK_NAME']);
            }
        }

        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $statements = [];
        $recreate = [];
        $indexes = $this->getIndexList($tableName, $schemaName);
        foreach ($indexes as $idxData) {
            $idxColumns = $idxData['COLUMNS_LIST'];
            $idxColumnKey = array_search($columnName, $idxColumns);
            if ($idxColumnKey === false) {
                continue;
            }
            unset($idxColumns[$idxColumnKey]);
            $idxColumns = array_values($idxColumns);
            if (empty($idxColumns)) {
                $this->getSchemaListener()->dropIndex($tableName, $idxData['KEY_NAME'], 'index');
                continue;
            }
            /* do not recreate an index that would coincide with the existing index by indexed columns */
            foreach ($indexes as $otherIdx) {
                if ($otherIdx['COLUMNS_LIST'] === $idxColumns) {
                    continue 2;
                }
            }
            $recreate[] = [$idxData, $idxColumns];
        }

        $statements[] = sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $this->quoteIdentifier($columnName));
        foreach ($recreate as [$idxData, $idxColumns]) {
            $statements[] = $this->getAddIndexSql(
                $tableName,
                $idxData['KEY_NAME'],
                $idxColumns,
                $idxData['INDEX_TYPE'],
                $schemaName
            );
        }
        $statements[] = $this->getDropOnUpdateTriggerSql($tableName, $columnName, $schemaName);

        $result = $this->runStatements($statements);
        $this->resetDdlCache($tableName, $schemaName);

        return $result;
    }

    /**
     * Retrieve index information by indexed columns or return NULL, if there is no index for a column list
     *
     * @param string $tableName
     * @param array $columns
     * @param string|null $schemaName
     * @return array|null
     */
    protected function _getIndexByColumns($tableName, array $columns, $schemaName)
    {
        foreach ($this->getIndexList($tableName, $schemaName) as $idxData) {
            if ($idxData['COLUMNS_LIST'] === $columns) {
                return $idxData;
            }
        }
        return null;
    }

    /**
     * Change the column name and definition
     *
     * For change definition of column - use modifyColumn
     *
     * @param string $tableName
     * @param string $oldColumnName
     * @param string $newColumnName
     * @param array|string $definition
     * @param boolean $flushData flush table statistic
     * @param string $schemaName
     * @return \Zend_Db_Statement_Pdo
     * @throws \Zend_Db_Exception
     */
    public function changeColumn(
        $tableName,
        $oldColumnName,
        $newColumnName,
        $definition,
        $flushData = false,
        $schemaName = null
    ) {
        $this->getSchemaListener()->changeColumn(
            $tableName,
            $oldColumnName,
            $newColumnName,
            $definition
        );
        if (!$this->tableColumnExists($tableName, $oldColumnName, $schemaName)) {
            throw new \Zend_Db_Exception(
                sprintf(
                    'Column "%s" does not exist in table "%s".',
                    $oldColumnName,
                    $tableName
                )
            );
        }

        $statements = [];
        if ($oldColumnName !== $newColumnName) {
            $statements[] = sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
                $this->quoteIdentifier($oldColumnName),
                $this->quoteIdentifier($newColumnName)
            );
            $statements[] = $this->getDropOnUpdateTriggerSql($tableName, $oldColumnName, $schemaName);
        }
        $current = $this->describeTable($tableName, $schemaName)[$oldColumnName];
        $statements = array_merge(
            $statements,
            $this->getModifyColumnStatements($tableName, $newColumnName, $definition, $current, $schemaName)
        );

        $result = $this->runStatements($statements);

        if ($flushData) {
            $this->showTableStatus($tableName, $schemaName);
        }
        $this->resetDdlCache($tableName, $schemaName);

        return $result;
    }

    /**
     * Modify the column definition
     *
     * @param string $tableName
     * @param string $columnName
     * @param array|string $definition
     * @param boolean $flushData
     * @param string $schemaName
     * @return $this
     * @throws \Zend_Db_Exception
     */
    public function modifyColumn($tableName, $columnName, $definition, $flushData = false, $schemaName = null)
    {
        $this->getSchemaListener()->modifyColumn(
            $tableName,
            $columnName,
            $definition
        );
        if (!$this->tableColumnExists($tableName, $columnName, $schemaName)) {
            throw new \Zend_Db_Exception(sprintf('Column "%s" does not exist in table "%s".', $columnName, $tableName));
        }

        $current = $this->describeTable($tableName, $schemaName)[$columnName];
        $this->runStatements(
            $this->getModifyColumnStatements($tableName, $columnName, $definition, $current, $schemaName)
        );
        if ($flushData) {
            $this->showTableStatus($tableName, $schemaName);
        }
        $this->resetDdlCache($tableName, $schemaName);

        return $this;
    }

    /**
     * Build the statements that bring a column to the new definition
     *
     * A string definition is a PostgreSQL column definition: a type, optionally followed by NULL / NOT NULL
     * and DEFAULT.
     *
     * @param string $tableName
     * @param string $columnName
     * @param array|string $definition
     * @param array $current describeTable() data of the column
     * @param string|null $schemaName
     * @return string[]
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function getModifyColumnStatements($tableName, $columnName, $definition, array $current, $schemaName)
    {
        if (is_array($definition)) {
            $spec = $this->getColumnSpec($definition);
        } else {
            $spec = $this->parseColumnDefinition((string)$definition);
        }

        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $column = $this->quoteIdentifier($columnName);
        $alter = sprintf('ALTER TABLE %s ALTER COLUMN %s ', $table, $column);

        $statements = [];
        if ($current['IDENTITY'] && !$spec['identity']) {
            // Either a SQL standard identity column or a serial one, whose default is nextval()
            $statements[] = $this->isIdentityColumn($tableName, $columnName, $schemaName)
                ? $alter . 'DROP IDENTITY IF EXISTS'
                : $alter . 'DROP DEFAULT';
        } elseif (!$current['IDENTITY']) {
            $statements[] = $alter . 'DROP DEFAULT';
        }
        $statements[] = $alter . sprintf('TYPE %1$s USING %2$s::%1$s', $spec['type'], $column);
        if ($spec['default'] !== null && !$spec['identity']) {
            $statements[] = $alter . 'SET DEFAULT ' . $spec['default'];
        }
        if ($spec['nullable'] !== null) {
            $statements[] = $alter . ($spec['nullable'] && !$spec['identity'] ? 'DROP NOT NULL' : 'SET NOT NULL');
        }
        if ($spec['identity'] && !$current['IDENTITY']) {
            $statements[] = $alter . 'SET NOT NULL';
            $statements[] = $alter . 'ADD GENERATED BY DEFAULT AS IDENTITY';
            $statements[] = $this->getSyncSequenceSql($tableName, $columnName, $schemaName);
        }
        if (is_array($definition)) {
            $statements[] = $this->getColumnCommentSql($tableName, $columnName, $spec['comment'], $schemaName);
            if ($spec['on_update']) {
                $statements = array_merge(
                    $statements,
                    $this->getOnUpdateTriggerStatements($tableName, $columnName, $schemaName)
                );
            } else {
                $statements[] = $this->getDropOnUpdateTriggerSql($tableName, $columnName, $schemaName);
            }
        }

        return $statements;
    }

    /**
     * Split a PostgreSQL column definition string into type, nullability and default
     *
     * MySQL types are translated (see translateMysqlType()): setup passes e.g. "mediumtext".
     *
     * @param string $definition
     * @return array
     */
    private function parseColumnDefinition(string $definition): array
    {
        $spec = $this->parsePgsqlColumnDefinition($definition);
        $spec['type'] = $this->translateMysqlType($spec['type']);
        return $spec;
    }

    /**
     * Translate a MySQL column type to the PostgreSQL one the adapter creates (see getColumnSpec())
     *
     * Display widths are dropped, unsigned integers are widened, text and binary types become text and bytea.
     * Other types are returned as is.
     *
     * @param string $type e.g. "int(10) unsigned", "mediumtext", "varbinary(255)"
     * @return string
     */
    private function translateMysqlType(string $type): string
    {
        if (!preg_match('/^(\w+)\s*(\(\s*\d+\s*(?:,\s*\d+\s*)?\))?(\s+unsigned)?(\s+zerofill)?$/i', trim($type), $matches)) {
            return $type;
        }
        $baseType = strtolower($matches[1]);
        $unsigned = !empty($matches[3]);
        switch ($baseType) {
            case 'tinyint':
                return 'smallint';
            case 'smallint':
                return $unsigned ? 'integer' : 'smallint';
            case 'mediumint':
                return 'integer';
            case 'int':
            case 'integer':
                return $unsigned ? 'bigint' : 'integer';
            case 'bigint':
                return 'bigint';
            case 'float':
            case 'double':
            case 'real':
                return 'double precision';
            case 'decimal':
                return 'numeric' . ($matches[2] ?? '');
            case 'tinytext':
            case 'mediumtext':
            case 'longtext':
                return 'text';
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
            case 'binary':
            case 'varbinary':
                return 'bytea';
            case 'datetime':
                return 'timestamp';
        }
        return $type;
    }

    /**
     * Split a PostgreSQL column definition string into type, nullability and default
     *
     * @param string $definition
     * @return array
     */
    private function parsePgsqlColumnDefinition(string $definition): array
    {
        $spec = [
            'type'      => trim($definition),
            'nullable'  => null,
            'default'   => null,
            'identity'  => false,
            'comment'   => '',
            'on_update' => false,
        ];
        if (preg_match('/^(.*?)\s+(?=(?:NOT\s+)?NULL\b|DEFAULT\b)(.*)$/is', $definition, $matches)) {
            $spec['type'] = trim($matches[1]);
            $rest = $matches[2];
            if (preg_match('/\bNOT\s+NULL\b/i', $rest)) {
                $spec['nullable'] = false;
            } elseif (preg_match('/(?:^|\s)NULL\b/i', $rest)) {
                $spec['nullable'] = true;
            }
            if (preg_match('/\bDEFAULT\s+(.+?)(?:\s+(?:NOT\s+)?NULL\b.*)?$/is', $rest, $defaultMatch)) {
                $spec['default'] = trim($defaultMatch[1]);
            }
        }
        return $spec;
    }

    /**
     * Show table status
     *
     * Returns the keys of MySQL SHOW TABLE STATUS that have a PostgreSQL counterpart; others are null.
     *
     * @param string $tableName
     * @param string $schemaName
     * @return array|false
     */
    public function showTableStatus($tableName, $schemaName = null)
    {
        $sql = sprintf(
            'SELECT c.relname, GREATEST(c.reltuples, 0)::bigint AS rows, pg_relation_size(c.oid) AS data_length,'
            . ' pg_indexes_size(c.oid) AS index_length, obj_description(c.oid, \'pg_class\') AS comment'
            . ' FROM pg_class AS c WHERE c.oid = %s',
            $this->getRegclassSql($tableName, $schemaName)
        );
        $row = $this->rawFetchRow($sql);
        if (!$row) {
            return false;
        }

        $autoIncrement = null;
        $sequence = $this->getIdentitySequence($tableName, $schemaName);
        if ($sequence !== null) {
            $state = $this->fetchRow(sprintf('SELECT last_value, is_called::int AS is_called FROM %s', $sequence));
            $autoIncrement = $state['is_called'] ? $state['last_value'] + 1 : (int)$state['last_value'];
        }

        return [
            'Name'            => $row['relname'],
            'Engine'          => null,
            'Version'         => null,
            'Row_format'      => null,
            'Rows'            => $row['rows'],
            'Avg_row_length'  => $row['rows'] > 0 ? (int)($row['data_length'] / $row['rows']) : 0,
            'Data_length'     => $row['data_length'],
            'Max_data_length' => 0,
            'Index_length'    => $row['index_length'],
            'Data_free'       => 0,
            'Auto_increment'  => $autoIncrement,
            'Create_time'     => null,
            'Update_time'     => null,
            'Check_time'      => null,
            'Collation'       => null,
            'Checksum'        => null,
            'Create_options'  => '',
            'Comment'         => (string)$row['comment'],
        ];
    }

    /**
     * Retrieve the foreign keys descriptions for a table.
     *
     * The return value is an associative array keyed by the UPPERCASE foreign key,
     * as returned by the RDBMS.
     *
     * The value of each array element is an associative array
     * with the following keys:
     *
     * FK_NAME          => string; original foreign key name
     * SCHEMA_NAME      => string; name of database or schema
     * TABLE_NAME       => string;
     * COLUMN_NAME      => string; column name
     * REF_SCHEMA_NAME  => string; name of reference database or schema
     * REF_TABLE_NAME   => string; reference table name
     * REF_COLUMN_NAME  => string; reference column name
     * ON_DELETE        => string; action type on delete row
     * ON_UPDATE        => string; action type on update row
     *
     * @param string $tableName
     * @param string $schemaName
     * @return array
     */
    public function getForeignKeys($tableName, $schemaName = null)
    {
        $cacheKey = $this->_getTableName($tableName, $schemaName);
        $ddl = $this->loadDdlCache($cacheKey, self::DDL_FOREIGN_KEY);
        if ($ddl === false) {
            $ddl = [];
            $sql = sprintf(
                'SELECT co.conname, rn.nspname AS ref_schema, rc.relname AS ref_table,'
                . ' a.attname AS column_name, ra.attname AS ref_column, co.confdeltype, co.confupdtype'
                . ' FROM pg_constraint AS co'
                . ' JOIN pg_class AS rc ON rc.oid = co.confrelid'
                . ' JOIN pg_namespace AS rn ON rn.oid = rc.relnamespace'
                . ' JOIN pg_attribute AS a ON a.attrelid = co.conrelid AND a.attnum = co.conkey[1]'
                . ' JOIN pg_attribute AS ra ON ra.attrelid = co.confrelid AND ra.attnum = co.confkey[1]'
                . ' WHERE co.conrelid = %s AND co.contype = \'f\''
                . ' ORDER BY co.conname',
                $this->getRegclassSql($tableName, $schemaName)
            );
            foreach ($this->fetchAll($sql) as $row) {
                $ddl[strtoupper($row['conname'])] = [
                    'FK_NAME'           => $row['conname'],
                    'SCHEMA_NAME'       => $schemaName,
                    'TABLE_NAME'        => $tableName,
                    'COLUMN_NAME'       => $row['column_name'],
                    'REF_SHEMA_NAME'    => $schemaName !== null ? $row['ref_schema'] : null,
                    'REF_SCHEMA_NAME'   => $schemaName !== null ? $row['ref_schema'] : null,
                    'REF_TABLE_NAME'    => $row['ref_table'],
                    'REF_COLUMN_NAME'   => $row['ref_column'],
                    'ON_DELETE'         => self::FK_ACTION_MAP[$row['confdeltype']] ?? '',
                    'ON_UPDATE'         => self::FK_ACTION_MAP[$row['confupdtype']] ?? '',
                ];
            }

            $this->saveDdlCache($cacheKey, self::DDL_FOREIGN_KEY, $ddl);
        }

        return $ddl;
    }

    /**
     * Retrieve the foreign keys tree for all tables
     *
     * @return array
     */
    public function getForeignKeysTree()
    {
        $tree = [];
        foreach ($this->listTables() as $table) {
            foreach ($this->getForeignKeys($table) as $key) {
                $tree[$table][$key['COLUMN_NAME']] = $key;
            }
        }

        return $tree;
    }

    /**
     * Modify tables, used for upgrade process
     *
     * Change columns definitions, reset foreign keys, change tables comments and engines.
     *
     * The value of each array element is an associative array
     * with the following keys:
     *
     * columns => array; list of columns definitions
     * comment => string; table comment
     * engine  => string; table engine
     *
     * @param array $tables
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function modifyTables($tables)
    {
        $foreignKeys = $this->getForeignKeysTree();
        foreach ($tables as $table => $tableData) {
            if (!$this->isTableExists($table)) {
                continue;
            }
            foreach ($tableData['columns'] as $column => $columnDefinition) {
                if (!$this->tableColumnExists($table, $column)) {
                    continue;
                }
                $droppedKeys = [];
                foreach ($foreignKeys as $keyTable => $columns) {
                    foreach ($columns as $columnName => $keyOptions) {
                        if ($table == $keyOptions['REF_TABLE_NAME'] && $column == $keyOptions['REF_COLUMN_NAME']) {
                            $this->dropForeignKey($keyTable, $keyOptions['FK_NAME']);
                            $droppedKeys[] = $keyOptions;
                        }
                    }
                }

                $this->modifyColumn($table, $column, $columnDefinition);

                foreach ($droppedKeys as $options) {
                    unset($columnDefinition['identity'], $columnDefinition['primary'], $columnDefinition['comment']);

                    $onDelete = $options['ON_DELETE'];

                    if ($onDelete == AdapterInterface::FK_ACTION_SET_NULL) {
                        $columnDefinition['nullable'] = true;
                    }
                    $this->modifyColumn($options['TABLE_NAME'], $options['COLUMN_NAME'], $columnDefinition);
                    $this->addForeignKey(
                        $options['FK_NAME'],
                        $options['TABLE_NAME'],
                        $options['COLUMN_NAME'],
                        $options['REF_TABLE_NAME'],
                        $options['REF_COLUMN_NAME'],
                        ($onDelete) ? $onDelete : AdapterInterface::FK_ACTION_NO_ACTION
                    );
                }
            }
            if (!empty($tableData['comment'])) {
                $this->changeTableComment($table, $tableData['comment']);
            }
            if (!empty($tableData['engine'])) {
                $this->changeTableEngine($table, $tableData['engine']);
            }
        }

        return $this;
    }

    /**
     * Retrieve table index information
     *
     * The return value is an associative array keyed by the UPPERCASE index key (except for primary key,
     * that is always stored under 'PRIMARY' key) as returned by the RDBMS.
     *
     * The value of each array element is an associative array
     * with the following keys:
     *
     * SCHEMA_NAME      => string; name of database or schema
     * TABLE_NAME       => string; name of the table
     * KEY_NAME         => string; the original index name
     * COLUMNS_LIST     => array; array of index column names
     * INDEX_TYPE       => string; lowercase, create index type
     * INDEX_METHOD     => string; index method using
     * type             => string; see INDEX_TYPE
     * fields           => array; see COLUMNS_LIST
     * IS_CONSTRAINT    => bool; the index backs a constraint and is dropped with ALTER TABLE ... DROP CONSTRAINT
     * IS_PARTIAL       => bool; the index has a WHERE predicate
     *
     * @param string $tableName
     * @param string $schemaName
     * @return array
     */
    public function getIndexList($tableName, $schemaName = null)
    {
        $cacheKey = $this->_getTableName($tableName, $schemaName);
        $ddl = $this->loadDdlCache($cacheKey, self::DDL_INDEX);
        if ($ddl === false) {
            $ddl = [];
            $sql = sprintf(
                'SELECT i.relname AS key_name, ix.indisprimary::int AS is_primary, ix.indisunique::int AS is_unique,'
                . ' am.amname AS method, pg_get_indexdef(ix.indexrelid) AS definition,'
                . ' (ix.indpred IS NOT NULL)::int AS is_partial,'
                . ' EXISTS (SELECT 1 FROM pg_constraint AS co WHERE co.conindid = ix.indexrelid'
                . ' AND co.conrelid = ix.indrelid)::int AS is_constraint,'
                . ' (SELECT json_agg(a.attname ORDER BY k.ord) FROM unnest(ix.indkey::int2[]) WITH ORDINALITY AS k(attnum, ord)'
                . ' JOIN pg_attribute AS a ON a.attrelid = ix.indrelid AND a.attnum = k.attnum'
                . ' WHERE k.ord <= ix.indnkeyatts) AS columns'
                . ' FROM pg_index AS ix'
                . ' JOIN pg_class AS i ON i.oid = ix.indexrelid'
                . ' JOIN pg_am AS am ON am.oid = i.relam'
                . ' WHERE ix.indrelid = %s'
                . ' ORDER BY ix.indisprimary DESC, i.relname',
                $this->getRegclassSql($tableName, $schemaName)
            );
            foreach ($this->fetchAll($sql) as $row) {
                $columns = $row['columns'] !== null ? json_decode($row['columns'], true) : [];
                $method = strtoupper($row['method']);
                if ($row['is_primary']) {
                    $indexType = AdapterInterface::INDEX_TYPE_PRIMARY;
                } elseif ($row['is_unique']) {
                    $indexType = AdapterInterface::INDEX_TYPE_UNIQUE;
                } elseif ($row['method'] === 'gin' && stripos($row['definition'], 'to_tsvector') !== false) {
                    $indexType = AdapterInterface::INDEX_TYPE_FULLTEXT;
                    $method = 'FULLTEXT';
                    $columns = $this->getFullTextIndexColumns($row['definition']);
                } else {
                    $indexType = AdapterInterface::INDEX_TYPE_INDEX;
                }

                $upperKeyName = $row['is_primary'] ? 'PRIMARY' : strtoupper($row['key_name']);
                $ddl[$upperKeyName] = [
                    'SCHEMA_NAME'   => $schemaName,
                    'TABLE_NAME'    => $tableName,
                    'KEY_NAME'      => $row['key_name'],
                    'COLUMNS_LIST'  => $columns,
                    'INDEX_TYPE'    => $indexType,
                    'INDEX_METHOD'  => $method,
                    'type'          => strtolower($indexType), // for compatibility
                    'fields'        => $columns, // for compatibility
                    'IS_CONSTRAINT' => (bool)$row['is_constraint'],
                    'IS_PARTIAL'    => (bool)$row['is_partial'],
                ];
            }
            $this->saveDdlCache($cacheKey, self::DDL_INDEX, $ddl);
        }

        return $ddl;
    }

    /**
     * Get the columns of a full-text (GIN over to_tsvector()) index from its definition
     *
     * The columns are the identifiers of the indexed expression that are neither literals, casts nor function
     * names, e.g. title and content in
     * to_tsvector('simple'::regconfig, ((COALESCE((title)::text, ''::text) || ' '::text) || COALESCE(content, ''::text)))
     *
     * @param string $definition pg_get_indexdef() of the index
     * @return string[]
     */
    private function getFullTextIndexColumns(string $definition): array
    {
        $expression = preg_replace(
            ['/^.*?\bUSING\s+gin\s*/is', '/\'(?:[^\']|\'\')*\'/', '/::\s*[a-z_][\w ]*(\(\d+(,\d+)?\))?/i'],
            ['', '', ''],
            $definition
        );
        preg_match_all('/"((?:[^"]|"")+)"|\b([a-z_][\w$]*)\b(?!\s*\()/i', $expression, $matches, PREG_SET_ORDER);
        $columns = [];
        foreach ($matches as $match) {
            $columns[] = isset($match[2]) ? strtolower($match[2]) : str_replace('""', '"', $match[1]);
        }
        return array_values(array_unique($columns));
    }

    /**
     * Remove rows that duplicate others by the given columns, keeping the last stored row of each group
     *
     * @param string $tableName
     * @param string[] $fields
     * @param string|null $schemaName
     * @return $this
     */
    private function removeDuplicateRows($tableName, array $fields, $schemaName = null)
    {
        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = sprintf('a.%1$s = b.%1$s', $this->quoteIdentifier($field));
        }
        if (!$conditions) {
            return $this;
        }
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $this->rawQuery(sprintf(
            'DELETE FROM %1$s AS a USING %1$s AS b WHERE a.ctid < b.ctid AND %2$s',
            $table,
            implode(' AND ', $conditions)
        ));

        return $this;
    }

    /**
     * Creates and returns a new \Magento\Framework\DB\Select object for this adapter.
     *
     * @return \Horobyna\Db\Select
     */
    public function select()
    {
        return $this->selectFactory->create($this);
    }

    /**
     * Quotes a value and places into a piece of text at a placeholder.
     *
     * Method rewrited for handle empty arrays in value param
     *
     * @param string $text The text with a placeholder.
     * @param mixed $value The value to quote.
     * @param int|string|null $type OPTIONAL SQL datatype of the given value e.g. Zend_Db::FLOAT_TYPE or "INT"
     * @param integer $count OPTIONAL count of placeholders to replace
     * @return string An SQL-safe quoted value placed into the original text.
     */
    public function quoteInto($text, $value, $type = null, $count = null)
    {
        if (is_array($value) && empty($value)) {
            $value = new \Zend_Db_Expr('NULL');
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        return \Zend_Db_Adapter_Abstract::quoteInto($text, $value, $type, $count);
    }

    /**
     * Retrieve ddl cache name
     *
     * @param string $tableName
     * @param string $schemaName
     * @return string
     */
    protected function _getTableName($tableName, $schemaName = null)
    {
        return ($schemaName ? $schemaName . '.' : '') . $tableName;
    }

    /**
     * SQL fragment resolving a table to its oid, NULL if the table does not exist
     *
     * Without a schema the table is looked up through search_path, so temporary tables are found as well.
     *
     * @param string $tableName
     * @param string|null $schemaName
     * @return string
     */
    private function getRegclassSql($tableName, $schemaName = null): string
    {
        return sprintf(
            'to_regclass(%s)',
            $this->quote($this->quoteIdentifier($this->_getTableName($tableName, $schemaName)))
        );
    }

    /**
     * Split table argument of insert methods into table name and schema name
     *
     * @param string|array|\Zend_Db_Expr $table
     * @return array [tableName, schemaName]
     */
    private function splitTableName($table): array
    {
        if (is_array($table)) {
            $table = array_values($table);
            return count($table) > 1 ? [$table[1], $table[0]] : [$table[0], null];
        }
        $table = (string)$table;
        if (strpos($table, '.') !== false) {
            list($schemaName, $tableName) = explode('.', $table, 2);
            return [$tableName, $schemaName];
        }
        return [$table, null];
    }

    /**
     * Truncate an identifier the same way PostgreSQL does
     *
     * @param string $name
     * @return string
     */
    private function truncateIdentifier($name): string
    {
        return substr((string)$name, 0, self::LENGTH_INDEX_NAME);
    }

    /**
     * Shorten an entity name to the PostgreSQL identifier length
     *
     * @param string $entityName
     * @param string $prefix
     * @return string
     */
    private function shortenEntityName($entityName, $prefix): string
    {
        $shortName = ExpressionConverter::shortenEntityName($entityName, $prefix);
        if (strlen($shortName) > self::LENGTH_INDEX_NAME) {
            // md5() here is not for cryptographic use.
            // phpcs:ignore Magento2.Security.InsecureFunction
            $shortName = substr($prefix . md5($entityName), 0, self::LENGTH_INDEX_NAME);
        }
        return $shortName;
    }

    /**
     * Retrieve Id for cache
     *
     * @param string $tableKey
     * @param int $ddlType
     * @return string
     */
    protected function _getCacheId($tableKey, $ddlType)
    {
        return sprintf('%s_%s_%s', self::DDL_CACHE_PREFIX, $tableKey, $ddlType);
    }

    /**
     * Load DDL data from cache
     *
     * Return false if cache does not exists
     *
     * @param string $tableCacheKey the table cache key
     * @param int $ddlType the DDL constant
     * @return string|array|int|false
     */
    public function loadDdlCache($tableCacheKey, $ddlType)
    {
        if (!$this->_isDdlCacheAllowed) {
            return false;
        }
        if (isset($this->_ddlCache[$ddlType][$tableCacheKey])) {
            return $this->_ddlCache[$ddlType][$tableCacheKey];
        }

        if ($this->_cacheAdapter) {
            $cacheId = $this->_getCacheId($tableCacheKey, $ddlType);
            $data = $this->_cacheAdapter->load($cacheId);
            if ($data !== false) {
                $data = $this->serializer->unserialize($data);
                $this->_ddlCache[$ddlType][$tableCacheKey] = $data;
            }
            return $data;
        }

        return false;
    }

    /**
     * Save DDL data into cache
     *
     * @param string $tableCacheKey
     * @param int $ddlType
     * @param array $data
     * @return $this
     */
    public function saveDdlCache($tableCacheKey, $ddlType, $data)
    {
        if (!$this->_isDdlCacheAllowed) {
            return $this;
        }
        $this->_ddlCache[$ddlType][$tableCacheKey] = $data;

        if ($this->_cacheAdapter) {
            $cacheId = $this->_getCacheId($tableCacheKey, $ddlType);
            $data = $this->serializer->serialize($data);
            $this->_cacheAdapter->save($data, $cacheId, [self::DDL_CACHE_TAG]);
        }

        return $this;
    }

    /**
     * Reset cached DDL data from cache
     *
     * If table name is null - reset all cached DDL data
     *
     * @param string $tableName
     * @param string $schemaName OPTIONAL
     * @return $this
     */
    public function resetDdlCache($tableName = null, $schemaName = null)
    {
        if (!$this->_isDdlCacheAllowed) {
            return $this;
        }
        if ($tableName === null) {
            $this->_ddlCache = [];
            if ($this->_cacheAdapter) {
                $this->_cacheAdapter->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [self::DDL_CACHE_TAG]);
            }
        } else {
            $cacheKey = $this->_getTableName($tableName, $schemaName);

            $ddlTypes = [
                self::DDL_DESCRIBE,
                self::DDL_CREATE,
                self::DDL_INDEX,
                self::DDL_FOREIGN_KEY,
                self::DDL_EXISTS
            ];
            foreach ($ddlTypes as $ddlType) {
                unset($this->_ddlCache[$ddlType][$cacheKey]);
            }

            if ($this->_cacheAdapter) {
                foreach ($ddlTypes as $ddlType) {
                    $cacheId = $this->_getCacheId($cacheKey, $ddlType);
                    $this->_cacheAdapter->remove($cacheId);
                }
            }
        }

        return $this;
    }

    /**
     * Disallow DDL caching
     *
     * @return $this
     */
    public function disallowDdlCache()
    {
        $this->_isDdlCacheAllowed = false;
        return $this;
    }

    /**
     * Allow DDL caching
     *
     * @return $this
     */
    public function allowDdlCache()
    {
        $this->_isDdlCacheAllowed = true;
        return $this;
    }

    /**
     * Returns the column descriptions for a table.
     *
     * The return value is an associative array keyed by the column name,
     * as returned by the RDBMS.
     *
     * The value of each array element is an associative array
     * with the following keys:
     *
     * SCHEMA_NAME      => string; name of database or schema
     * TABLE_NAME       => string;
     * COLUMN_NAME      => string; column name
     * COLUMN_POSITION  => number; ordinal position of column in table
     * DATA_TYPE        => string; SQL datatype name of column, using MySQL names (int, varchar, decimal, ...)
     * DEFAULT          => string; default expression of column, null if none
     * NULLABLE         => boolean; true if column can have nulls
     * LENGTH           => number; length of CHAR/VARCHAR
     * SCALE            => number; scale of NUMERIC/DECIMAL
     * PRECISION        => number; precision of NUMERIC/DECIMAL
     * UNSIGNED         => boolean; always false, PostgreSQL has no unsigned types
     * PRIMARY          => boolean; true if column is part of the primary key
     * PRIMARY_POSITION => integer; position of column in primary key
     * IDENTITY         => integer; true if column is auto-generated with unique values
     *
     * @param string $tableName
     * @param string $schemaName OPTIONAL
     * @return array
     */
    public function describeTable($tableName, $schemaName = null)
    {
        $cacheKey = $this->_getTableName($tableName, $schemaName);
        $ddl = $this->loadDdlCache($cacheKey, self::DDL_DESCRIBE);
        if ($ddl === false) {
            $sql = sprintf(
                'SELECT a.attnum, c.relname, a.attname, t.typname, a.atttypmod,'
                . ' pg_get_expr(d.adbin, d.adrelid) AS default_value, a.attnotnull::int AS notnull,'
                . ' a.attidentity, pk.position AS primary_position'
                . ' FROM pg_attribute AS a'
                . ' JOIN pg_class AS c ON c.oid = a.attrelid'
                . ' JOIN pg_type AS t ON t.oid = a.atttypid'
                . ' LEFT JOIN pg_attrdef AS d ON d.adrelid = a.attrelid AND d.adnum = a.attnum'
                . ' LEFT JOIN LATERAL (SELECT array_position(co.conkey, a.attnum) AS position FROM pg_constraint AS co'
                . ' WHERE co.conrelid = a.attrelid AND co.contype = \'p\') AS pk ON TRUE'
                . ' WHERE a.attrelid = %s AND a.attnum > 0 AND NOT a.attisdropped'
                . ' ORDER BY a.attnum',
                $this->getRegclassSql($tableName, $schemaName)
            );
            $ddl = [];
            foreach ($this->fetchAll($sql) as $row) {
                $ddl[$row['attname']] = $this->prepareColumnData($row, $schemaName);
            }
            // Do not remember a missing table: it may be created by a raw query that does not reset the cache
            if ($ddl) {
                $this->saveDdlCache($cacheKey, self::DDL_DESCRIBE, $ddl);
            }
        }

        return $ddl;
    }

    /**
     * Convert a pg_attribute row to describeTable() column data
     *
     * @param array $row
     * @param string|null $schemaName
     * @return array
     */
    private function prepareColumnData(array $row, $schemaName): array
    {
        $length = null;
        $precision = null;
        $scale = null;
        $typeModifier = (int)$row['atttypmod'];
        if (in_array($row['typname'], ['varchar', 'bpchar'], true) && $typeModifier > 4) {
            $length = $typeModifier - 4;
        } elseif ($row['typname'] === 'numeric' && $typeModifier >= 4) {
            $precision = (($typeModifier - 4) >> 16) & 0xffff;
            $scale = ($typeModifier - 4) & 0xffff;
        }

        $default = $row['default_value'];
        $isIdentity = $row['attidentity'] !== '' && $row['attidentity'] !== null
            || ($default !== null && stripos($default, 'nextval(') === 0);

        return [
            'SCHEMA_NAME'      => $schemaName,
            'TABLE_NAME'       => $row['relname'],
            'COLUMN_NAME'      => $row['attname'],
            'COLUMN_POSITION'  => (int)$row['attnum'],
            'DATA_TYPE'        => self::DATA_TYPE_MAP[$row['typname']] ?? $row['typname'],
            'DEFAULT'          => $isIdentity ? null : $this->normalizeDefaultValue($default),
            'NULLABLE'         => !$row['notnull'],
            'LENGTH'           => $length,
            'SCALE'            => $scale,
            'PRECISION'        => $precision,
            'UNSIGNED'         => false,
            'PRIMARY'          => $row['primary_position'] !== null,
            'PRIMARY_POSITION' => $row['primary_position'] !== null ? (int)$row['primary_position'] : null,
            'IDENTITY'         => $isIdentity,
        ];
    }

    /**
     * Convert a default expression to the raw value MySQL reports
     *
     * E.g. 'abc'::character varying => abc, (-1) => -1, now() => CURRENT_TIMESTAMP, NULL::text => null
     *
     * @param string|null $default
     * @return string|null
     */
    private function normalizeDefaultValue($default)
    {
        if ($default === null) {
            return null;
        }
        $default = trim($default);
        if (preg_match('/^(now\(\)|CURRENT_TIMESTAMP|LOCALTIMESTAMP|transaction_timestamp\(\))(::[\w\s]+)?$/i', $default)) {
            return 'CURRENT_TIMESTAMP';
        }
        while (preg_match('/^(.*)::[a-z_][a-z_\s]*(\(\d+(,\d+)?\))?(\[\])?$/is', $default, $matches)) {
            $default = $matches[1];
        }
        if (preg_match('/^\((.*)\)$/s', $default, $matches)) {
            $default = $matches[1];
        }
        if (strcasecmp($default, 'NULL') === 0) {
            return null;
        }
        if (preg_match('/^\'((?:[^\']|\'\')*)\'$/s', $default, $matches)) {
            return str_replace("''", "'", $matches[1]);
        }
        return $default;
    }

    /**
     * Format described column to definition, ready to be added to ddl table.
     *
     * Return array with keys: name, type, length, options, comment
     *
     * @param array $columnData
     * @return array
     */
    public function getColumnCreateByDescribe($columnData)
    {
        $type = $this->_getColumnTypeByDdl($columnData);
        $options = [];

        if ($columnData['IDENTITY'] === true) {
            $options['identity'] = true;
        }
        if ($columnData['UNSIGNED'] === true) {
            $options['unsigned'] = true;
        }
        if ($columnData['NULLABLE'] === false
            && !($type == Table::TYPE_TEXT && isset($columnData['DEFAULT']) && strlen($columnData['DEFAULT']) != 0)
        ) {
            $options['nullable'] = false;
        }
        if ($columnData['PRIMARY'] === true) {
            $options['primary'] = true;
        }
        if ($columnData['DEFAULT'] !== null && $type != Table::TYPE_TEXT) {
            $options['default'] = $this->quote($columnData['DEFAULT']);
        }
        if (isset($columnData['SCALE']) && strlen((string)$columnData['SCALE']) > 0) {
            $options['scale'] = $columnData['SCALE'];
        }
        if (isset($columnData['PRECISION']) && strlen((string)$columnData['PRECISION']) > 0) {
            $options['precision'] = $columnData['PRECISION'];
        }

        $comment = $this->string->upperCaseWords($columnData['COLUMN_NAME'], '_', ' ');

        $result = [
            'name'      => $columnData['COLUMN_NAME'],
            'type'      => $type,
            'length'    => $columnData['LENGTH'],
            'options'   => $options,
            'comment'   => $comment,
        ];

        return $result;
    }

    /**
     * Create \Magento\Framework\DB\Ddl\Table object by data from describe table
     *
     * @param string $tableName
     * @param string $newTableName
     * @return Table
     */
    public function createTableByDdl($tableName, $newTableName)
    {
        $describe = $this->describeTable($tableName);
        $table = $this->newTable($newTableName)
            ->setComment($this->string->upperCaseWords($newTableName, '_', ' '));

        foreach ($describe as $columnData) {
            $columnInfo = $this->getColumnCreateByDescribe($columnData);

            $table->addColumn(
                $columnInfo['name'],
                $columnInfo['type'],
                $columnInfo['length'],
                $columnInfo['options'],
                $columnInfo['comment']
            );
        }

        $indexes = $this->getIndexList($tableName);
        foreach ($indexes as $indexData) {
            /**
             * Do not create primary index - it is created with identity column.
             * For reliability check both name and type, because these values can start to differ in future.
             */
            if (($indexData['KEY_NAME'] == 'PRIMARY')
                || ($indexData['INDEX_TYPE'] == AdapterInterface::INDEX_TYPE_PRIMARY)
            ) {
                continue;
            }

            $fields = $indexData['COLUMNS_LIST'];
            $options = ['type' => $indexData['INDEX_TYPE']];
            $table->addIndex($this->getIndexName($newTableName, $fields, $indexData['INDEX_TYPE']), $fields, $options);
        }

        $foreignKeys = $this->getForeignKeys($tableName);
        foreach ($foreignKeys as $keyData) {
            $fkName = $this->getForeignKeyName(
                $newTableName,
                $keyData['COLUMN_NAME'],
                $keyData['REF_TABLE_NAME'],
                $keyData['REF_COLUMN_NAME']
            );
            $onDelete = $this->_getDdlAction($keyData['ON_DELETE']);

            $table->addForeignKey(
                $fkName,
                $keyData['COLUMN_NAME'],
                $keyData['REF_TABLE_NAME'],
                $keyData['REF_COLUMN_NAME'],
                $onDelete
            );
        }

        return $table;
    }

    /**
     * Modify the column definition by data from describe table
     *
     * @param string $tableName
     * @param string $columnName
     * @param array $definition
     * @param boolean $flushData
     * @param string $schemaName
     * @return $this
     */
    public function modifyColumnByDdl($tableName, $columnName, $definition, $flushData = false, $schemaName = null)
    {
        $definition = array_change_key_case($definition, CASE_UPPER);
        $definition['COLUMN_TYPE'] = $this->_getColumnTypeByDdl($definition);
        if (array_key_exists('DEFAULT', $definition) && $definition['DEFAULT'] === null) {
            unset($definition['DEFAULT']);
        }

        return $this->modifyColumn($tableName, $columnName, $definition, $flushData, $schemaName);
    }

    /**
     * Retrieve column data type by data from describe table
     *
     * @param array $column
     * @return string|null
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _getColumnTypeByDdl($column)
    {
        switch ($column['DATA_TYPE']) {
            case 'bool':
                return Table::TYPE_BOOLEAN;
            case 'tinytext':
            case 'char':
            case 'varchar':
            case 'text':
            case 'mediumtext':
            case 'longtext':
                return Table::TYPE_TEXT;
            case 'blob':
            case 'bytea':
            case 'mediumblob':
            case 'longblob':
                return Table::TYPE_BLOB;
            case 'tinyint':
            case 'smallint':
                return Table::TYPE_SMALLINT;
            case 'mediumint':
            case 'int':
                return Table::TYPE_INTEGER;
            case 'bigint':
                return Table::TYPE_BIGINT;
            case 'datetime':
                return Table::TYPE_DATETIME;
            case 'timestamp':
                return Table::TYPE_TIMESTAMP;
            case 'date':
                return Table::TYPE_DATE;
            case 'float':
                return Table::TYPE_FLOAT;
            case 'decimal':
            case 'numeric':
                return Table::TYPE_DECIMAL;
        }
        return null;
    }

    /**
     * Change table storage engine
     *
     * PostgreSQL has a single storage engine, so nothing is changed.
     *
     * @param string $tableName
     * @param string $engine
     * @param string $schemaName
     * @return true
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeTableEngine($tableName, $engine, $schemaName = null)
    {
        return true;
    }

    /**
     * Change table comment
     *
     * @param string $tableName
     * @param string $comment
     * @param string $schemaName
     * @return \Zend_Db_Statement_Pdo
     */
    public function changeTableComment($tableName, $comment, $schemaName = null)
    {
        return $this->rawQuery($this->getTableCommentSql($tableName, $comment, $schemaName));
    }

    /**
     * Inserts a table row with specified data
     *
     * Special for Zero values to identity column
     *
     * @param string $table
     * @param array $bind
     * @return int The number of affected rows.
     */
    public function insertForce($table, array $bind)
    {
        return $this->executeInsert($table, array_keys($bind), [array_values($bind)], '', true);
    }

    /**
     * Inserts a table row with specified data.
     *
     * @param mixed $table The table to insert data into.
     * @param array $bind Column-value pairs.
     * @return int The number of affected rows.
     */
    public function insert($table, array $bind)
    {
        return $this->executeInsert($table, array_keys($bind), [array_values($bind)]);
    }

    /**
     * Updates table rows with specified data based on a WHERE clause.
     *
     * @param mixed $table The table to update.
     * @param array $bind Column-value pairs.
     * @param mixed $where UPDATE WHERE clause(s).
     * @return int The number of affected rows.
     */
    public function update($table, array $bind, $where = '')
    {
        list($tableName, $schemaName) = $this->splitTableName($table);
        $binaryColumns = $this->getBinaryColumns($tableName, $schemaName);
        foreach ($bind as $column => $value) {
            if (isset($binaryColumns[$column])) {
                $bind[$column] = $this->wrapBinaryValue($value);
            }
        }

        return \Zend_Db_Adapter_Abstract::update($table, $bind, $where);
    }

    /**
     * Inserts a table row with specified data.
     *
     * Emulates ON DUPLICATE KEY UPDATE with ON CONFLICT on the primary or unique key covered by the inserted
     * columns. VALUES(column) in update expressions refers to the inserted value, as in MySQL.
     *
     * @param string $table The table to insert data into.
     * @param array $data Column-value pairs or array of column-value pairs.
     * @param array $fields update fields pairs or values
     * @return int The number of affected rows.
     * @throws \Zend_Db_Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function insertOnDuplicate($table, array $data, array $fields = [])
    {
        // extract and quote col names from the array keys
        $row    = reset($data); // get first element from data array
        $rows   = [];

        if (is_array($row)) { // Array of column-value pairs
            $cols = array_keys($row);
            foreach ($data as $row) {
                if (array_diff($cols, array_keys($row))) {
                    throw new \Zend_Db_Exception('Invalid data for insert');
                }
                $line = [];
                foreach ($cols as $field) {
                    $line[] = $row[$field];
                }
                $rows[] = $line;
            }
            unset($row);
        } else { // Column-value pairs
            $cols   = array_keys($data);
            $rows[] = array_values($data);
        }

        $updateFields = [];
        if (empty($fields)) {
            $fields = $cols;
        }

        // prepare ON CONFLICT conditions
        foreach ($fields as $k => $v) {
            $field = $value = null;
            if (!is_numeric($k)) {
                $field = $this->quoteIdentifier($k);
                if ($v instanceof \Zend_Db_Expr) {
                    $value = $this->translateValuesFunction($v->__toString());
                } elseif ($v instanceof \Laminas\Db\Sql\Expression) {
                    $value = $this->translateValuesFunction($v->getExpression());
                } elseif (is_string($v)) {
                    $value = sprintf('EXCLUDED.%s', $this->quoteIdentifier($v));
                } elseif (is_numeric($v)) {
                    $value = $this->quoteInto('?', $v);
                }
            } elseif (is_string($v)) {
                $value = sprintf('EXCLUDED.%s', $this->quoteIdentifier($v));
                $field = $this->quoteIdentifier($v);
            }

            if ($field && is_string($value) && $value !== '') {
                $updateFields[] = sprintf('%s = %s', $field, $value);
            }
        }

        $onConflict = '';
        if ($updateFields) {
            list($tableName, $schemaName) = $this->splitTableName($table);
            $updateFields = $this->qualifyExistingRowColumns(
                $updateFields,
                $tableName,
                array_keys($this->describeTable($tableName, $schemaName))
            );
            $target = $this->getConflictTarget($tableName, $schemaName, $cols);
            if ($target !== null) {
                $rows = $this->removeConflictingRows($rows, $cols, $target);
                $onConflict = sprintf(
                    ' ON CONFLICT (%s) DO UPDATE SET %s',
                    implode(', ', array_map([$this, 'quoteIdentifier'], $target)),
                    implode(', ', $updateFields)
                );
            }
        }

        return $this->executeInsert($table, $cols, $rows, $onConflict);
    }

    /**
     * Translate an INSERT IGNORE statement given as SQL text to INSERT ... ON CONFLICT DO NOTHING
     *
     * E.g. the database cache backend, Adobe Stock assets, \Magento\Framework\DB\Select::insertIgnoreFromSelect().
     *
     * @param string $sql
     * @return string
     */
    private function translateInsertIgnore(string $sql): string
    {
        if (!preg_match('/^(\s*INSERT)\s+IGNORE\b/i', $sql, $matches)) {
            return $sql;
        }

        return $matches[1] . rtrim(substr($sql, strlen($matches[0])), " \t\n\r;") . ' ON CONFLICT DO NOTHING';
    }

    /**
     * Translate an INSERT ... ON DUPLICATE KEY UPDATE statement given as SQL text to INSERT ... ON CONFLICT
     *
     * Some modules build the statement themselves instead of calling insertOnDuplicate() (Inventory SaveMultiple
     * resource models, the bundle price indexer, the database cache backend). As in insertOnDuplicate(), the
     * conflict target is the primary or unique key covered by the inserted columns, and without one the clause
     * is dropped.
     *
     * @param string $sql with backtick-quoted identifiers already translated
     * @return string
     */
    private function translateOnDuplicateKeyUpdate(string $sql): string
    {
        if (stripos($sql, 'DUPLICATE') === false) {
            return $sql;
        }
        $masked = $this->scanner->mask($sql);
        if (!preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $masked, $clause, PREG_OFFSET_CAPTURE)
            || !preg_match(
                '/^\s*INSERT\s+INTO\s+((?:"[^"]+"|\w+)(?:\.(?:"[^"]+"|\w+))?)\s*\(([^()]*)\)/i',
                $sql,
                $insert
            )
        ) {
            return $sql;
        }

        $statement = rtrim(substr($sql, 0, $clause[0][1]));
        list($tableName, $schemaName) = $this->splitTableName(str_replace('"', '', $insert[1]));
        $columns = array_map(fn ($column) => trim($column, ' "'), $this->scanner->splitList($insert[2]));
        $target = $this->getConflictTarget($tableName, $schemaName, $columns);
        if ($target === null) {
            return $statement;
        }

        $assignments = [];
        $list = rtrim(substr($sql, $clause[0][1] + strlen($clause[0][0])), " \t\n\r;");
        foreach ($this->scanner->splitList($list) as $assignment) {
            list($field, $expression) = array_map('trim', explode('=', $assignment, 2) + [1 => '']);
            $assignments[] = $field . ' = ' . $this->translateValuesFunction($expression);
        }
        $assignments = $this->qualifyExistingRowColumns(
            $assignments,
            $tableName,
            array_keys($this->describeTable($tableName, $schemaName))
        );

        return sprintf(
            '%s ON CONFLICT (%s) DO UPDATE SET %s',
            $statement,
            implode(', ', array_map([$this, 'quoteIdentifier'], $target)),
            implode(', ', $assignments)
        );
    }

    /**
     * Replace MySQL VALUES(column) references with PostgreSQL EXCLUDED.column
     *
     * @param string $expression
     * @return string
     */
    private function translateValuesFunction(string $expression): string
    {
        return preg_replace_callback(
            '/\bVALUES\s*\(\s*(?:`([^`]+)`|"([^"]+)"|(\w+))\s*\)/i',
            fn ($matches) => 'EXCLUDED.' . $this->quoteIdentifier($matches[1] ?: ($matches[2] ?: $matches[3])),
            $expression
        );
    }

    /**
     * Qualify bare column references in "column = expression" update assignments with the table name
     *
     * In MySQL ON DUPLICATE KEY UPDATE a bare column (e.g. `popularity` + 1) is the existing row; in PostgreSQL
     * ON CONFLICT it is ambiguous between the existing row and EXCLUDED.
     *
     * @param string[] $assignments
     * @param string $tableName
     * @param string[] $columns
     * @return string[]
     */
    private function qualifyExistingRowColumns(array $assignments, $tableName, array $columns): array
    {
        if (!$columns) {
            return $assignments;
        }
        $names = implode('|', array_map(fn ($column) => preg_quote($column, '/'), $columns));
        $pattern = '/(?<![\w."`])(?:`(' . $names . ')`|"(' . $names . ')"|\b(' . $names . ')\b)(?![\w"`(.])/';
        $table = $this->quoteIdentifier($tableName);
        foreach ($assignments as $key => $assignment) {
            list($field, $expression) = explode(' = ', $assignment, 2);
            $expression = preg_replace_callback(
                $pattern,
                fn ($matches) => $table . '.' . $this->quoteIdentifier(end($matches)),
                $expression
            );
            $assignments[$key] = $field . ' = ' . $expression;
        }
        return $assignments;
    }

    /**
     * Find the primary or unique key ON CONFLICT should target for the inserted columns
     *
     * @param string $tableName
     * @param string|null $schemaName
     * @param string[] $columns
     * @return string[]|null
     */
    private function getConflictTarget($tableName, $schemaName, array $columns)
    {
        foreach ($this->getIndexList($tableName, $schemaName) as $index) {
            if (!in_array(
                $index['INDEX_TYPE'],
                [AdapterInterface::INDEX_TYPE_PRIMARY, AdapterInterface::INDEX_TYPE_UNIQUE],
                true
            ) || $index['IS_PARTIAL'] || empty($index['COLUMNS_LIST'])
            ) {
                continue;
            }
            if (!array_diff($index['COLUMNS_LIST'], $columns)) {
                return $index['COLUMNS_LIST'];
            }
        }
        return null;
    }

    /**
     * Keep only the last row for each conflict key
     *
     * MySQL applies duplicate rows of one statement one after another, while PostgreSQL refuses to update
     * the same row twice in a single ON CONFLICT statement.
     *
     * @param array $rows
     * @param string[] $columns
     * @param string[] $target
     * @return array
     */
    private function removeConflictingRows(array $rows, array $columns, array $target): array
    {
        if (count($rows) < 2) {
            return $rows;
        }
        $positions = array_keys(array_intersect($columns, $target));
        $unique = [];
        foreach ($rows as $row) {
            $row = array_values((array)$row);
            $key = [];
            foreach ($positions as $position) {
                $key[] = (string)$row[$position];
            }
            $key = implode("\0", $key);
            unset($unique[$key]);
            $unique[$key] = $row;
        }
        return array_values($unique);
    }

    /**
     * Inserts a table multiply rows with specified data.
     *
     * @param string|array|\Zend_Db_Expr $table The table to insert data into.
     * @param array $data Column-value pairs or array of Column-value pairs.
     * @return int The number of affected rows.
     * @throws \Zend_Db_Exception
     */
    public function insertMultiple($table, array $data)
    {
        $row = reset($data);
        // support insert syntaxes
        if (!is_array($row)) {
            return $this->insert($table, $data);
        }

        // validate data array
        $cols = array_keys($row);
        $insertArray = [];
        foreach ($data as $row) {
            $line = [];
            if (array_diff($cols, array_keys($row))) {
                throw new \Zend_Db_Exception('Invalid data for insert');
            }
            foreach ($cols as $field) {
                $line[] = $row[$field];
            }
            $insertArray[] = $line;
        }
        unset($row);

        return $this->insertArray($table, $cols, $insertArray);
    }

    /**
     * Insert array into a table based on columns definition
     *
     * $data can be represented as:
     * - arrays of values ordered according to columns in $columns array
     *      array(
     *          array('value1', 'value2'),
     *          array('value3', 'value4'),
     *      )
     * - array of values, if $columns contains only one column
     *      array('value1', 'value2')
     *
     * @param string $table
     * @param string[] $columns
     * @param array $data
     * @param int $strategy
     * @return int
     * @throws \Zend_Db_Exception
     */
    public function insertArray($table, array $columns, array $data, $strategy = 0)
    {
        $rows         = [];
        $columnsCount = count($columns);
        foreach ($data as $row) {
            if (is_array($row) && $columnsCount != count($row)) {
                throw new \Zend_Db_Exception('Invalid data for insert');
            }
            $rows[] = array_values((array)$row);
        }

        $onConflict = '';
        switch ($strategy) {
            case self::REPLACE:
                list($tableName, $schemaName) = $this->splitTableName($table);
                $target = $this->getConflictTarget($tableName, $schemaName, $columns);
                if ($target !== null) {
                    $rows = $this->removeConflictingRows($rows, $columns, $target);
                    $onConflict = $this->renderOnConflict($target, array_diff($columns, $target));
                }
                break;
            case self::INSERT_IGNORE:
                $onConflict = ' ON CONFLICT DO NOTHING';
                break;
        }

        return $this->executeInsert($table, $columns, $rows, $onConflict);
    }

    /**
     * Render ON CONFLICT clause that overwrites the given columns with the inserted values
     *
     * @param string[] $target
     * @param string[] $fields
     * @return string
     */
    private function renderOnConflict(array $target, array $fields): string
    {
        $update = [];
        foreach ($fields as $field) {
            $update[] = sprintf('%1$s = EXCLUDED.%1$s', $this->quoteIdentifier($field));
        }

        return sprintf(
            ' ON CONFLICT (%s) %s',
            implode(', ', array_map([$this, 'quoteIdentifier'], $target)),
            $update ? 'DO UPDATE SET ' . implode(', ', $update) : 'DO NOTHING'
        );
    }

    /**
     * Insert rows and keep identity values consistent with MySQL auto_increment
     *
     * - NULL or 0 in the identity column inserts DEFAULT, i.e. generates a new value
     *   (an explicit 0 is kept by insertForce() and between startSetup() and endSetup());
     * - the generated identity is returned with RETURNING and is available through lastInsertId();
     * - after explicit identity values the sequence is moved past the greatest stored value.
     *
     * @param string|array|\Zend_Db_Expr $table
     * @param string[] $columns
     * @param array $rows positional rows
     * @param string $onConflict
     * @param bool $keepZeroIdentity
     * @return int
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function executeInsert($table, array $columns, array $rows, string $onConflict = '', $keepZeroIdentity = false)
    {
        list($tableName, $schemaName) = $this->splitTableName($table);
        $identity = $this->getAutoIncrementField($tableName, $schemaName);
        $identity = $identity === false ? null : $identity;
        $identityPosition = $identity !== null ? array_search($identity, $columns, true) : false;
        $keepZeroIdentity = $keepZeroIdentity || $this->isSetupMode;
        $binaryColumns = $this->getBinaryColumns($tableName, $schemaName);
        $binaryPositions = array_keys(array_intersect($columns, array_keys($binaryColumns)));

        $bind = [];
        $values = [];
        $hasExplicitIdentity = false;
        foreach ($rows as $row) {
            $row = array_values((array)$row);
            if ($identityPosition !== false) {
                $value = $row[$identityPosition];
                if ($value === null || $value === '' || (!$keepZeroIdentity && ($value === 0 || $value === '0'))) {
                    $row[$identityPosition] = new \Zend_Db_Expr('DEFAULT');
                } else {
                    $hasExplicitIdentity = true;
                }
            }
            foreach ($binaryPositions as $position) {
                $row[$position] = $this->wrapBinaryValue($row[$position]);
            }
            $values[] = $this->_prepareInsertData($row, $bind);
        }

        $quotedTable = $this->quoteIdentifier($table, true);
        if ($columns) {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $quotedTable,
                implode(',', array_map([$this, 'quoteIdentifier'], $columns)),
                implode(', ', $values)
            );
        } else {
            $sql = sprintf('INSERT INTO %s DEFAULT VALUES', $quotedTable);
        }
        $sql .= $onConflict;
        if ($identity !== null) {
            $sql .= ' RETURNING ' . $this->quoteIdentifier($identity);
        }

        $this->isAdapterInsert = true;
        try {
            $stmt = $this->query($sql, $bind);
        } finally {
            $this->isAdapterInsert = false;
        }
        $result = $stmt->rowCount();

        if ($identity !== null) {
            // MySQL LAST_INSERT_ID() returns the first value generated by a multi-row insert
            $insertId = $stmt->fetchColumn();
            if ($insertId !== false && $insertId !== null) {
                $this->lastInsertId = $insertId;
                $this->lastInsertIds[$this->_getTableName($tableName, $schemaName)] = $insertId;
            }
            if ($hasExplicitIdentity) {
                $this->rawQuery($this->getSyncSequenceSql($tableName, $identity, $schemaName));
            }
        }

        return $result;
    }

    /**
     * SQL that moves the identity sequence past the greatest value stored in the column
     *
     * @param string $tableName
     * @param string $columnName
     * @param string|null $schemaName
     * @return string
     */
    public function getSyncSequenceSql($tableName, $columnName, $schemaName = null): string
    {
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $column = $this->quoteIdentifier($columnName);

        return sprintf(
            'SELECT setval(s.seq, s.max_value) FROM (SELECT pg_get_serial_sequence(%s, %s) AS seq,'
            . ' MAX(%s) AS max_value FROM %s) AS s WHERE s.seq IS NOT NULL AND s.max_value >= 1'
            . ' AND s.max_value > COALESCE(pg_sequence_last_value(s.seq::regclass), 0)',
            $this->quote($table),
            $this->quote($columnName),
            $column,
            $table
        );
    }

    /**
     * Get the quoted name of the sequence that generates identity values of a table, null if there is none
     *
     * @param string $tableName
     * @param string|null $schemaName
     * @return string|null
     */
    private function getIdentitySequence($tableName, $schemaName = null)
    {
        $column = $this->getAutoIncrementField($tableName, $schemaName);
        if ($column === false) {
            return null;
        }
        $sequence = $this->fetchOne(
            'SELECT pg_get_serial_sequence(?, ?)',
            [$this->quoteIdentifier($this->_getTableName($tableName, $schemaName)), $column]
        );
        return $sequence ?: null;
    }

    /**
     * Check whether the column is a SQL standard identity column (as opposed to a serial one)
     *
     * @param string $tableName
     * @param string $columnName
     * @param string|null $schemaName
     * @return bool
     */
    public function isIdentityColumn($tableName, $columnName, $schemaName = null): bool
    {
        return (bool)$this->fetchOne(
            sprintf(
                'SELECT COUNT(1) FROM pg_attribute WHERE attrelid = %s AND attname = ? AND attidentity <> \'\'',
                $this->getRegclassSql($tableName, $schemaName)
            ),
            [$columnName]
        );
    }

    /**
     * Get bytea columns of a table
     *
     * @param string $tableName
     * @param string|null $schemaName
     * @return array column name => column name
     */
    private function getBinaryColumns($tableName, $schemaName = null): array
    {
        $columns = [];
        foreach ($this->describeTable($tableName, $schemaName) as $column) {
            if ($column['DATA_TYPE'] === 'blob') {
                $columns[$column['COLUMN_NAME']] = $column['COLUMN_NAME'];
            }
        }
        return $columns;
    }

    /**
     * Mark a value bound to a bytea column as binary
     *
     * @param mixed $value
     * @return mixed
     */
    private function wrapBinaryValue($value)
    {
        if ($value === null || $value instanceof \Zend_Db_Expr || $value instanceof Parameter) {
            return $value;
        }
        $parameter = new Parameter((string)$value);
        $parameter->setIsBlob(true);
        return $parameter;
    }

    /**
     * Gets the last ID generated automatically by an IDENTITY column.
     *
     * Returns the value captured by the adapter's own inserts; otherwise asks the table's sequence
     * (or LASTVAL() when no table is given). Returns '0' if nothing was generated, as MySQL does.
     *
     * @param string $tableName OPTIONAL Name of table.
     * @param string $primaryKey OPTIONAL Name of primary key column.
     * @return string
     */
    public function lastInsertId($tableName = null, $primaryKey = null)
    {
        if ($tableName !== null) {
            list($table, $schemaName) = $this->splitTableName($tableName);
            $cacheKey = $this->_getTableName($table, $schemaName);
            if (isset($this->lastInsertIds[$cacheKey])) {
                return $this->lastInsertIds[$cacheKey];
            }
            $column = $primaryKey ?: $this->getAutoIncrementField($table, $schemaName);
            if (!$column) {
                return '0';
            }
            $sql = sprintf(
                'SELECT currval(pg_get_serial_sequence(%s, %s))',
                $this->quote($this->quoteIdentifier($cacheKey)),
                $this->quote($column)
            );
        } elseif ($this->lastInsertId !== null) {
            return $this->lastInsertId;
        } else {
            $sql = 'SELECT lastval()';
        }

        // currval()/lastval() fail when nothing was generated in this session
        $this->_connect();
        try {
            $value = $this->runInSavepoint(fn () => $this->_connection->query($sql)->fetchColumn());
        } catch (\PDOException) {
            return '0';
        }
        return $value === false || $value === null ? '0' : (string)$value;
    }

    /**
     * Set cache adapter
     *
     * @param FrontendInterface $cacheAdapter
     * @return $this
     */
    public function setCacheAdapter(FrontendInterface $cacheAdapter)
    {
        $this->_cacheAdapter = $cacheAdapter;
        return $this;
    }

    /**
     * Return new DDL Table object
     *
     * @param string $tableName the table name
     * @param string $schemaName the database/schema name
     * @return Table
     */
    public function newTable($tableName = null, $schemaName = null)
    {
        $table = new Table();
        if ($tableName !== null) {
            $table->setName($tableName);
        }
        if ($schemaName !== null) {
            $table->setSchema($schemaName);
        }

        return $table;
    }

    /**
     * Create table
     *
     * The table, its indexes, comments and triggers are created in one transaction.
     *
     * @param Table $table
     * @throws \Zend_Db_Exception
     * @return \Zend_Db_Statement_Pdo
     */
    public function createTable(Table $table)
    {
        $this->getSchemaListener()->createTable($table);
        $columns = $table->getColumns();
        foreach ($columns as $columnEntry) {
            if (empty($columnEntry['COMMENT'])) {
                throw new \Zend_Db_Exception("Cannot create table without columns comments");
            }
        }

        $statements = $this->getCreateTableStatements($table, false);
        if ($this->isTableExists($table->getName(), $table->getSchema())) {
            // CREATE TABLE IF NOT EXISTS is a no-op; its indexes and comments already exist as well
            $statements = [reset($statements)];
        }
        $result = $this->runStatements($statements);
        $this->resetDdlCache($table->getName(), $table->getSchema());

        return $result;
    }

    /**
     * Create temporary table
     *
     * Foreign keys are skipped: PostgreSQL does not allow temporary tables to reference regular ones.
     *
     * @param \Magento\Framework\DB\Ddl\Table $table
     * @throws \Zend_Db_Exception
     * @return \Zend_Db_Statement_Pdo|void
     */
    public function createTemporaryTable(\Magento\Framework\DB\Ddl\Table $table)
    {
        $result = $this->runStatements($this->getCreateTableStatements($table, true));
        $this->resetDdlCache($table->getName(), $table->getSchema());

        return $result;
    }

    /**
     * Create temporary table like
     *
     * @param string $temporaryTableName
     * @param string $originTableName
     * @param bool $ifNotExists
     * @return \Zend_Db_Statement_Pdo
     */
    public function createTemporaryTableLike($temporaryTableName, $originTableName, $ifNotExists = false)
    {
        $sql = sprintf(
            'CREATE TEMPORARY TABLE%s %s (LIKE %s INCLUDING ALL)',
            $ifNotExists ? ' IF NOT EXISTS' : '',
            $this->quoteIdentifier($this->_getTableName($temporaryTableName)),
            $this->quoteIdentifier($this->_getTableName($originTableName))
        );
        $result = $this->query($sql);
        $this->resetDdlCache($temporaryTableName);

        return $result;
    }

    /**
     * Rename several tables
     *
     * The tables are renamed in order in one transaction, so pairs like a -> tmp, b -> a, tmp -> b swap tables.
     *
     * @param array $tablePairs array('oldName' => 'Name1', 'newName' => 'Name2')
     *
     * @return boolean
     * @throws \Zend_Db_Exception
     */
    public function renameTablesBatch(array $tablePairs)
    {
        if (count($tablePairs) == 0) {
            throw new \Zend_Db_Exception('Please provide tables for rename');
        }

        $statements = [];
        $tablesList  = [];
        foreach ($tablePairs as $pair) {
            $oldTableName  = $pair['oldName'];
            $newTableName  = $pair['newName'];
            list($newName) = $this->splitTableName($newTableName);
            $statements[] = sprintf(
                'ALTER TABLE %s RENAME TO %s',
                $this->quoteIdentifier($oldTableName),
                $this->quoteIdentifier($newName)
            );

            $tablesList[$oldTableName] = $oldTableName;
            $tablesList[$newTableName] = $newTableName;
        }

        $this->runStatements($statements);

        foreach ($tablesList as $table) {
            $this->resetDdlCache($table);
        }

        return true;
    }

    /**
     * Run statements in one transaction and return the result of the first one
     *
     * @param string[] $statements
     * @return \Zend_Db_Statement_Pdo|null
     * @throws \Exception
     */
    public function runStatements(array $statements)
    {
        $statements = array_values(array_filter($statements));
        $run = function () use ($statements) {
            $result = null;
            foreach ($statements as $statement) {
                // Internal statements may contain function bodies with ';', which query() would reject
                $statementResult = $this->_query($statement);
                $result = $result ?? $statementResult;
            }
            return $result;
        };
        if (count($statements) === 1) {
            return $run();
        }
        if ($this->_transactionLevel > 0) {
            // A nested rollBack() would mark the caller's transaction as rolled back; a savepoint does not
            return $this->runInSavepoint($run);
        }

        $this->beginTransaction();
        try {
            $result = $run();
            $this->commit();
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Get statements that create a table from DDL object
     *
     * @param Table $table
     * @param bool $temporary
     * @return string[]
     * @throws \Zend_Db_Exception
     */
    private function getCreateTableStatements(Table $table, bool $temporary): array
    {
        $tableName = $table->getName();
        $schemaName = $temporary ? null : $table->getSchema();
        $sqlFragment = $this->_getColumnsDefinition($table);
        if (!$temporary) {
            $sqlFragment = array_merge($sqlFragment, $this->_getForeignKeysDefinition($table));
        }

        $statements = [
            sprintf(
                "CREATE %sTABLE IF NOT EXISTS %s (\n%s\n)",
                $temporary ? 'TEMPORARY ' : '',
                $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
                implode(",\n", $sqlFragment)
            )
        ];
        $statements = array_merge($statements, $this->_getIndexesDefinition($table));

        if (!$temporary) {
            $statements[] = $this->getTableCommentSql($tableName, $this->getTableComment($table), $schemaName);
        }
        foreach ($table->getColumns() as $columnData) {
            $spec = $this->getColumnSpec($columnData);
            if (!$temporary && $spec['comment'] !== '') {
                $statements[] = $this->getColumnCommentSql(
                    $tableName,
                    $columnData['COLUMN_NAME'],
                    $spec['comment'],
                    $schemaName
                );
            }
            if ($spec['on_update']) {
                array_push(
                    $statements,
                    ...$this->getOnUpdateTriggerStatements($tableName, $columnData['COLUMN_NAME'], $schemaName)
                );
            }
            if ($spec['identity'] && $table->getOption('auto_increment') !== null) {
                $statements[] = sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s RESTART WITH %d',
                    $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
                    $this->quoteIdentifier($columnData['COLUMN_NAME']),
                    $table->getOption('auto_increment')
                );
            }
        }

        return $statements;
    }

    /**
     * Retrieve columns and primary keys definition array for create table
     *
     * @param Table $table
     * @return string[]
     * @throws \Zend_Db_Exception
     */
    protected function _getColumnsDefinition(Table $table)
    {
        $definition = [];
        $primary    = [];
        $columns    = $table->getColumns();
        if (empty($columns)) {
            throw new \Zend_Db_Exception('Table columns are not defined');
        }

        foreach ($columns as $columnData) {
            $columnDefinition = $this->_getColumnDefinition($columnData);
            if ($columnData['PRIMARY']) {
                $primary[$columnData['COLUMN_NAME']] = $columnData['PRIMARY_POSITION'];
            }

            $definition[] = sprintf(
                '  %s %s',
                $this->quoteIdentifier($columnData['COLUMN_NAME']),
                $columnDefinition
            );
        }

        // PRIMARY KEY
        if (!empty($primary)) {
            asort($primary, SORT_NUMERIC);
            $primary      = array_map([$this, 'quoteIdentifier'], array_keys($primary));
            $definition[] = sprintf('  PRIMARY KEY (%s)', implode(', ', $primary));
        }

        return $definition;
    }

    /**
     * Retrieve CREATE INDEX statements for create table
     *
     * Unlike MySQL, PostgreSQL creates indexes with separate statements.
     *
     * @param Table $table
     * @return string[]
     */
    protected function _getIndexesDefinition(Table $table)
    {
        $definition = [];
        $indexes = $table->getIndexes();
        foreach ($indexes as $indexData) {
            $indexType = !empty($indexData['TYPE']) ? strtolower($indexData['TYPE']) : AdapterInterface::INDEX_TYPE_INDEX;
            if ($indexType === AdapterInterface::INDEX_TYPE_PRIMARY) {
                continue;
            }
            $columns = [];
            foreach ($indexData['COLUMNS'] as $columnData) {
                $columns[] = $columnData['NAME'];
            }
            $definition[] = $this->getAddIndexSql(
                $table->getName(),
                $indexData['INDEX_NAME'],
                $columns,
                $indexType,
                $table->getSchema(),
                true
            );
        }

        return $definition;
    }

    /**
     * Get statement that creates an index
     *
     * Full-text indexes are GIN indexes over to_tsvector() of the concatenated columns.
     * Prefix lengths of MySQL indexes have no PostgreSQL counterpart and are ignored.
     *
     * @param string $tableName
     * @param string $indexName
     * @param string[] $fields
     * @param string $indexType
     * @param string|null $schemaName
     * @param bool $ifNotExists
     * @return string
     */
    public function getAddIndexSql($tableName, $indexName, array $fields, $indexType, $schemaName, $ifNotExists = false)
    {
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        if ($indexType === AdapterInterface::INDEX_TYPE_PRIMARY) {
            return sprintf(
                'ALTER TABLE %s ADD PRIMARY KEY (%s)',
                $table,
                implode(', ', array_map([$this, 'quoteIdentifier'], $fields))
            );
        }

        if ($indexType === AdapterInterface::INDEX_TYPE_FULLTEXT) {
            $parts = [];
            foreach ($fields as $field) {
                $parts[] = sprintf("COALESCE(%s::text, '')", $this->quoteIdentifier($field));
            }
            $columns = sprintf("to_tsvector('simple'::regconfig, %s)", implode(" || ' ' || ", $parts));
            $using = ' USING gin';
        } else {
            $columns = implode(', ', array_map([$this, 'quoteIdentifier'], $fields));
            $using = '';
        }

        return sprintf(
            'CREATE %sINDEX %s%s ON %s%s (%s)',
            $indexType === AdapterInterface::INDEX_TYPE_UNIQUE ? 'UNIQUE ' : '',
            $ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->quoteIdentifier($this->truncateIdentifier($indexName)),
            $table,
            $using,
            $columns
        );
    }

    /**
     * Retrieve table foreign keys definition array for create table
     *
     * @param Table $table
     * @return string[]
     */
    protected function _getForeignKeysDefinition(Table $table)
    {
        $definition = [];
        $relations  = $table->getForeignKeys();

        if (!empty($relations)) {
            foreach ($relations as $fkData) {
                $onDelete = $this->_getDdlAction($fkData['ON_DELETE']);
                $definition[] = sprintf(
                    '  CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
                    $this->quoteIdentifier($this->truncateIdentifier($fkData['FK_NAME'])),
                    $this->quoteIdentifier($fkData['COLUMN_NAME']),
                    $this->quoteIdentifier($fkData['REF_TABLE_NAME']),
                    $this->quoteIdentifier($fkData['REF_COLUMN_NAME']),
                    $onDelete
                );
            }
        }

        return $definition;
    }

    /**
     * Get the required table comment
     *
     * MySQL table options (engine, charset, row format...) have no PostgreSQL counterpart.
     *
     * @param Table $table
     * @return string
     * @throws \Zend_Db_Exception
     */
    private function getTableComment(Table $table): string
    {
        $comment = $table->getComment();
        if (empty($comment)) {
            throw new \Zend_Db_Exception('Comment for table is required and must be defined');
        }
        return $comment;
    }

    /**
     * Get COMMENT ON TABLE statement
     *
     * @param string $tableName
     * @param string $comment
     * @param string|null $schemaName
     * @return string
     */
    public function getTableCommentSql($tableName, $comment, $schemaName = null): string
    {
        return sprintf(
            'COMMENT ON TABLE %s IS %s',
            $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
            $comment !== '' && $comment !== null ? $this->quote($comment) : 'NULL'
        );
    }

    /**
     * Get COMMENT ON COLUMN statement
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $comment
     * @param string|null $schemaName
     * @return string
     */
    public function getColumnCommentSql($tableName, $columnName, $comment, $schemaName = null): string
    {
        return sprintf(
            'COMMENT ON COLUMN %s.%s IS %s',
            $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
            $this->quoteIdentifier($columnName),
            $comment !== '' && $comment !== null ? $this->quote($comment) : 'NULL'
        );
    }

    /**
     * Get column definition from description
     *
     * @param  array $options
     * @param  null|string $ddlType
     * @return string
     */
    public function getColumnDefinitionFromDescribe($options, $ddlType = null)
    {
        $columnInfo = $this->getColumnCreateByDescribe($options);
        foreach ($columnInfo['options'] as $key => $value) {
            $columnInfo[$key] = $value;
        }
        return $this->_getColumnDefinition($columnInfo, $ddlType);
    }

    /**
     * Retrieve column definition fragment
     *
     * Comments are not part of a PostgreSQL column definition and are set with COMMENT ON COLUMN.
     *
     * @param array $options
     * @param string $ddlType Table DDL Column type constant
     * @return string
     * @throws \Zend_Db_Exception
     */
    protected function _getColumnDefinition($options, $ddlType = null)
    {
        return $this->renderColumnSpec($this->getColumnSpec($options, $ddlType));
    }

    /**
     * Render column specification as column definition fragment
     *
     * @param array $spec
     * @return string
     */
    private function renderColumnSpec(array $spec): string
    {
        if ($spec['identity']) {
            return $spec['type'] . ' GENERATED BY DEFAULT AS IDENTITY NOT NULL';
        }
        return sprintf(
            '%s%s%s',
            $spec['type'],
            $spec['nullable'] ? ' NULL' : ' NOT NULL',
            $spec['default'] !== null ? ' DEFAULT ' . $spec['default'] : ''
        );
    }

    /**
     * Build column specification from universal column definition
     *
     * Returns type, nullable, default (SQL or null), identity, comment and on_update
     * (whether the column must be set to the current timestamp on update).
     *
     * MySQL unsigned integers are widened to the next signed type so that they keep their range
     * (bigint stays bigint), like pgloader does.
     *
     * @param array $options
     * @param string|null $ddlType
     * @return array
     * @throws \Zend_Db_Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function getColumnSpec(array $options, $ddlType = null): array
    {
        // convert keys to uppercase
        $options    = array_change_key_case($options, CASE_UPPER);
        $cNullable  = true;
        $cDefault   = false;
        $cIdentity  = false;
        $onUpdate   = false;

        // detect and validate column type
        if ($ddlType === null) {
            $ddlType = $this->_getDdlType($options);
        }

        if (empty($ddlType) || !isset($this->_ddlColumnTypes[$ddlType])) {
            throw new \Zend_Db_Exception('Invalid column definition data');
        }

        // column size
        $cType = $this->_ddlColumnTypes[$ddlType];
        switch ($ddlType) {
            case Table::TYPE_SMALLINT:
                if (!empty($options['UNSIGNED'])) {
                    $cType = 'integer';
                }
                break;
            case Table::TYPE_INTEGER:
                if (!empty($options['UNSIGNED'])) {
                    $cType = 'bigint';
                }
                break;
            case Table::TYPE_DECIMAL:
            case Table::TYPE_NUMERIC:
                $precision  = 10;
                $scale      = 0;
                $match      = [];
                if (!empty($options['LENGTH']) && preg_match('#^\(?(\d+),(\d+)\)?$#', $options['LENGTH'], $match)) {
                    $precision  = $match[1];
                    $scale      = $match[2];
                } else {
                    if (isset($options['SCALE']) && is_numeric($options['SCALE'])) {
                        $scale = $options['SCALE'];
                    }
                    if (isset($options['PRECISION']) && is_numeric($options['PRECISION'])) {
                        $precision = $options['PRECISION'];
                    }
                }
                $cType .= sprintf('(%d,%d)', $precision, $scale);
                break;
            case Table::TYPE_TEXT:
                if (empty($options['LENGTH'])) {
                    $length = Table::DEFAULT_TEXT_SIZE;
                } else {
                    $length = $this->_parseTextSize($options['LENGTH']);
                }
                if ($length <= 255) {
                    $cType = sprintf('varchar(%d)', $length);
                }
                break;
        }

        if (array_key_exists('DEFAULT', $options)) {
            $cDefault = $options['DEFAULT'];
        }
        if (array_key_exists('NULLABLE', $options)) {
            $cNullable = (bool)$options['NULLABLE'];
        }
        if (!empty($options['IDENTITY']) || !empty($options['AUTO_INCREMENT'])) {
            $cIdentity = true;
        }

        /*  For cases when tables created from createTableByDdl()
         *  where default value can be quoted already.
         *  We need to avoid "double-quoting" here
         */
        if ($cDefault !== null && is_string($cDefault) && strlen($cDefault)) {
            $cDefault = str_replace("'", '', $cDefault);
        }
        // false means "no default" (Ddl\Table uses it when no default is given)
        if ($cDefault === true) {
            $cDefault = 1;
        }

        // prepare default value string
        if ($ddlType == Table::TYPE_TIMESTAMP || $ddlType == Table::TYPE_DATETIME) {
            if ($cDefault === null) {
                $cDefault = new \Zend_Db_Expr('NULL');
            } elseif ($cDefault == Table::TIMESTAMP_INIT || $cDefault === 'CURRENT_TIMESTAMP') {
                $cDefault = new \Zend_Db_Expr('CURRENT_TIMESTAMP');
            } elseif ($cDefault == Table::TIMESTAMP_UPDATE) {
                // MySQL stores the zero date on insert, which PostgreSQL cannot represent
                $cDefault = $cNullable ? new \Zend_Db_Expr('NULL') : new \Zend_Db_Expr('CURRENT_TIMESTAMP');
                $onUpdate = true;
            } elseif ($cDefault == Table::TIMESTAMP_INIT_UPDATE) {
                $cDefault = new \Zend_Db_Expr('CURRENT_TIMESTAMP');
                $onUpdate = true;
            } elseif ($cNullable && !$cDefault) {
                $cDefault = new \Zend_Db_Expr('NULL');
            } elseif (!$cDefault) {
                $cDefault = false;
            }
        } elseif ($cDefault === null) {
            $cDefault = $cNullable ? new \Zend_Db_Expr('NULL') : false;
        }

        if ($cDefault === false || $cIdentity) {
            $defaultSql = null;
        } elseif ($cDefault instanceof \Zend_Db_Expr) {
            $defaultSql = $cDefault->__toString();
        } else {
            $defaultSql = $this->quote($cDefault);
        }

        return [
            'type'      => $cType,
            'nullable'  => $cNullable,
            'default'   => $defaultSql,
            'identity'  => $cIdentity,
            'comment'   => empty($options['COMMENT']) ? '' : (string)$options['COMMENT'],
            'on_update' => $onUpdate,
        ];
    }

    /**
     * Get the value MySQL implicitly stores in a NOT NULL column without default
     *
     * @param string $type
     * @return string
     */
    public function getZeroValueSql(string $type): string
    {
        if (preg_match('/^(smallint|integer|bigint|numeric|double)/', $type)) {
            return "'0'";
        }
        if ($type === 'bytea') {
            return "''::bytea";
        }
        if ($type === 'date' || $type === 'timestamp') {
            return "'1970-01-01 00:00:00'";
        }
        return "''";
    }

    /**
     * Get statements that make a column follow "ON UPDATE CURRENT_TIMESTAMP"
     *
     * @param string $tableName
     * @param string $columnName
     * @param string|null $schemaName
     * @return string[]
     */
    public function getOnUpdateTriggerStatements($tableName, $columnName, $schemaName = null): array
    {
        $statements = [];
        $functionExists = $this->fetchOne(
            'SELECT to_regprocedure(?) IS NOT NULL',
            [self::ON_UPDATE_TIMESTAMP_FUNCTION . '()']
        );
        if (!$functionExists) {
            // The column is passed as trigger argument; jsonb lets one function set any column of NEW
            $statements[] = 'CREATE OR REPLACE FUNCTION ' . self::ON_UPDATE_TIMESTAMP_FUNCTION . '()'
                . ' RETURNS trigger LANGUAGE plpgsql AS $function$'
                . ' BEGIN'
                . ' IF NEW IS DISTINCT FROM OLD'
                . ' AND (to_jsonb(NEW) -> TG_ARGV[0]) IS NOT DISTINCT FROM (to_jsonb(OLD) -> TG_ARGV[0]) THEN'
                . ' NEW := jsonb_populate_record(NEW, jsonb_build_object(TG_ARGV[0], LOCALTIMESTAMP));'
                . ' END IF;'
                . ' RETURN NEW;'
                . ' END'
                . ' $function$';
        }
        $statements[] = $this->getDropOnUpdateTriggerSql($tableName, $columnName, $schemaName);
        $statements[] = sprintf(
            'CREATE TRIGGER %s BEFORE UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION %s(%s)',
            $this->quoteIdentifier($this->getOnUpdateTriggerName($tableName, $columnName)),
            $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
            self::ON_UPDATE_TIMESTAMP_FUNCTION,
            $this->quote($columnName)
        );

        return $statements;
    }

    /**
     * Get statement that drops "ON UPDATE CURRENT_TIMESTAMP" trigger of a column
     *
     * @param string $tableName
     * @param string $columnName
     * @param string|null $schemaName
     * @return string
     */
    public function getDropOnUpdateTriggerSql($tableName, $columnName, $schemaName = null): string
    {
        return sprintf(
            'DROP TRIGGER IF EXISTS %s ON %s',
            $this->quoteIdentifier($this->getOnUpdateTriggerName($tableName, $columnName)),
            $this->quoteIdentifier($this->_getTableName($tableName, $schemaName))
        );
    }

    /**
     * Get name of "ON UPDATE CURRENT_TIMESTAMP" trigger of a column
     *
     * @param string $tableName
     * @param string $columnName
     * @return string
     */
    private function getOnUpdateTriggerName($tableName, $columnName): string
    {
        return $this->shortenEntityName('trg_' . $tableName . '_' . $columnName . '_on_update', 'trg_');
    }

    /**
     * Get the columns that are set to the current timestamp on update, i.e. MySQL "ON UPDATE CURRENT_TIMESTAMP"
     *
     * Recognizes the triggers created by this adapter and those created by pgloader, whose function only
     * assigns the current time to columns of NEW (e.g. "NEW.updated_at = now();"). Other triggers are ignored.
     *
     * Returns column name => [
     *     TRIGGER_NAME => string; the trigger that maintains the column
     *     COLUMNS      => string[]; all columns maintained by the trigger
     *     IS_ADAPTER   => bool; the trigger was created by this adapter (one trigger per column)
     * ]
     *
     * @param string $tableName
     * @param string|null $schemaName
     * @return array
     */
    public function getOnUpdateTimestampColumns($tableName, $schemaName = null): array
    {
        // tgtype bits: 2 = BEFORE, 16 = UPDATE
        $sql = sprintf(
            'SELECT t.tgname, p.proname, p.prosrc, pg_get_triggerdef(t.oid) AS definition'
            . ' FROM pg_trigger AS t JOIN pg_proc AS p ON p.oid = t.tgfoid'
            . ' WHERE t.tgrelid = %s AND NOT t.tgisinternal AND (t.tgtype & 18) = 18'
            . ' ORDER BY t.tgname',
            $this->getRegclassSql($tableName, $schemaName)
        );
        $result = [];
        foreach ($this->fetchAll($sql) as $row) {
            $isAdapter = $row['proname'] === self::ON_UPDATE_TIMESTAMP_FUNCTION;
            if ($isAdapter) {
                if (!preg_match('/\(\'((?:[^\']|\'\')*)\'\)\s*$/', $row['definition'], $matches)) {
                    continue;
                }
                $columns = [str_replace("''", "'", $matches[1])];
            } else {
                $assignment = '/NEW\.("(?:[^"]|"")+"|\w+)\s*:?=\s*'
                    . '(?:now\(\)|CURRENT_TIMESTAMP|LOCALTIMESTAMP|clock_timestamp\(\)|transaction_timestamp\(\))\s*;/i';
                $rest = preg_replace(
                    ['/--[^\n]*/', $assignment, '/\b(BEGIN|END|RETURN\s+NEW)\b/i', '/[\s;]+/'],
                    '',
                    $row['prosrc']
                );
                if ($rest !== '' || !preg_match_all($assignment, $row['prosrc'], $matches)) {
                    continue;
                }
                $columns = array_map(
                    fn ($name) => $name[0] === '"' ? str_replace('""', '"', substr($name, 1, -1)) : strtolower($name),
                    $matches[1]
                );
            }
            foreach ($columns as $column) {
                $result[$column] = [
                    'TRIGGER_NAME' => $row['tgname'],
                    'COLUMNS'      => $columns,
                    'IS_ADAPTER'   => $isAdapter,
                ];
            }
        }

        return $result;
    }

    /**
     * Drop table from database
     *
     * @param string $tableName
     * @param string $schemaName
     * @return true
     */
    public function dropTable($tableName, $schemaName = null)
    {
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        $query = 'DROP TABLE IF EXISTS ' . $table;
        $this->query($query);
        $this->resetDdlCache($tableName, $schemaName);
        $this->getSchemaListener()->dropTable($tableName);
        return true;
    }

    /**
     * Drop temporary table from database
     *
     * Without a schema only a temporary table is dropped, never a regular table of the same name.
     *
     * @param string $tableName
     * @param string $schemaName
     * @return boolean
     */
    public function dropTemporaryTable($tableName, $schemaName = null)
    {
        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName ?? 'pg_temp'));
        $query = 'DROP TABLE IF EXISTS ' . $table;
        $this->query($query);
        $this->resetDdlCache($tableName, $schemaName);

        return true;
    }

    /**
     * Truncate a table
     *
     * @param string $tableName
     * @param string $schemaName
     * @return $this
     * @throws \Zend_Db_Exception
     */
    public function truncateTable($tableName, $schemaName = null)
    {
        if (!$this->isTableExists($tableName, $schemaName)) {
            throw new \Zend_Db_Exception(sprintf('Table "%s" does not exist', $tableName));
        }

        $table = $this->quoteIdentifier($this->_getTableName($tableName, $schemaName));
        // MySQL resets auto_increment on truncate
        $query = 'TRUNCATE TABLE ' . $table . ' RESTART IDENTITY';
        $this->query($query);

        return $this;
    }

    /**
     * Check is a table exists
     *
     * @param string $tableName
     * @param string $schemaName
     * @return bool
     */
    public function isTableExists($tableName, $schemaName = null)
    {
        $cacheKey = $this->_getTableName($tableName, $schemaName);

        $ddl = $this->loadDdlCache($cacheKey, self::DDL_EXISTS);
        if ($ddl !== false) {
            return true;
        }

        $sql = sprintf(
            'SELECT COUNT(1) AS tbl_exists FROM pg_class WHERE oid = %s AND relkind IN (\'r\', \'p\', \'v\', \'m\', \'f\')',
            $this->getRegclassSql($tableName, $schemaName)
        );
        $ddl = $this->rawFetchRow($sql, 'tbl_exists');
        if ($ddl) {
            $this->saveDdlCache($cacheKey, self::DDL_EXISTS, $ddl);
            return true;
        }

        return false;
    }

    /**
     * Rename table
     *
     * @param string $oldTableName
     * @param string $newTableName
     * @param string $schemaName
     * @return true
     * @throws \Zend_Db_Exception
     */
    public function renameTable($oldTableName, $newTableName, $schemaName = null)
    {
        if (!$this->isTableExists($oldTableName, $schemaName)) {
            throw new \Zend_Db_Exception(sprintf('Table "%s" does not exist', $oldTableName));
        }
        if ($this->isTableExists($newTableName, $schemaName)) {
            throw new \Zend_Db_Exception(sprintf('Table "%s" already exists', $newTableName));
        }
        $this->getSchemaListener()->renameTable($oldTableName, $newTableName);

        $query = sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $this->quoteIdentifier($this->_getTableName($oldTableName, $schemaName)),
            $this->quoteIdentifier($newTableName)
        );
        $this->query($query);
        $this->resetDdlCache($oldTableName, $schemaName);
        $this->resetDdlCache($newTableName, $schemaName);

        return true;
    }

    /**
     * Add new index to table name
     *
     * An existing index with the same name is replaced. When a unique or primary key cannot be created
     * because of duplicate rows, the duplicates are removed and the key is created again, as in MySQL adapter.
     *
     * @param string $tableName
     * @param string $indexName
     * @param string|array $fields the table column name or array of ones
     * @param string $indexType the index type
     * @param string $schemaName
     * @return \Zend_Db_Statement_Interface
     * @throws \Zend_Db_Exception
     * @throws \Exception
     */
    public function addIndex(
        $tableName,
        $indexName,
        $fields,
        $indexType = AdapterInterface::INDEX_TYPE_INDEX,
        $schemaName = null
    ) {
        $this->getSchemaListener()->addIndex(
            $tableName,
            $indexName,
            $fields,
            $indexType
        );
        $columns = $this->describeTable($tableName, $schemaName);
        $keyList = $this->getIndexList($tableName, $schemaName);
        $indexType = strtolower((string)$indexType);
        if (!in_array($indexType, [
            AdapterInterface::INDEX_TYPE_PRIMARY,
            AdapterInterface::INDEX_TYPE_UNIQUE,
            AdapterInterface::INDEX_TYPE_FULLTEXT
        ], true)) {
            $indexType = AdapterInterface::INDEX_TYPE_INDEX;
        }

        if (!is_array($fields)) {
            $fields = [$fields];
        }

        foreach ($fields as $field) {
            if (!isset($columns[$field])) {
                $msg = sprintf(
                    'There is no field "%s" that you are trying to create an index on "%s"',
                    $field,
                    $tableName
                );
                throw new \Zend_Db_Exception($msg);
            }
        }

        $statements = [];
        $existingKey = $indexType === AdapterInterface::INDEX_TYPE_PRIMARY
            ? 'PRIMARY'
            : strtoupper($this->truncateIdentifier($indexName));
        if (isset($keyList[$existingKey])) {
            $statements[] = $this->getDropIndexSql($keyList[$existingKey], $tableName, $schemaName);
        }
        $statements[] = $this->getAddIndexSql($tableName, $indexName, $fields, $indexType, $schemaName);

        $isUnique = in_array($indexType, [AdapterInterface::INDEX_TYPE_PRIMARY, AdapterInterface::INDEX_TYPE_UNIQUE]);
        try {
            $result = $this->runInSavepoint(fn () => $this->runStatements($statements));
        } catch (DuplicateException $e) {
            if (!$isUnique) {
                throw $e;
            }
            $this->removeDuplicateRows($tableName, $fields, $schemaName);
            $result = $this->runStatements($statements);
        }

        $this->resetDdlCache($tableName, $schemaName);

        return $result;
    }

    /**
     * Drop the index from table
     *
     * @param string $tableName
     * @param string $keyName
     * @param string $schemaName
     * @return true|\Zend_Db_Statement_Interface
     */
    public function dropIndex($tableName, $keyName, $schemaName = null)
    {
        $indexList = $this->getIndexList($tableName, $schemaName);
        $indexType = 'index';
        $keyName = $keyName !== null ? strtoupper($this->truncateIdentifier($keyName)) : '';
        if (!isset($indexList[$keyName])) {
            return true;
        }

        if ($keyName == 'PRIMARY') {
            $indexType = 'primary';
        } elseif (strpos($keyName, 'UNQ_') !== false) {
            $indexType = 'unique';
        }

        $sql = $this->getDropIndexSql($indexList[$keyName], $tableName, $schemaName);
        $this->getSchemaListener()->dropIndex($tableName, $keyName, $indexType);
        $this->resetDdlCache($tableName, $schemaName);

        return $this->rawQuery($sql);
    }

    /**
     * Get statement that drops an index or the constraint it backs
     *
     * @param array $index getIndexList() item
     * @param string $tableName
     * @param string|null $schemaName
     * @return string
     */
    public function getDropIndexSql(array $index, $tableName, $schemaName = null): string
    {
        if ($index['IS_CONSTRAINT']) {
            return sprintf(
                'ALTER TABLE %s DROP CONSTRAINT %s',
                $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
                $this->quoteIdentifier($index['KEY_NAME'])
            );
        }
        // An index lives in the schema of its table
        return sprintf('DROP INDEX %s', $this->quoteIdentifier($this->_getTableName($index['KEY_NAME'], $schemaName)));
    }

    /**
     * Add new Foreign Key to table
     *
     * If Foreign Key with same name is exist - it will be deleted
     *
     * @param string $fkName
     * @param string $tableName
     * @param string $columnName
     * @param string $refTableName
     * @param string $refColumnName
     * @param string $onDelete
     * @param bool $purge trying remove invalid data
     * @param string $schemaName
     * @param string $refSchemaName
     * @return \Zend_Db_Statement_Interface
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function addForeignKey(
        $fkName,
        $tableName,
        $columnName,
        $refTableName,
        $refColumnName,
        $onDelete = AdapterInterface::FK_ACTION_CASCADE,
        $purge = false,
        $schemaName = null,
        $refSchemaName = null
    ) {
        $this->dropForeignKey($tableName, $fkName, $schemaName);

        if ($purge) {
            $this->purgeOrphanRecords($tableName, $columnName, $refTableName, $refColumnName, $onDelete);
        }

        $query = sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
            $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
            $this->quoteIdentifier($this->truncateIdentifier($fkName)),
            $this->quoteIdentifier($columnName),
            $this->quoteIdentifier($this->_getTableName($refTableName, $refSchemaName)),
            $this->quoteIdentifier($refColumnName)
        );

        if ($onDelete !== null) {
            $query .= ' ON DELETE ' . strtoupper($onDelete);
        }

        $this->getSchemaListener()->addForeignKey(
            $fkName,
            $tableName,
            $columnName,
            $refTableName,
            $refColumnName,
            $onDelete
        );

        $result = $this->rawQuery($query);
        $this->resetDdlCache($tableName, $schemaName);
        return $result;
    }

    /**
     * Format Date to internal database date format
     *
     * @param int|string|\DateTimeInterface $date
     * @param bool $includeTime
     * @return \Zend_Db_Expr
     */
    public function formatDate($date, $includeTime = true)
    {
        $date = $this->dateTime->formatDate($date, $includeTime);

        if ($date === null) {
            return new \Zend_Db_Expr('NULL');
        }

        return new \Zend_Db_Expr($this->quote($date));
    }

    /**
     * Run additional environment before setup
     *
     * Disables foreign key checks (and triggers) for the session when the user is a superuser, and keeps
     * explicit 0 in identity columns, like MySQL adapter does with FOREIGN_KEY_CHECKS and NO_AUTO_VALUE_ON_ZERO.
     *
     * @return $this
     */
    public function startSetup()
    {
        $this->isSetupMode = true;
        if (!$this->isReplicationRoleChanged
            && $this->fetchOne('SELECT rolsuper::int FROM pg_roles WHERE rolname = current_user')
        ) {
            $this->rawQuery("SET session_replication_role = 'replica'");
            $this->isReplicationRoleChanged = true;
        }
        return $this;
    }

    /**
     * Run additional environment after setup
     *
     * @return $this
     */
    public function endSetup()
    {
        $this->isSetupMode = false;
        if ($this->isReplicationRoleChanged) {
            $this->rawQuery('SET session_replication_role = DEFAULT');
            $this->isReplicationRoleChanged = false;
        }
        return $this;
    }

    /**
     * Build SQL statement for condition
     *
     * If $condition integer or string - exact value will be filtered ('eq' condition)
     *
     * If $condition is array is - one of the following structures is expected:
     * - array("from" => $fromValue, "to" => $toValue)
     * - array("eq" => $equalValue)
     * - array("neq" => $notEqualValue)
     * - array("like" => $likeValue)
     * - array("in" => array($inValues))
     * - array("nin" => array($notInValues))
     * - array("notnull" => $valueIsNotNull)
     * - array("null" => $valueIsNull)
     * - array("gt" => $greaterValue)
     * - array("lt" => $lessValue)
     * - array("gteq" => $greaterOrEqualValue)
     * - array("lteq" => $lessOrEqualValue)
     * - array("finset" => $valueInSet)
     * - array("nfinset" => $valueNotInSet)
     * - array("regexp" => $regularExpression)
     * - array("seq" => $stringValue)
     * - array("sneq" => $stringValue)
     *
     * If non matched - sequential array is expected and OR conditions
     * will be built using above mentioned structure
     *
     * LIKE and REGEXP are case-insensitive and accept any column type, as in MySQL.
     *
     * @param string $fieldName
     * @param integer|string|array $condition
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function prepareSqlCondition($fieldName, $condition)
    {
        $conditionKeyMap = [
            'eq'            => "{{fieldName}} = ?",
            'neq'           => "{{fieldName}} != ?",
            'like'          => "CAST({{fieldName}} AS TEXT) ILIKE ?",
            'nlike'         => "CAST({{fieldName}} AS TEXT) NOT ILIKE ?",
            'in'            => "{{fieldName}} IN(?)",
            'nin'           => "{{fieldName}} NOT IN(?)",
            'is'            => "{{fieldName}} IS ?",
            'notnull'       => "{{fieldName}} IS NOT NULL",
            'null'          => "{{fieldName}} IS NULL",
            'gt'            => "{{fieldName}} > ?",
            'lt'            => "{{fieldName}} < ?",
            'gteq'          => "{{fieldName}} >= ?",
            'lteq'          => "{{fieldName}} <= ?",
            'finset'        => "CAST(? AS TEXT) = ANY(string_to_array(CAST({{fieldName}} AS TEXT), ','))",
            'nfinset'       => "NOT (CAST(? AS TEXT) = ANY(string_to_array(CAST({{fieldName}} AS TEXT), ',')))",
            'regexp'        => "CAST({{fieldName}} AS TEXT) ~* ?",
            'from'          => "{{fieldName}} >= ?",
            'to'            => "{{fieldName}} <= ?",
            'seq'           => null,
            'sneq'          => null,
            'ntoa'          => "host('0.0.0.0'::inet + CAST({{fieldName}} AS BIGINT)) LIKE ?",
        ];

        $query = '';
        if (is_array($condition)) {
            $key = key(array_intersect_key($condition, $conditionKeyMap));

            if (isset($condition['from']) || isset($condition['to'])) {
                if (isset($condition['from'])) {
                    $from  = $this->_prepareSqlDateCondition($condition, 'from');
                    $query = $this->_prepareQuotedSqlCondition($conditionKeyMap['from'], $from, $fieldName);
                }

                if (isset($condition['to'])) {
                    $query .= empty($query) ? '' : ' AND ';
                    $to     = $this->_prepareSqlDateCondition($condition, 'to');
                    $query = $query . $this->_prepareQuotedSqlCondition($conditionKeyMap['to'], $to, $fieldName);
                }
            } elseif (array_key_exists($key, $conditionKeyMap)) {
                $value = $condition[$key];
                if (($key == 'seq') || ($key == 'sneq')) {
                    $key = $this->_transformStringSqlCondition($key, $value);
                }
                if (($key == 'in' || $key == 'nin') && ($value === null || is_string($value) || is_array($value))) {
                    // Drop empty items of the list ('' or null, e.g. an empty attribute value used as option id):
                    // MySQL compares '' (also what null is quoted as) with a number as 0, PostgreSQL rejects it.
                    $isEmptyList = is_array($value) && !$value;
                    $value = array_values(array_filter(
                        is_array($value) ? $value : explode(',', (string)$value),
                        fn ($item) => $item !== null && (!is_string($item) || trim($item) !== '')
                    ));
                    if (!$value && !$isEmptyList && $key == 'nin') {
                        // NOT IN(NULL) would match nothing, while excluding only empty values excludes nothing
                        return '(1 = 1)';
                    }
                }
                $query = $this->_prepareQuotedSqlCondition($conditionKeyMap[$key], $value, $fieldName);
            } else {
                $queries = [];
                foreach ($condition as $orCondition) {
                    $queries[] = sprintf('(%s)', $this->prepareSqlCondition($fieldName, $orCondition));
                }

                $query = sprintf('(%s)', implode(' OR ', $queries));
            }
        } else {
            $query = $this->_prepareQuotedSqlCondition($conditionKeyMap['eq'], (string)$condition, $fieldName);
        }

        return $query;
    }

    /**
     * Prepare Sql condition
     *
     * @param  string $text Condition value
     * @param  mixed $value
     * @param  string $fieldName
     * @return string
     */
    protected function _prepareQuotedSqlCondition($text, $value, $fieldName)
    {
        $text = str_replace('{{fieldName}}', (string)$fieldName, (string)$text);
        $sql = $this->quoteInto($text, $value);
        return $sql;
    }

    /**
     * Transforms sql condition key 'seq' / 'sneq' that is used for comparing string values to its analog:
     * - 'null' / 'notnull' for empty strings
     * - 'eq' / 'neq' for non-empty strings
     *
     * @param string $conditionKey
     * @param mixed $value
     * @return string
     */
    protected function _transformStringSqlCondition($conditionKey, $value)
    {
        $value = (string) $value;
        if ($value == '') {
            return ($conditionKey == 'seq') ? 'null' : 'notnull';
        } else {
            return ($conditionKey == 'seq') ? 'eq' : 'neq';
        }
    }

    /**
     * Prepare value for save in column
     *
     * Return converted to column data type value
     *
     * @param array $column the column describe array
     * @param mixed $value
     * @return mixed
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function prepareColumnValue(array $column, $value)
    {
        if ($value instanceof \Zend_Db_Expr) {
            return $value;
        }
        if ($value instanceof Parameter) {
            return $value;
        }

        // return original value if invalid column describe data
        if (!isset($column['DATA_TYPE'])) {
            return $value;
        }

        // return null
        if ($value === null && $column['NULLABLE']) {
            return null;
        }

        switch ($column['DATA_TYPE']) {
            case 'bool':
            case 'smallint':
            case 'int':
                $value = (int)$value;
                break;
            case 'bigint':
                if (!is_integer($value)) {
                    $value = sprintf('%.0f', (float)$value);
                }
                break;

            case 'decimal':
                $precision  = 10;
                $scale      = 0;
                if (isset($column['SCALE'])) {
                    $scale = $column['SCALE'];
                }
                if (isset($column['PRECISION'])) {
                    $precision = $column['PRECISION'];
                }
                $format = sprintf('%%%d.%dF', $precision - $scale, $scale);
                $value  = (float)sprintf($format, $value);
                break;

            case 'float':
                $value  = (float)sprintf('%F', $value);
                break;

            case 'date':
                $value  = $this->formatDate($value, false);
                break;
            case 'datetime':
            case 'timestamp':
                $value  = $this->formatDate($value);
                break;

            case 'char':
            case 'varchar':
            case 'text':
                $value  = (string)$value;
                if ($column['NULLABLE'] && $value == '') {
                    $value = null;
                }
                break;

            case 'blob':
                $value = $this->wrapBinaryValue($value);
                break;
        }

        return $value;
    }

    /**
     * Generate fragment of SQL, that check condition and return true or false value
     *
     * Callers pass the result to Zend_Db_Select::order() as a string, see asFunctionCall().
     *
     * @param \Zend_Db_Expr|\Magento\Framework\DB\Select|string $expression
     * @param string $true true value
     * @param string $false false value
     * @return \Zend_Db_Expr
     */
    public function getCheckSql($expression, $true, $false)
    {
        // MySQL accepts a number as condition (e.g. getCheckSql('selection.selection_can_change_qty', ...))
        if ($expression instanceof \Zend_Db_Expr || $expression instanceof \Zend_Db_Select) {
            $condition = $this->functionMapper->toCondition(sprintf('(%s)', $expression));
        } else {
            $condition = $this->functionMapper->toCondition((string)$expression);
        }
        $expression = sprintf("CASE WHEN %s THEN %s ELSE %s END", $condition, $true, $false);

        return new \Zend_Db_Expr($this->asFunctionCall($expression));
    }

    /**
     * Present an SQL expression as a function call
     *
     * Callers often pass the adapter's SQL fragments to Zend_Db_Select::order() or columns() as strings, e.g.
     * $select->order($connection->getCheckSql(...) . ' ASC'). Zend only treats a string as an expression when it
     * looks like a function call (MySQL fragments are IF(...), CONCAT(...)), otherwise it quotes the whole string
     * as an identifier. A single-argument COALESCE() returns its argument unchanged.
     *
     * For the same reason fragments never contain " AS ": columns() splits a string at it into expression and alias
     * (e.g. sprintf('MAX(%s)', $connection->getDateFormatSql(...)) in reports), so casts use the :: syntax.
     *
     * @param string $expression
     * @return string
     */
    private function asFunctionCall(string $expression): string
    {
        return sprintf('COALESCE(%s)', $expression);
    }

    /**
     * Returns valid IFNULL expression
     *
     * @param \Zend_Db_Expr|\Magento\Framework\DB\Select|string $expression
     * @param string|int $value OPTIONAL. Applies when $expression is NULL
     * @return \Zend_Db_Expr
     */
    public function getIfNullSql($expression, $value = 0)
    {
        // An untyped literal takes the type of the expression; a bare number fails against text columns
        if (is_int($value) || is_float($value)) {
            $value = $this->quote($value);
        }
        if ($expression instanceof \Zend_Db_Expr || $expression instanceof \Zend_Db_Select) {
            $expression = sprintf("COALESCE((%s), %s)", $expression, $value);
        } else {
            $expression = sprintf("COALESCE(%s, %s)", $expression, $value);
        }

        return new \Zend_Db_Expr($expression);
    }

    /**
     * Generates case SQL fragment
     *
     * Generate fragment of SQL, that check value against multiple condition cases
     * and return different result depends on them
     *
     * @param string $valueName Name of value to check
     * @param array $casesResults Cases and results
     * @param string $defaultValue value to use if value doesn't confirm to any cases
     * @return \Zend_Db_Expr
     */
    public function getCaseSql($valueName, $casesResults, $defaultValue = null)
    {
        $expression = 'CASE ' . $valueName;
        foreach ($casesResults as $case => $result) {
            $expression .= ' WHEN ' . $case . ' THEN ' . $result;
        }
        if ($defaultValue !== null) {
            $expression .= ' ELSE ' . $defaultValue;
        }
        $expression .= ' END';

        return new \Zend_Db_Expr($expression);
    }

    /**
     * Generate fragment of SQL, that combine together (concatenate) the results from data array
     *
     * All arguments in data must be quoted. Without separator the result is NULL if any argument is NULL,
     * as with MySQL CONCAT(); with separator NULL arguments are skipped, as with CONCAT_WS().
     *
     * @param string[] $data
     * @param string $separator concatenate with separator
     * @return \Zend_Db_Expr
     */
    public function getConcatSql(array $data, $separator = null)
    {
        if (!empty($separator)) {
            return new \Zend_Db_Expr(sprintf('CONCAT_WS(%s, %s)', $this->quote($separator), implode(', ', $data)));
        }
        $parts = array_map(fn ($part) => sprintf('(%s)::text', $part), $data);
        return new \Zend_Db_Expr($this->asFunctionCall(implode(' || ', $parts)));
    }

    /**
     * Generate fragment of SQL that returns length of character string
     *
     * The string argument must be quoted. Like MySQL LENGTH() the length is in bytes.
     *
     * @param string $string
     * @return \Zend_Db_Expr
     */
    public function getLengthSql($string)
    {
        return new \Zend_Db_Expr(sprintf('OCTET_LENGTH((%s)::text)', $string));
    }

    /**
     * Generate least SQL fragment
     *
     * Generate fragment of SQL, that compare with two or more arguments, and returns the smallest
     * (minimum-valued) argument
     * All arguments in data must be quoted
     *
     * @param string[] $data
     * @return \Zend_Db_Expr
     */
    public function getLeastSql(array $data)
    {
        return new \Zend_Db_Expr(sprintf('LEAST(%s)', implode(', ', $data)));
    }

    /**
     * Generate greatest SQL fragment
     *
     * Generate fragment of SQL, that compare with two or more arguments, and returns the largest
     * (maximum-valued) argument
     * All arguments in data must be quoted
     *
     * @param string[] $data
     * @return \Zend_Db_Expr
     */
    public function getGreatestSql(array $data)
    {
        return new \Zend_Db_Expr(sprintf('GREATEST(%s)', implode(', ', $data)));
    }

    /**
     * Get Interval Unit SQL fragment
     *
     * @param int $interval
     * @param string $unit
     * @return string
     * @throws \Zend_Db_Exception
     */
    protected function _getIntervalUnitSql($interval, $unit)
    {
        if (!isset($this->_intervalUnits[$unit])) {
            throw new \Zend_Db_Exception(sprintf('Undefined interval unit "%s" specified', $unit));
        }

        return sprintf("INTERVAL '%d %s'", $interval, $this->_intervalUnits[$unit]);
    }

    /**
     * Add time values (intervals) to a date value
     *
     * @see INTERVAL_* constants for $unit
     *
     * @param \Zend_Db_Expr|string $date quoted field name or SQL statement
     * @param int $interval
     * @param string $unit
     * @return \Zend_Db_Expr
     */
    public function getDateAddSql($date, $interval, $unit)
    {
        $expr = $this->asFunctionCall(
            sprintf('(%s)::timestamp + %s', $date, $this->_getIntervalUnitSql($interval, $unit))
        );
        return new \Zend_Db_Expr($expr);
    }

    /**
     * Subtract time values (intervals) to a date value
     *
     * @see INTERVAL_* constants for $expr
     *
     * @param \Zend_Db_Expr|string $date quoted field name or SQL statement
     * @param int|string $interval
     * @param string $unit
     * @return \Zend_Db_Expr
     */
    public function getDateSubSql($date, $interval, $unit)
    {
        $expr = $this->asFunctionCall(
            sprintf('(%s)::timestamp - %s', $date, $this->_getIntervalUnitSql($interval, $unit))
        );
        return new \Zend_Db_Expr($expr);
    }

    /**
     * Format date as specified
     *
     * Supported format Specifier
     *
     * %H   Hour (00..23)
     * %i   Minutes, numeric (00..59)
     * %s   Seconds (00..59)
     * %d   Day of the month, numeric (00..31)
     * %m   Month, numeric (00..12)
     * %Y   Year, numeric, four digits
     *
     * and most other MySQL DATE_FORMAT() specifiers. The format is translated to a TO_CHAR() pattern.
     *
     * @param string $date quoted date value or non quoted SQL statement(field)
     * @param string $format
     * @return \Zend_Db_Expr
     */
    public function getDateFormatSql($date, $format)
    {
        $pattern = $this->functionMapper->translateDateFormat((string)$format);
        $expr = sprintf('TO_CHAR((%s)::timestamp, %s)', $date, $this->quote($pattern));
        return new \Zend_Db_Expr($expr);
    }

    /**
     * Extract the date part of a date or datetime expression
     *
     * @param \Zend_Db_Expr|string $date quoted field name or SQL statement
     * @return \Zend_Db_Expr
     */
    public function getDatePartSql($date)
    {
        return new \Zend_Db_Expr($this->asFunctionCall(sprintf('(%s)::date', $date)));
    }

    /**
     * Prepare substring sql function
     *
     * @param \Zend_Db_Expr|string $stringExpression quoted field name or SQL statement
     * @param int|string|\Zend_Db_Expr $pos
     * @param int|string|\Zend_Db_Expr|null $len
     * @return \Zend_Db_Expr
     */
    public function getSubstringSql($stringExpression, $pos, $len = null)
    {
        if ($len === null) {
            return new \Zend_Db_Expr(sprintf('SUBSTRING((%s)::text, %s)', $stringExpression, $pos));
        }
        return new \Zend_Db_Expr(sprintf('SUBSTRING((%s)::text, %s, %s)', $stringExpression, $pos, $len));
    }

    /**
     * Prepare standard deviation sql function
     *
     * @param \Zend_Db_Expr|string $expressionField quoted field name or SQL statement
     * @return \Zend_Db_Expr
     */
    public function getStandardDeviationSql($expressionField)
    {
        return new \Zend_Db_Expr(sprintf('STDDEV_SAMP(%s)', $expressionField));
    }

    /**
     * Extract part of a date
     *
     * Like MySQL EXTRACT() the result is an integer.
     *
     * @see INTERVAL_* constants for $unit
     *
     * @param \Zend_Db_Expr|string $date quoted field name or SQL statement
     * @param string $unit
     * @return \Zend_Db_Expr
     * @throws \Zend_Db_Exception
     */
    public function getDateExtractSql($date, $unit)
    {
        if (!isset($this->_intervalUnits[$unit])) {
            throw new \Zend_Db_Exception(sprintf('Undefined interval unit "%s" specified', $unit));
        }

        $expr = sprintf(
            'COALESCE(FLOOR(EXTRACT(%s FROM (%s)::timestamp))::integer)',
            $this->_intervalUnits[$unit],
            $date
        );
        return new \Zend_Db_Expr($expr);
    }

    /**
     * Returns a compressed version of the table name if it is too long
     *
     * @param string $tableName
     * @return string
     * @codeCoverageIgnore
     */
    public function getTableName($tableName)
    {
        return $this->shortenEntityName($tableName, 't_');
    }

    /**
     * Build a trigger name based on table name and trigger details
     *
     * @param string $tableName The table which is the subject of the trigger
     * @param string $time Either "before" or "after"
     * @param string $event The DB level event which activates the trigger, i.e. "update" or "insert"
     * @return string
     * @codeCoverageIgnore
     */
    public function getTriggerName($tableName, $time, $event)
    {
        $triggerName = 'trg_' . $tableName . '_' . $time . '_' . $event;
        return $this->shortenEntityName($triggerName, 'trg_');
    }

    /**
     * Retrieve valid index name
     *
     * Check index name length and allowed symbols
     *
     * @param string $tableName
     * @param string|string[] $fields the columns list
     * @param string $indexType
     * @return string
     */
    public function getIndexName($tableName, $fields, $indexType = '')
    {
        if (is_array($fields)) {
            $fields = implode('_', $fields);
        }

        switch (strtolower((string)$indexType)) {
            case AdapterInterface::INDEX_TYPE_UNIQUE:
                $prefix = 'unq_';
                break;
            case AdapterInterface::INDEX_TYPE_FULLTEXT:
                $prefix = 'fti_';
                break;
            case AdapterInterface::INDEX_TYPE_INDEX:
            default:
                $prefix = 'idx_';
        }
        return strtoupper($this->shortenEntityName($tableName . '_' . $fields, $prefix));
    }

    /**
     * Retrieve valid foreign key name
     *
     * Check foreign key name length and allowed symbols
     *
     * @param string $priTableName
     * @param string $priColumnName
     * @param string $refTableName
     * @param string $refColumnName
     * @return string
     * @codeCoverageIgnore
     */
    public function getForeignKeyName($priTableName, $priColumnName, $refTableName, $refColumnName)
    {
        $fkName = sprintf('%s_%s_%s_%s', $priTableName, $priColumnName, $refTableName, $refColumnName);
        return strtoupper($this->shortenEntityName($fkName, 'fk_'));
    }

    /**
     * Stop updating indexes
     *
     * PostgreSQL cannot suspend index maintenance, so nothing is changed.
     *
     * @param string $tableName
     * @param string $schemaName
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function disableTableKeys($tableName, $schemaName = null)
    {
        return $this;
    }

    /**
     * Re-create missing indexes
     *
     * PostgreSQL cannot suspend index maintenance, so nothing is changed.
     *
     * @param string $tableName
     * @param string $schemaName
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function enableTableKeys($tableName, $schemaName = null)
    {
        return $this;
    }

    /**
     * Get insert from Select object query
     *
     * @param Select $select
     * @param string $table insert into table
     * @param array $fields
     * @param int|false $mode
     * @return string
     */
    public function insertFromSelect(Select $select, $table, array $fields = [], $mode = false)
    {
        $query = sprintf('INSERT INTO %s', $this->quoteIdentifier($table));
        $countFieldsInSelect = count($select->getPart(Select::COLUMNS));
        if (empty($fields) && $countFieldsInSelect > 1) {
            $fields = array_slice(
                array_keys($this->describeTable($table)),
                0,
                $countFieldsInSelect
            );
        }
        if ($fields) {
            $columns = array_map([$this, 'quoteIdentifier'], $fields);
            $query = sprintf('%s (%s)', $query, join(', ', $columns));
        }

        $selectSql = $select->assemble();
        $onConflict = '';
        if ($mode === self::INSERT_IGNORE) {
            $onConflict = ' ON CONFLICT DO NOTHING';
        } elseif ($mode === self::INSERT_ON_DUPLICATE || $mode === self::REPLACE) {
            list($tableName, $schemaName) = $this->splitTableName($table);
            $insertColumns = $fields ?: array_keys($this->describeTable($tableName, $schemaName));
            $target = $this->getConflictTarget($tableName, $schemaName, $insertColumns);
            if ($target !== null) {
                $updateFields = $mode === self::REPLACE ? array_diff($insertColumns, $target) : $fields;
                if (!$updateFields) {
                    foreach ($this->describeTable($tableName, $schemaName) as $column) {
                        if ($column['PRIMARY'] === false) {
                            $updateFields[] = $column['COLUMN_NAME'];
                        }
                    }
                }
                $onConflict = $this->renderOnConflict($target, $updateFields);
                if ($fields && strpos($onConflict, 'DO UPDATE') !== false) {
                    $selectSql = $this->getDistinctOnSelectSql($selectSql, $tableName, $schemaName, $fields, $target);
                }
            }
        }

        return sprintf('%s %s%s', $query, $selectSql, $onConflict);
    }

    /**
     * Wrap INSERT ... SELECT source so that it yields one row per conflict key
     *
     * PostgreSQL refuses to update the same row twice in one statement, MySQL applies the rows one after another.
     * Columns are cast to the column types: a literal like '0' is typed when it is inserted directly,
     * but becomes text once it passes through a subquery.
     *
     * @param string $selectSql
     * @param string $tableName
     * @param string|null $schemaName
     * @param string[] $fields
     * @param string[] $target
     * @return string
     */
    private function getDistinctOnSelectSql($selectSql, $tableName, $schemaName, array $fields, array $target)
    {
        $types = $this->fetchPairs(sprintf(
            'SELECT attname, format_type(atttypid, atttypmod) FROM pg_attribute'
            . ' WHERE attrelid = %s AND attnum > 0 AND NOT attisdropped',
            $this->getRegclassSql($tableName, $schemaName)
        ));
        $columns = [];
        foreach ($fields as $field) {
            $column = 'mage_src.' . $this->quoteIdentifier($field);
            $columns[] = isset($types[$field]) ? sprintf('CAST(%s AS %s)', $column, $types[$field]) : $column;
        }

        return sprintf(
            'SELECT DISTINCT ON (%s) %s FROM (%s) AS mage_src (%s)',
            implode(', ', array_map(fn ($field) => 'mage_src.' . $this->quoteIdentifier($field), $target)),
            implode(', ', $columns),
            $selectSql,
            implode(', ', array_map([$this, 'quoteIdentifier'], $fields))
        );
    }

    /**
     * Get insert queries in array for insert by range with step parameter
     *
     * @param string $rangeField
     * @param \Magento\Framework\DB\Select $select
     * @param int $stepCount
     * @return \Magento\Framework\DB\Select[]
     * @throws LocalizedException
     * @deprecated
     * @see \Magento\Framework\DB\Query\Generator
     */
    public function selectsByRange($rangeField, \Magento\Framework\DB\Select $select, $stepCount = 100)
    {
        $iterator = $this->getQueryGenerator()->generate($rangeField, $select, $stepCount);
        $queries = [];
        foreach ($iterator as $query) {
            $queries[] = $query;
        }
        return $queries;
    }

    /**
     * Get query generator
     *
     * @return QueryGenerator
     */
    private function getQueryGenerator()
    {
        if ($this->queryGenerator === null) {
            // phpcs:ignore Magento2.PHP.AutogeneratedClassNotInConstructor
            $this->queryGenerator = \Magento\Framework\App\ObjectManager::getInstance()->create(QueryGenerator::class);
        }
        return $this->queryGenerator;
    }

    /**
     * Get update table query using select object for join and update
     *
     * PostgreSQL has no UPDATE ... JOIN: inner joins become UPDATE ... FROM with the join conditions in WHERE.
     * Outer joins cannot reference the updated table in UPDATE ... FROM, so then the table is joined to itself
     * by ctid and the join conditions are applied to that copy.
     *
     * @param Select $select
     * @param string|array $table
     * @return string
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function updateFromSelect(Select $select, $table)
    {
        if (!is_array($table)) {
            $table = [$table => $table];
        }

        // get table name and alias
        $keys       = array_keys($table);
        $tableAlias = $keys[0];
        $tableName  = $table[$keys[0]];

        // render UPDATE SET
        $columns = [];
        foreach ($select->getPart(\Magento\Framework\DB\Select::COLUMNS) as $columnEntry) {
            list($correlationName, $column, $alias) = $columnEntry;
            if (empty($alias)) {
                $alias = $column;
            }
            if (!$column instanceof \Zend_Db_Expr && !empty($correlationName)) {
                $column = $this->quoteIdentifier([$correlationName, $column]);
            }
            // The updated column must not be qualified in PostgreSQL
            $columns[] = sprintf('%s = %s', $this->quoteIdentifier($alias), $column);
        }

        if (!$columns) {
            throw new LocalizedException(
                new \Magento\Framework\Phrase('The columns for UPDATE statement are not defined')
            );
        }

        $fromPart = $select->getPart(\Magento\Framework\DB\Select::FROM);
        $hasOuterJoin = false;
        foreach ($fromPart as $joinProp) {
            if (!in_array(
                $joinProp['joinType'],
                [\Magento\Framework\DB\Select::FROM, \Magento\Framework\DB\Select::INNER_JOIN],
                true
            )) {
                $hasOuterJoin = true;
            }
        }

        $wherePart = $select->getPart(\Magento\Framework\DB\Select::WHERE);
        $conditions = [];
        $from = [];
        if (!$hasOuterJoin) {
            foreach ($fromPart as $correlationName => $joinProp) {
                if ($correlationName === $tableAlias) {
                    continue;
                }
                $from[] = $this->getJoinTableSql($joinProp, $correlationName);
                if (!empty($joinProp['joinCondition'])) {
                    $conditions[] = sprintf('(%s)', $joinProp['joinCondition']);
                }
            }
            if ($wherePart) {
                $conditions[] = sprintf('(%s)', implode(' ', $wherePart));
            }
            $fromSql = $from ? "\nFROM " . implode(', ', $from) : '';
        } else {
            $selfAlias = $tableAlias . '_mage_self';
            $rename = fn ($sql) => $this->renameTableAlias((string)$sql, $tableAlias, $selfAlias);
            $fromSql = "\nFROM " . $this->quoteTableAs($tableName, $selfAlias);
            foreach ($fromPart as $correlationName => $joinProp) {
                if ($correlationName === $tableAlias) {
                    continue;
                }
                $joinType = $joinProp['joinType'] == \Magento\Framework\DB\Select::FROM
                    ? \Magento\Framework\DB\Select::INNER_JOIN
                    : $joinProp['joinType'];
                $condition = !empty($joinProp['joinCondition']) ? $rename($joinProp['joinCondition']) : 'TRUE';
                $fromSql .= sprintf(
                    "\n%s %s ON %s",
                    strtoupper($joinType),
                    $this->getJoinTableSql($joinProp, $correlationName),
                    $condition
                );
            }
            $conditions[] = sprintf(
                '%s.ctid = %s.ctid',
                $this->quoteIdentifier($tableAlias),
                $this->quoteIdentifier($selfAlias)
            );
            if ($wherePart) {
                $conditions[] = sprintf('(%s)', $rename(implode(' ', $wherePart)));
            }
        }

        $query = sprintf(
            "UPDATE %s\nSET %s%s",
            $this->quoteTableAs($tableName, $tableAlias),
            implode(', ', $columns),
            $fromSql
        );
        if ($conditions) {
            $query = sprintf("%s\nWHERE %s", $query, implode(' AND ', $conditions));
        }

        return $query;
    }

    /**
     * Render a table of select FROM part with schema and alias
     *
     * @param array $joinProp
     * @param string $correlationName
     * @return string
     */
    private function getJoinTableSql(array $joinProp, $correlationName): string
    {
        $joinTable = '';
        if ($joinProp['schema'] !== null) {
            $joinTable = sprintf('%s.', $this->quoteIdentifier($joinProp['schema']));
        }
        return $joinTable . $this->quoteTableAs($joinProp['tableName'], $correlationName);
    }

    /**
     * Replace qualified references to a table alias in an SQL fragment
     *
     * @param string $sql
     * @param string $alias
     * @param string $newAlias
     * @return string
     */
    private function renameTableAlias(string $sql, string $alias, string $newAlias): string
    {
        return preg_replace(
            '/(?<![\w"`.])(["`]?)' . preg_quote($alias, '/') . '\1\./',
            $this->quoteIdentifier($newAlias) . '.',
            $sql
        );
    }

    /**
     * Get delete from select object query
     *
     * Deletes the rows of the table (or alias) whose ctid is selected by the joins and conditions of the select.
     *
     * @param Select $select
     * @param string $table the table name or alias used in select
     * @return string
     */
    public function deleteFromSelect(Select $select, $table)
    {
        $select = clone $select;
        $select->reset(\Magento\Framework\DB\Select::DISTINCT);
        $select->reset(\Magento\Framework\DB\Select::COLUMNS);

        $tableName = $table;
        $schemaName = null;
        foreach ($select->getPart(\Magento\Framework\DB\Select::FROM) as $correlationName => $joinProp) {
            if ($correlationName === $table) {
                $tableName = $joinProp['tableName'];
                $schemaName = $joinProp['schema'];
                break;
            }
        }
        $select->columns(new \Zend_Db_Expr($this->quoteIdentifier($table) . '.ctid'));

        $query = sprintf(
            'DELETE FROM %s WHERE ctid IN (%s)',
            $this->quoteIdentifier($this->_getTableName($tableName, $schemaName)),
            $select->assemble()
        );

        return $query;
    }

    /**
     * Calculate checksum for table or for group of tables
     *
     * The checksum is an md5 of all rows, independent of the physical row order.
     *
     * @param array|string $tableNames array of tables names | table name
     * @param string $schemaName schema name
     * @return array
     */
    public function getTablesChecksum($tableNames, $schemaName = null)
    {
        $result     = [];
        $tableNames = is_array($tableNames) ? $tableNames : [$tableNames];

        foreach ($tableNames as $tableName) {
            $query = sprintf(
                'SELECT md5(COALESCE(string_agg(md5(t::text), \'\' ORDER BY md5(t::text)), \'\')) AS "Checksum"'
                . ' FROM %s AS t',
                $this->quoteIdentifier($this->_getTableName($tableName, $schemaName))
            );
            $checkSumArray      = $this->fetchRow($query);
            $result[$tableName] = $checkSumArray['Checksum'];
        }

        return $result;
    }

    /**
     * Check if the database support STRAIGHT JOIN
     *
     * @return false
     */
    public function supportStraightJoin()
    {
        return false;
    }

    /**
     * Adds order by random to select object
     *
     * Possible using integer field for optimization
     *
     * @param Select $select
     * @param string $field
     * @return $this
     */
    public function orderRand(Select $select, $field = null)
    {
        if ($field !== null) {
            $expression = new \Zend_Db_Expr(sprintf('RANDOM() * %s', $this->quoteIdentifier($field)));
            $select->columns(['mage_rand' => $expression]);
            $spec = new \Zend_Db_Expr('mage_rand');
        } else {
            $spec = new \Zend_Db_Expr('RANDOM()');
        }
        $select->order($spec);

        return $this;
    }

    /**
     * Render SQL FOR UPDATE clause
     *
     * @param string $sql
     * @return string
     */
    public function forUpdate($sql)
    {
        return sprintf('%s FOR UPDATE', $sql);
    }

    /**
     * Prepare insert data
     *
     * @param mixed $row
     * @param array $bind
     * @return string
     */
    protected function _prepareInsertData($row, &$bind)
    {
        $row = (array)$row;
        $line = [];
        foreach ($row as $value) {
            if ($value instanceof \Zend_Db_Expr) {
                $line[] = $value->__toString();
            } else {
                $line[] = '?';
                $bind[] = $value;
            }
        }
        $line = implode(', ', $line);

        return sprintf('(%s)', $line);
    }

    /**
     * Return ddl type
     *
     * @param array $options
     * @return string
     */
    protected function _getDdlType($options)
    {
        $ddlType = null;
        if (isset($options['TYPE'])) {
            $ddlType = $options['TYPE'];
        } elseif (isset($options['COLUMN_TYPE'])) {
            $ddlType = $options['COLUMN_TYPE'];
        }

        return $ddlType;
    }

    /**
     * Return DDL action
     *
     * @param string $action
     * @return string
     */
    protected function _getDdlAction($action)
    {
        switch ($action) {
            case AdapterInterface::FK_ACTION_CASCADE:
                return Table::ACTION_CASCADE;
            case AdapterInterface::FK_ACTION_SET_NULL:
                return Table::ACTION_SET_NULL;
            case AdapterInterface::FK_ACTION_RESTRICT:
                return Table::ACTION_RESTRICT;
            case AdapterInterface::FK_ACTION_SET_DEFAULT:
                return Table::ACTION_SET_DEFAULT;
            default:
                return Table::ACTION_NO_ACTION;
        }
    }

    /**
     * Prepare sql date condition
     *
     * @param array $condition
     * @param string $key
     * @return string
     */
    protected function _prepareSqlDateCondition($condition, $key)
    {
        if (empty($condition['date'])) {
            if (empty($condition['datetime'])) {
                $result = $condition[$key];
            } else {
                $result = $this->formatDate($condition[$key]);
            }
        } else {
            $result = $this->formatDate($condition[$key]);
        }

        return $result;
    }

    /**
     * Try to find installed primary key name, if not - formate new one.
     *
     * @param string $tableName Table name
     * @param string $schemaName OPTIONAL
     * @return string Primary Key name
     */
    public function getPrimaryKeyName($tableName, $schemaName = null)
    {
        $indexes = $this->getIndexList($tableName, $schemaName);
        if (isset($indexes['PRIMARY'])) {
            // Callers look the name up in getIndexList() (e.g. EntityMetadata::getLinkField()), which keeps the
            // primary key under 'PRIMARY' as MySQL names it; the actual constraint name is in KEY_NAME
            return 'PRIMARY';
        } else {
            return 'PK_' . strtoupper($tableName);
        }
    }

    /**
     * Parse text size
     *
     * Returns max allowed size if value great it
     *
     * @param string|int $size
     * @return int
     */
    protected function _parseTextSize($size)
    {
        $size = trim((string)$size);
        $last = strtolower(substr($size, -1));

        switch ($last) {
            case 'k':
                $size = (int)$size * 1024;
                break;
            case 'm':
                $size = (int)$size * 1024 * 1024;
                break;
            case 'g':
                $size = (int)$size * 1024 * 1024 * 1024;
                break;
        }

        if (empty($size)) {
            return Table::DEFAULT_TEXT_SIZE;
        }
        if ($size >= Table::MAX_TEXT_SIZE) {
            return Table::MAX_TEXT_SIZE;
        }

        return (int)$size;
    }

    /**
     * Converts fetched blob into raw binary PHP data.
     *
     * The adapter's statement already reads bytea streams; this also decodes bytea in hex text form.
     *
     * @param mixed $value
     * @return mixed
     */
    public function decodeVarbinary($value)
    {
        if (is_resource($value)) {
            return stream_get_contents($value);
        }
        if (is_string($value) && strncmp($value, '\\x', 2) === 0 && ctype_xdigit(substr($value, 2))) {
            return hex2bin(substr($value, 2));
        }
        return $value;
    }

    /**
     * Create trigger
     *
     * The statements become the body of a PL/pgSQL function with the trigger's name. Statements Magento
     * generates for MySQL (INSERT IGNORE, the <=> operator) are translated.
     *
     * @param \Magento\Framework\DB\Ddl\Trigger $trigger
     * @throws \Zend_Db_Exception
     * @return \Zend_Db_Statement_Pdo
     */
    public function createTrigger(\Magento\Framework\DB\Ddl\Trigger $trigger)
    {
        if (!$trigger->getStatements()) {
            throw new \Zend_Db_Exception(
                (string)new \Magento\Framework\Phrase(
                    'Trigger %1 has not statements available',
                    [$trigger->getName()]
                )
            );
        }

        $statements = $this->translateTriggerStatements(implode("\n", $trigger->getStatements()));
        $isBefore = strtoupper($trigger->getTime()) === \Magento\Framework\DB\Ddl\Trigger::TIME_BEFORE;
        $return = $isBefore ? "IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;\nRETURN NEW;" : 'RETURN NULL;';
        $function = $this->quoteIdentifier($trigger->getName());

        $this->_query(sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger LANGUAGE plpgsql AS \$mage_trigger\$\nBEGIN\n%s\n%s\nEND\n\$mage_trigger\$",
            $function,
            $statements,
            $return
        ));

        return $this->_query(sprintf(
            'CREATE TRIGGER %s %s %s ON %s FOR EACH ROW EXECUTE FUNCTION %s()',
            $this->quoteIdentifier($trigger->getName()),
            $trigger->getTime(),
            $trigger->getEvent(),
            $this->quoteIdentifier($trigger->getTable()),
            $function
        ));
    }

    /**
     * Translate MySQL trigger statements to PL/pgSQL
     *
     * @param string $statements
     * @return string
     */
    public function translateTriggerStatements(string $statements): string
    {
        $statements = $this->translateIdentifiers($statements);
        $statements = preg_replace(
            '/\bINSERT\s+IGNORE\s+INTO\s+(.+?)\s*;/is',
            'INSERT INTO $1 ON CONFLICT DO NOTHING;',
            $statements
        );
        $operand = '((?:NEW|OLD)\.(?:"(?:[^"]|"")+"|\w+))';
        return preg_replace('/' . $operand . '\s*<=>\s*' . $operand . '/i', '$1 IS NOT DISTINCT FROM $2', $statements);
    }

    /**
     * Drop trigger from database
     *
     * PostgreSQL triggers belong to a table; the table is looked up by the trigger name.
     * The trigger function created by createTrigger() is dropped as well.
     *
     * @param string $triggerName
     * @param string|null $schemaName
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function dropTrigger($triggerName, $schemaName = null)
    {
        if (empty($triggerName)) {
            throw new \InvalidArgumentException((string)new \Magento\Framework\Phrase('Trigger name is not defined'));
        }

        $sql = 'SELECT tg.tgrelid::regclass::text FROM pg_trigger AS tg'
            . ' JOIN pg_class AS c ON c.oid = tg.tgrelid JOIN pg_namespace AS n ON n.oid = c.relnamespace'
            . ' WHERE tg.tgname = ? AND ' . ($schemaName ? 'n.nspname = ?' : 'pg_table_is_visible(c.oid)');
        $tables = $this->fetchCol($sql, $schemaName ? [$triggerName, $schemaName] : [$triggerName]);
        foreach ($tables as $table) {
            $this->query(sprintf('DROP TRIGGER IF EXISTS %s ON %s', $this->quoteIdentifier($triggerName), $table));
        }

        $function = $this->quoteIdentifier(($schemaName ? $schemaName . '.' : '') . $triggerName);
        $this->query(sprintf('DROP FUNCTION IF EXISTS %s()', $function));

        return true;
    }

    /**
     * Check if all transactions have been committed
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->_transactionLevel > 0) {
            trigger_error('Some transactions have not been committed or rolled back', E_USER_ERROR);
        }
    }

    /**
     * Returns a list of the tables and views in the current schema.
     *
     * @return array
     */
    public function listTables()
    {
        return $this->getTables();
    }

    /**
     * Retrieve tables list
     *
     * Like MySQL SHOW TABLES, returns the tables and views of the current schema.
     *
     * @param null|string $likeCondition
     * @return array
     */
    public function getTables($likeCondition = null)
    {
        $sql = 'SELECT c.relname FROM pg_class AS c JOIN pg_namespace AS n ON n.oid = c.relnamespace'
            . ' WHERE n.nspname = current_schema() AND c.relkind IN (\'r\', \'p\', \'v\', \'m\', \'f\')';
        $bind = [];
        if ($likeCondition !== null) {
            $sql .= ' AND c.relname LIKE ?';
            $bind[] = $likeCondition;
        }
        return $this->fetchCol($sql . ' ORDER BY c.relname', $bind);
    }

    /**
     * Returns auto increment field if exists
     *
     * @param string $tableName
     * @param string|null $schemaName
     * @return string|bool
     */
    public function getAutoIncrementField($tableName, $schemaName = null)
    {
        foreach ($this->describeTable($tableName, $schemaName) as $column) {
            if ($column['IDENTITY']) {
                return $column['COLUMN_NAME'];
            }
        }
        return false;
    }

    /**
     * Get schema Listener.
     *
     * Required to listen all DDL changes done by 3-rd party modules with old Install/UpgradeSchema scripts.
     *
     * @return SchemaListener
     */
    public function getSchemaListener()
    {
        if ($this->schemaListener === null) {
            // phpcs:ignore Magento2.PHP.AutogeneratedClassNotInConstructor
            $this->schemaListener = \Magento\Framework\App\ObjectManager::getInstance()->create(SchemaListener::class);
        }
        return $this->schemaListener;
    }

    /**
     * Disable show internals with var_dump
     *
     * @see https://www.php.net/manual/en/language.oop5.magic.php#object.debuginfo
     * @return array
     */
    public function __debugInfo()
    {
        return [];
    }
}
