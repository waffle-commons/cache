<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Pool;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Cache\CacheItemInterface;
use Waffle\Commons\Cache\Adapter\ArrayCache;
use Waffle\Commons\Cache\Pool\CacheItem;
use Waffle\Commons\Cache\Pool\CachePool;
use WaffleTests\Commons\Cache\AbstractTestCase;

/**
 * Targets gaps in CachePool coverage: `deleteItems`, foreign-item `saveDeferred`,
 * and the `__destruct` deferred-flush.
 */
#[CoversClass(CachePool::class)]
#[CoversClass(CacheItem::class)]
final class CachePoolCoverageTest extends AbstractTestCase
{
    public function testDeleteItemsRemovesEveryListedKey(): void
    {
        $pool = new CachePool(new ArrayCache());
        $pool->save($pool->getItem('a')->set(1));
        $pool->save($pool->getItem('b')->set(2));
        $pool->save($pool->getItem('c')->set(3));

        $pool->deleteItems(['a', 'c']);

        static::assertFalse($pool->hasItem('a'));
        static::assertTrue($pool->hasItem('b'));
        static::assertFalse($pool->hasItem('c'));
    }

    public function testSaveDeferredRefusesForeignCacheItemImplementations(): void
    {
        $foreign = new class implements CacheItemInterface {
            public function getKey(): string
            {
                return 'k';
            }

            public function get(): mixed
            {
                return null;
            }

            public function isHit(): bool
            {
                return false;
            }

            public function set(mixed $value): static
            {
                return $this;
            }

            public function expiresAt(?\DateTimeInterface $expiration): static
            {
                return $this;
            }

            public function expiresAfter(null|int|DateInterval $time): static
            {
                return $this;
            }
        };

        static::assertFalse(new CachePool(new ArrayCache())->saveDeferred($foreign));
    }

    public function testDestructorAutoCommitsDeferredItems(): void
    {
        $store = new ArrayCache();
        $pool = new CachePool($store);

        $pool->saveDeferred($pool->getItem('auto')->set('persisted'));

        // Force the destructor to fire by dropping the only reference.
        unset($pool);

        static::assertSame('persisted', $store->get('auto'));
    }

    public function testDeleteItemAlsoRemovesPendingDeferred(): void
    {
        $store = new ArrayCache();
        $pool = new CachePool($store);

        $pool->saveDeferred($pool->getItem('drop-me')->set('value'));
        $pool->deleteItem('drop-me');
        $pool->commit();

        // After commit, the deferred entry is dropped — no value reached the store.
        static::assertNull($store->get('drop-me'));
    }
}
