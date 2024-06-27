<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Lock\Store;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Lock\BlockingSharedLockStoreInterface;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\InvalidArgumentException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\SharedLockStoreInterface;

/**
 * DoctrineDbalPostgreSqlStore is a PersistingStoreInterface implementation using
 * PostgreSql advisory locks with a Doctrine DBAL Connection.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class DoctrineDbalPostgreSqlStore implements BlockingSharedLockStoreInterface, BlockingStoreInterface
{
    private Connection $conn;
    private static array $storeRegistry = [];

    /**
     * You can either pass an existing database connection a Doctrine DBAL Connection
     * or a URL that will be used to connect to the database.
     *
     * @throws InvalidArgumentException When first argument is not Connection nor string
     */
    public function __construct(#[\SensitiveParameter] Connection|string $connOrUrl)
    {
        if ($connOrUrl instanceof Connection) {
            if (!$connOrUrl->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                throw new InvalidArgumentException(\sprintf('The adapter "%s" does not support the "%s" platform.', __CLASS__, $connOrUrl->getDatabasePlatform()::class));
            }
            $this->conn = $connOrUrl;
        } else {
            if (!class_exists(DriverManager::class)) {
                throw new InvalidArgumentException('Failed to parse DSN. Try running "composer require doctrine/dbal".');
            }
            $params = (new DsnParser([
                'db2' => 'ibm_db2',
                'mssql' => 'pdo_sqlsrv',
                'mysql' => 'pdo_mysql',
                'mysql2' => 'pdo_mysql',
                'postgres' => 'pdo_pgsql',
                'postgresql' => 'pdo_pgsql',
                'pgsql' => 'pdo_pgsql',
                'sqlite' => 'pdo_sqlite',
                'sqlite3' => 'pdo_sqlite',
            ]))->parse($this->filterDsn($connOrUrl));

            $config = new Configuration();
            $config->setSchemaManagerFactory(new DefaultSchemaManagerFactory());

            $this->conn = DriverManager::getConnection($params, $config);
        }
    }

    public function save(Key $key): void
    {
        // prevent concurrency within the same connection
        $this->getInternalStore()->save($key);

        $lockAcquired = false;

        try {
            $sql = 'SELECT pg_try_advisory_lock(:key)';
            $result = $this->conn->executeQuery($sql, [
                'key' => $this->getHashedKey($key),
            ]);

            // Check if lock is acquired
            if (true === $result->fetchOne()) {
                $key->markUnserializable();
                // release sharedLock in case of promotion
                $this->unlockShared($key);

                $lockAcquired = true;

                return;
            }
        } finally {
            if (!$lockAcquired) {
                $this->getInternalStore()->delete($key);
            }
        }

        throw new LockConflictedException();
    }

    public function saveRead(Key $key): void
    {
        // prevent concurrency within the same connection
        $this->getInternalStore()->saveRead($key);

        $lockAcquired = false;

        try {
            $sql = 'SELECT pg_try_advisory_lock_shared(:key)';
            $result = $this->conn->executeQuery($sql, [
                'key' => $this->getHashedKey($key),
            ]);

            // Check if lock is acquired
            if (true === $result->fetchOne()) {
                $key->markUnserializable();
                // release lock in case of demotion
                $this->unlock($key);

                $lockAcquired = true;

                return;
            }
        } finally {
            if (!$lockAcquired) {
                $this->getInternalStore()->delete($key);
            }
        }

        throw new LockConflictedException();
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        // postgresql locks forever.
        // check if lock still exists
        if (!$this->exists($key)) {
            throw new LockConflictedException();
        }
    }

    public function delete(Key $key): void
    {
        // Prevent deleting locks own by an other key in the same connection
        if (!$this->exists($key)) {
            return;
        }

        $this->unlock($key);

        // Prevent deleting Readlocks own by current key AND an other key in the same connection
        $store = $this->getInternalStore();
        try {
            // If lock acquired = there is no other ReadLock
            $store->save($key);
            $this->unlockShared($key);
        } catch (LockConflictedException) {
            // an other key exists in this ReadLock
        }

        $store->delete($key);
    }

    public function exists(Key $key): bool
    {
        $sql = "SELECT count(*) FROM pg_locks WHERE locktype='advisory' AND objid=:key AND pid=pg_backend_pid()";
        $result = $this->conn->executeQuery($sql, [
            'key' => $this->getHashedKey($key),
        ]);

        if ($result->fetchOne() > 0) {
            // connection is locked, check for lock in internal store
            return $this->getInternalStore()->exists($key);
        }

        return false;
    }

    public function waitAndSave(Key $key): void
    {
        // prevent concurrency within the same connection
        // Internal store does not allow blocking mode, because there is no way to acquire one in a single process
        $this->getInternalStore()->save($key);

        $lockAcquired = false;
        $sql = 'SELECT pg_advisory_lock(:key)';
        try {
            $this->conn->executeStatement($sql, [
                'key' => $this->getHashedKey($key),
            ]);
            $lockAcquired = true;
        } finally {
            if (!$lockAcquired) {
                $this->getInternalStore()->delete($key);
            }
        }

        // release lock in case of promotion
        $this->unlockShared($key);
    }

    public function waitAndSaveRead(Key $key): void
    {
        // prevent concurrency within the same connection
        // Internal store does not allow blocking mode, because there is no way to acquire one in a single process
        $this->getInternalStore()->saveRead($key);

        $lockAcquired = false;
        $sql = 'SELECT pg_advisory_lock_shared(:key)';
        try {
            $this->conn->executeStatement($sql, [
                'key' => $this->getHashedKey($key),
            ]);
            $lockAcquired = true;
        } finally {
            if (!$lockAcquired) {
                $this->getInternalStore()->delete($key);
            }
        }

        // release lock in case of demotion
        $this->unlock($key);
    }

    /**
     * Returns a hashed version of the key.
     */
    private function getHashedKey(Key $key): int
    {
        return crc32((string) $key);
    }

    private function unlock(Key $key): void
    {
        do {
            $sql = "SELECT pg_advisory_unlock(objid::bigint) FROM pg_locks WHERE locktype='advisory' AND mode='ExclusiveLock' AND objid=:key AND pid=pg_backend_pid()";
            $result = $this->conn->executeQuery($sql, [
                'key' => $this->getHashedKey($key),
            ]);
        } while (0 !== $result->rowCount());
    }

    private function unlockShared(Key $key): void
    {
        do {
            $sql = "SELECT pg_advisory_unlock_shared(objid::bigint) FROM pg_locks WHERE locktype='advisory' AND mode='ShareLock' AND objid=:key AND pid=pg_backend_pid()";
            $result = $this->conn->executeQuery($sql, [
                'key' => $this->getHashedKey($key),
            ]);
        } while (0 !== $result->rowCount());
    }

    /**
     * Check driver and remove scheme extension from DSN.
     * From pgsql+advisory://server/ to pgsql://server/.
     *
     * @throws InvalidArgumentException when driver is not supported
     */
    private function filterDsn(#[\SensitiveParameter] string $dsn): string
    {
        if (!str_contains($dsn, '://')) {
            throw new InvalidArgumentException('DSN is invalid for Doctrine DBAL.');
        }

        [$scheme, $rest] = explode(':', $dsn, 2);
        $driver = substr($scheme, 0, strpos($scheme, '+') ?: null);
        if (!\in_array($driver, ['pgsql', 'postgres', 'postgresql'])) {
            throw new InvalidArgumentException(\sprintf('The adapter "%s" does not support the "%s" driver.', __CLASS__, $driver));
        }

        return \sprintf('%s:%s', $driver, $rest);
    }

    private function getInternalStore(): SharedLockStoreInterface
    {
        $namespace = spl_object_hash($this->conn);

        return self::$storeRegistry[$namespace] ??= new InMemoryStore();
    }
}
