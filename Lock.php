<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Lock;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Lock\Exception\InvalidArgumentException;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\Exception\LockReleasingException;

/**
 * Lock is the default implementation of the LockInterface.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
final class Lock implements SharedLockInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private bool $dirty = false;

    /**
     * @param float|null $ttl         Maximum expected lock duration in seconds
     * @param bool       $autoRelease Whether to automatically release the lock or not when the lock instance is destroyed
     */
    public function __construct(
        private Key $key,
        private PersistingStoreInterface $store,
        private ?float $ttl = null,
        private bool $autoRelease = true,
    ) {
    }

    public function __sleep(): array
    {
        throw new \BadMethodCallException('Cannot serialize '.__CLASS__);
    }

    public function __wakeup(): void
    {
        throw new \BadMethodCallException('Cannot unserialize '.__CLASS__);
    }

    /**
     * Automatically releases the underlying lock when the object is destructed.
     */
    public function __destruct()
    {
        if (!$this->autoRelease || !$this->dirty || !$this->isAcquired()) {
            return;
        }

        $this->release();
    }

    public function acquire(bool $blocking = false): bool
    {
        $this->key->resetLifetime();
        try {
            if ($blocking) {
                if (!$this->store instanceof BlockingStoreInterface) {
                    while (true) {
                        try {
                            $this->store->save($this->key);
                            break;
                        } catch (LockConflictedException) {
                            usleep((100 + random_int(-10, 10)) * 1000);
                        }
                    }
                } else {
                    $this->store->waitAndSave($this->key);
                }
            } else {
                $this->store->save($this->key);
            }

            $this->dirty = true;
            $this->logger?->debug('Successfully acquired the "{resource}" lock.', ['resource' => $this->key]);

            if ($this->ttl) {
                $this->refresh();
            }

            if ($this->key->isExpired()) {
                try {
                    $this->release();
                } catch (\Exception) {
                    // swallow exception to not hide the original issue
                }
                throw new LockExpiredException(\sprintf('Failed to store the "%s" lock.', $this->key));
            }

            return true;
        } catch (LockConflictedException $e) {
            $this->dirty = false;
            $this->logger?->info('Failed to acquire the "{resource}" lock. Someone else already acquired the lock.', ['resource' => $this->key]);

            if ($blocking) {
                throw $e;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger?->notice('Failed to acquire the "{resource}" lock.', ['resource' => $this->key, 'exception' => $e]);
            throw new LockAcquiringException(\sprintf('Failed to acquire the "%s" lock.', $this->key), 0, $e);
        }
    }

    public function acquireRead(bool $blocking = false): bool
    {
        $this->key->resetLifetime();
        try {
            if (!$this->store instanceof SharedLockStoreInterface) {
                $this->logger?->debug('Store does not support ReadLocks, fallback to WriteLock.', ['resource' => $this->key]);

                return $this->acquire($blocking);
            }
            if ($blocking) {
                if (!$this->store instanceof BlockingSharedLockStoreInterface) {
                    while (true) {
                        try {
                            $this->store->saveRead($this->key);
                            break;
                        } catch (LockConflictedException) {
                            usleep((100 + random_int(-10, 10)) * 1000);
                        }
                    }
                } else {
                    $this->store->waitAndSaveRead($this->key);
                }
            } else {
                $this->store->saveRead($this->key);
            }

            $this->dirty = true;
            $this->logger?->debug('Successfully acquired the "{resource}" lock for reading.', ['resource' => $this->key]);

            if ($this->ttl) {
                $this->refresh();
            }

            if ($this->key->isExpired()) {
                try {
                    $this->release();
                } catch (\Exception) {
                    // swallow exception to not hide the original issue
                }
                throw new LockExpiredException(\sprintf('Failed to store the "%s" lock.', $this->key));
            }

            return true;
        } catch (LockConflictedException $e) {
            $this->dirty = false;
            $this->logger?->info('Failed to acquire the "{resource}" lock. Someone else already acquired the lock.', ['resource' => $this->key]);

            if ($blocking) {
                throw $e;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger?->notice('Failed to acquire the "{resource}" lock.', ['resource' => $this->key, 'exception' => $e]);
            throw new LockAcquiringException(\sprintf('Failed to acquire the "%s" lock.', $this->key), 0, $e);
        }
    }

    public function refresh(?float $ttl = null): void
    {
        if (!$ttl ??= $this->ttl) {
            throw new InvalidArgumentException('You have to define an expiration duration.');
        }

        try {
            $this->key->resetLifetime();
            $this->store->putOffExpiration($this->key, $ttl);
            $this->dirty = true;

            if ($this->key->isExpired()) {
                try {
                    $this->release();
                } catch (\Exception) {
                    // swallow exception to not hide the original issue
                }
                throw new LockExpiredException(\sprintf('Failed to put off the expiration of the "%s" lock within the specified time.', $this->key));
            }

            $this->logger?->debug('Expiration defined for "{resource}" lock for "{ttl}" seconds.', ['resource' => $this->key, 'ttl' => $ttl]);
        } catch (LockConflictedException $e) {
            $this->dirty = false;
            $this->logger?->notice('Failed to define an expiration for the "{resource}" lock, someone else acquired the lock.', ['resource' => $this->key]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger?->notice('Failed to define an expiration for the "{resource}" lock.', ['resource' => $this->key, 'exception' => $e]);
            throw new LockAcquiringException(\sprintf('Failed to define an expiration for the "%s" lock.', $this->key), 0, $e);
        }
    }

    public function isAcquired(): bool
    {
        return $this->dirty = $this->store->exists($this->key);
    }

    public function release(): void
    {
        try {
            try {
                $this->store->delete($this->key);
                $this->dirty = false;
            } catch (LockReleasingException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw new LockReleasingException(\sprintf('Failed to release the "%s" lock.', $this->key), 0, $e);
            }

            if ($this->store->exists($this->key)) {
                throw new LockReleasingException(\sprintf('Failed to release the "%s" lock, the resource is still locked.', $this->key));
            }

            $this->logger?->debug('Successfully released the "{resource}" lock.', ['resource' => $this->key]);
        } catch (LockReleasingException $e) {
            $this->logger?->notice('Failed to release the "{resource}" lock.', ['resource' => $this->key]);
            throw $e;
        }
    }

    public function isExpired(): bool
    {
        return $this->key->isExpired();
    }

    public function getRemainingLifetime(): ?float
    {
        return $this->key->getRemainingLifetime();
    }
}
