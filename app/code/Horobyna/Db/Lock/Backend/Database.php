<?php
/**
 * PostgreSQL database adapter for Magento
 */
declare(strict_types=1);

namespace Horobyna\Db\Lock\Backend;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Lock manager on PostgreSQL session advisory locks, replaces the MySQL GET_LOCK() backend ("db" lock provider)
 *
 * The lock name is hashed to the 64-bit advisory lock key with hashtextextended(); collisions are possible but
 * negligible. Like GET_LOCK(), locks belong to the database session: they are re-entrant (each lock() needs an
 * unlock()), not tied to transactions, and released when the connection closes. Session locks do not work through
 * a pooler in transaction mode (PgBouncer), workers need a direct connection or session pooling.
 *
 * Waiting polls pg_try_advisory_lock() instead of using lock_timeout: a timeout error would abort the
 * surrounding transaction, while GET_LOCK() simply returns 0.
 */
class Database implements LockManagerInterface
{
    /**
     * Max time for lock is 1 week, as in the MySQL backend
     */
    private const MAX_LOCK_TIME = 604800;

    /**
     * First and maximal pause between attempts, microseconds
     */
    private const MIN_RETRY_DELAY = 50000;
    private const MAX_RETRY_DELAY = 1000000;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var string|null Lock prefix
     */
    private $prefix;

    /**
     * @param ResourceConnection $resource
     * @param DeploymentConfig $deploymentConfig
     * @param string|null $prefix
     */
    public function __construct(
        ResourceConnection $resource,
        DeploymentConfig $deploymentConfig,
        ?string $prefix = null
    ) {
        $this->resource = $resource;
        $this->deploymentConfig = $deploymentConfig;
        $this->prefix = $prefix;
    }

    /**
     * Sets a lock for name
     *
     * @param string $name lock name
     * @param int $timeout How long to wait lock acquisition in seconds, negative value means infinite timeout
     * @return bool
     */
    public function lock(string $name, int $timeout = -1): bool
    {
        if (!$this->deploymentConfig->isDbAvailable()) {
            return true;
        }
        $name = $this->addPrefix($name);
        $timeout = $timeout < 0 ? self::MAX_LOCK_TIME : $timeout;
        $deadline = microtime(true) + $timeout;
        $delay = self::MIN_RETRY_DELAY;

        while (!$this->tryLock($name)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }
            usleep((int)min($delay, $remaining * 1000000));
            $delay = min($delay * 2, self::MAX_RETRY_DELAY);
        }

        return true;
    }

    /**
     * Releases a lock for name
     *
     * Returns false when the lock is not held by this session.
     *
     * @param string $name lock name
     * @return bool
     */
    public function unlock(string $name): bool
    {
        if (!$this->deploymentConfig->isDbAvailable()) {
            return true;
        }

        return (bool)$this->resource->getConnection()->fetchOne(
            'SELECT pg_advisory_unlock(hashtextextended(?, 0))::int',
            [$this->addPrefix($name)]
        );
    }

    /**
     * Tests of lock is set for name, by any session including this one
     *
     * An advisory lock on a bigint key is listed in pg_locks with the high 32 bits as classid, the low 32 bits
     * as objid and objsubid 1.
     *
     * @param string $name lock name
     * @return bool
     */
    public function isLocked(string $name): bool
    {
        if (!$this->deploymentConfig->isDbAvailable()) {
            return false;
        }

        return (bool)$this->resource->getConnection()->fetchOne(
            'SELECT EXISTS (
                SELECT 1 FROM pg_locks l, (SELECT hashtextextended(?, 0) AS key) k
                WHERE l.locktype = \'advisory\' AND l.granted AND l.objsubid = 1
                    AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database())
                    AND l.classid::bigint = (k.key >> 32) & 4294967295
                    AND l.objid::bigint = k.key & 4294967295
            )::int',
            [$this->addPrefix($name)]
        );
    }

    /**
     * Make one attempt to take the lock
     *
     * @param string $name lock name with prefix
     * @return bool
     */
    private function tryLock(string $name): bool
    {
        return (bool)$this->resource->getConnection()->fetchOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0))::int',
            [$name]
        );
    }

    /**
     * Adds prefix; the name is hashed, so its length is not limited
     *
     * @param string $name
     * @return string
     */
    private function addPrefix(string $name): string
    {
        $prefix = $this->getPrefix();

        return $prefix !== '' ? $prefix . '|' . $name : $name;
    }

    /**
     * Get installation specific lock prefix to avoid lock conflicts
     *
     * @return string lock prefix
     */
    private function getPrefix(): string
    {
        if ($this->prefix === null) {
            $this->prefix = (string)$this->deploymentConfig->get(
                ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTION_DEFAULT
                . '/'
                . ConfigOptionsListConstants::KEY_NAME,
                ''
            );
        }

        return $this->prefix;
    }
}
