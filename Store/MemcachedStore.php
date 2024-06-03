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

use Symfony\Component\Lock\Exception\InvalidArgumentException;
use Symfony\Component\Lock\Exception\InvalidTtlException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * MemcachedStore is a PersistingStoreInterface implementation using Memcached as store engine.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class MemcachedStore implements PersistingStoreInterface
{
    use ExpiringStoreTrait;

    private bool $useExtendedReturn;

    public static function isSupported(): bool
    {
        return \extension_loaded('memcached');
    }

    /**
     * @param int $initialTtl the expiration delay of locks in seconds
     */
    public function __construct(
        private \Memcached $memcached,
        private int $initialTtl = 300,
    ) {
        if (!static::isSupported()) {
            throw new InvalidArgumentException('Memcached extension is required.');
        }

        if ($initialTtl < 1) {
            throw new InvalidArgumentException(sprintf('"%s()" expects a strictly positive TTL. Got %d.', __METHOD__, $initialTtl));
        }
    }

    public function save(Key $key): void
    {
        $token = $this->getUniqueToken($key);
        $key->reduceLifetime($this->initialTtl);
        if (!$this->memcached->add((string) $key, $token, (int) ceil($this->initialTtl))) {
            // the lock is already acquired. It could be us. Let's try to put off.
            $this->putOffExpiration($key, $this->initialTtl);
        }

        $this->checkNotExpired($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        if ($ttl < 1) {
            throw new InvalidTtlException(sprintf('"%s()" expects a TTL greater or equals to 1 second. Got %s.', __METHOD__, $ttl));
        }

        // Interface defines a float value but Store required an integer.
        $ttl = (int) ceil($ttl);

        $token = $this->getUniqueToken($key);

        [$value, $cas] = $this->getValueAndCas($key);

        $key->reduceLifetime($ttl);
        // Could happens when we ask a putOff after a timeout but in luck nobody steal the lock
        if (\Memcached::RES_NOTFOUND === $this->memcached->getResultCode()) {
            if ($this->memcached->add((string) $key, $token, $ttl)) {
                return;
            }

            // no luck, with concurrency, someone else acquire the lock
            throw new LockConflictedException();
        }

        // Someone else steal the lock
        if ($value !== $token) {
            throw new LockConflictedException();
        }

        if (!$this->memcached->cas($cas, (string) $key, $token, $ttl)) {
            throw new LockConflictedException();
        }

        $this->checkNotExpired($key);
    }

    public function delete(Key $key): void
    {
        $token = $this->getUniqueToken($key);

        [$value, $cas] = $this->getValueAndCas($key);

        if ($value !== $token) {
            // we are not the owner of the lock. Nothing to do.
            return;
        }

        // To avoid concurrency in deletion, the trick is to extends the TTL then deleting the key
        if (!$this->memcached->cas($cas, (string) $key, $token, 2)) {
            // Someone steal our lock. It does not belongs to us anymore. Nothing to do.
            return;
        }

        // Now, we are the owner of the lock for 2 more seconds, we can delete it.
        $this->memcached->delete((string) $key);
    }

    public function exists(Key $key): bool
    {
        return $this->memcached->get((string) $key) === $this->getUniqueToken($key);
    }

    private function getUniqueToken(Key $key): string
    {
        if (!$key->hasState(__CLASS__)) {
            $token = base64_encode(random_bytes(32));
            $key->setState(__CLASS__, $token);
        }

        return $key->getState(__CLASS__);
    }

    private function getValueAndCas(Key $key): array
    {
        if ($this->useExtendedReturn ??= version_compare(phpversion('memcached'), '2.9.9', '>')) {
            $extendedReturn = $this->memcached->get((string) $key, null, \Memcached::GET_EXTENDED);
            if (\Memcached::GET_ERROR_RETURN_VALUE === $extendedReturn) {
                return [$extendedReturn, 0.0];
            }

            return [$extendedReturn['value'], $extendedReturn['cas']];
        }

        $cas = 0.0;
        $value = $this->memcached->get((string) $key, null, $cas);

        return [$value, $cas];
    }
}
