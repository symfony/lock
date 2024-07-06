<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Lock\Tests\Store;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
trait SharedLockStoreTestTrait
{
    /**
     * @see AbstractStoreTestCase::getStore()
     */
    abstract protected function getStore(): PersistingStoreInterface;

    public function testSharedLockReadFirst()
    {
        $store = $this->getStore();

        $key1 = new Key(__METHOD__);
        $key2 = new Key(__METHOD__);
        $key3 = new Key(__METHOD__);

        $store->saveRead($key1);
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));
        $this->assertFalse($store->exists($key3));

        // assert we can store multiple keys in read mode
        $store->saveRead($key2);
        $this->assertTrue($store->exists($key1));
        $this->assertTrue($store->exists($key2));
        $this->assertFalse($store->exists($key3));

        try {
            $store->save($key3);
            $this->fail('The store shouldn\'t save the second key');
        } catch (LockConflictedException $e) {
        }

        // The failure of previous attempt should not impact the state of current locks
        $this->assertTrue($store->exists($key1));
        $this->assertTrue($store->exists($key2));
        $this->assertFalse($store->exists($key3));

        $store->delete($key1);
        $this->assertFalse($store->exists($key1));
        $this->assertTrue($store->exists($key2));
        $this->assertFalse($store->exists($key3));

        $store->delete($key2);
        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));
        $this->assertFalse($store->exists($key3));

        $store->save($key3);
        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));
        $this->assertTrue($store->exists($key3));

        $store->delete($key3);
        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));
        $this->assertFalse($store->exists($key3));
    }

    public function testSharedLockWriteFirst()
    {
        $store = $this->getStore();
        $key1 = new Key(__METHOD__);
        $key2 = new Key(__METHOD__);

        $store->save($key1);
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        try {
            $store->saveRead($key2);
            $this->fail('The store shouldn\'t save the second key');
        } catch (LockConflictedException $e) {
        }

        // The failure of previous attempt should not impact the state of current locks
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $store->delete($key1);
        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $store->save($key2);
        $this->assertFalse($store->exists($key1));
        $this->assertTrue($store->exists($key2));

        $store->delete($key2);
        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));
    }

    public function testSharedLockPromote()
    {
        $store = $this->getStore();

        $key1 = new Key(__METHOD__);
        $key2 = new Key(__METHOD__);

        $store->saveRead($key1);
        $store->saveRead($key2);
        $this->assertTrue($store->exists($key1));
        $this->assertTrue($store->exists($key2));

        try {
            $store->save($key1);
            $this->fail('The store shouldn\'t save the second key');
        } catch (LockConflictedException $e) {
        }
    }

    public function testSharedLockPromoteAllowed()
    {
        $store = $this->getStore();

        $key1 = new Key(static::class.__METHOD__);
        $key2 = new Key(static::class.__METHOD__);

        $store->saveRead($key1);
        $store->save($key1);

        try {
            $store->saveRead($key2);
            $this->fail('The store shouldn\'t save the second key');
        } catch (LockConflictedException $e) {
        }
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $store->delete($key1);
        $store->saveRead($key2);
        $this->assertFalse($store->exists($key1));
        $this->assertTrue($store->exists($key2));
    }

    public function testSharedLockDemote()
    {
        $store = $this->getStore();

        $key1 = new Key(static::class.__METHOD__);
        $key2 = new Key(static::class.__METHOD__);

        $store->save($key1);
        $store->saveRead($key1);
        $store->saveRead($key2);

        $this->assertTrue($store->exists($key1));
        $this->assertTrue($store->exists($key2));
    }
}
