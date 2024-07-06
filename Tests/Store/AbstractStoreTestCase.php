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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
abstract class AbstractStoreTestCase extends TestCase
{
    abstract protected function getStore(): PersistingStoreInterface;

    public function testSave()
    {
        $store = $this->getStore();

        $key = new Key(__METHOD__);

        $this->assertFalse($store->exists($key));
        $store->save($key);
        $this->assertTrue($store->exists($key));
        $store->delete($key);
        $this->assertFalse($store->exists($key));
    }

    public function testSaveWithDifferentResources()
    {
        $store = $this->getStore();

        $key1 = new Key(__METHOD__.'1');
        $key2 = new Key(__METHOD__.'2');

        $store->save($key1);
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $store->save($key2);
        $this->assertTrue($store->exists($key1));
        $this->assertTrue($store->exists($key2));

        $store->delete($key1);
        $this->assertFalse($store->exists($key1));
        $this->assertTrue($store->exists($key2));

        $store->delete($key2);
        $this->assertFalse($store->exists($key1));
        $this->assertFalse($store->exists($key2));
    }

    public function testSaveWithDifferentKeysOnSameResources()
    {
        $store = $this->getStore();

        $key1 = new Key(__METHOD__);
        $key2 = new Key(__METHOD__);

        $store->save($key1);
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        try {
            $store->save($key2);
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

    public function testSaveTwice()
    {
        $store = $this->getStore();

        $key = new Key(__METHOD__);

        $store->save($key);
        $store->save($key);
        // just asserts it don't throw an exception
        $this->addToAssertionCount(1);

        $store->delete($key);
    }

    public function testDeleteIsolated()
    {
        $store = $this->getStore();

        $key1 = new Key(__METHOD__.'1');
        $key2 = new Key(__METHOD__.'2');

        $store->save($key1);
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));

        $store->delete($key2);
        $this->assertTrue($store->exists($key1));
        $this->assertFalse($store->exists($key2));
    }
}
