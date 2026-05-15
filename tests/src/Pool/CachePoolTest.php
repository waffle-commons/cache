<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Pool;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Cache\Adapter\ArrayCache;
use Waffle\Commons\Cache\Pool\CacheItem;
use Waffle\Commons\Cache\Pool\CachePool;
use WaffleTests\Commons\Cache\AbstractTestCase;

#[CoversClass(CachePool::class)]
#[CoversClass(CacheItem::class)]
final class CachePoolTest extends AbstractTestCase
{
    public function testGetItemReturnsMissForUnknownKey(): void
    {
        $pool = new CachePool(new ArrayCache());
        $item = $pool->getItem('missing');

        static::assertFalse($item->isHit());
        static::assertNull($item->get());
    }

    public function testSaveAndGetItemRoundtrip(): void
    {
        $pool = new CachePool(new ArrayCache());
        $item = $pool->getItem('answer')->set(42);
        $pool->save($item);

        $loaded = $pool->getItem('answer');
        static::assertTrue($loaded->isHit());
        static::assertSame(42, $loaded->get());
    }

    public function testDeleteItem(): void
    {
        $pool = new CachePool(new ArrayCache());
        $pool->save($pool->getItem('x')->set(1));

        static::assertTrue($pool->hasItem('x'));
        $pool->deleteItem('x');
        static::assertFalse($pool->hasItem('x'));
    }

    public function testDeferredItemsCommitTogether(): void
    {
        $store = new ArrayCache();
        $pool = new CachePool($store);

        $pool->saveDeferred($pool->getItem('a')->set(1));
        $pool->saveDeferred($pool->getItem('b')->set(2));

        // Before commit, the underlying store is empty.
        static::assertNull($store->get('a'));
        static::assertNull($store->get('b'));

        $pool->commit();
        static::assertSame(1, $store->get('a'));
        static::assertSame(2, $store->get('b'));
    }

    public function testClearAlsoDropsDeferred(): void
    {
        $pool = new CachePool(new ArrayCache());
        $pool->saveDeferred($pool->getItem('a')->set(1));
        $pool->clear();
        static::assertFalse($pool->hasItem('a'));
    }

    public function testCacheItemExpiresAtIsHonored(): void
    {
        $store = new ArrayCache();
        $pool = new CachePool($store);

        $item = $pool->getItem('soon')
            ->set('v')
            ->expiresAt(new \DateTimeImmutable('-1 second'));
        $pool->save($item);

        // The PSR-16 store will see TTL = expiresAt - time() = -1 → expired immediately.
        static::assertNull($store->get('soon'));
    }

    public function testCacheItemExpiresAfterAcceptsDateInterval(): void
    {
        $pool = new CachePool(new ArrayCache());
        $item = $pool->getItem('lives')
            ->set('v')
            ->expiresAfter(new DateInterval('PT60S'));
        $pool->save($item);

        static::assertTrue($pool->hasItem('lives'));
    }

    public function testCacheItemExpiresAfterNullClearsExpiration(): void
    {
        $pool = new CachePool(new ArrayCache());
        $item = $pool->getItem('immortal')
            ->set('v')
            ->expiresAfter(60)
            ->expiresAfter(null);
        static::assertInstanceOf(CacheItem::class, $item);
        static::assertNull($item->getTtlSeconds());
    }

    public function testGetItemsReturnsMapKeyedByName(): void
    {
        $pool = new CachePool(new ArrayCache());
        $pool->save($pool->getItem('a')->set(1));

        $items = iterator_to_array(
            (function () use ($pool) {
                yield from $pool->getItems(['a', 'b']);
            })(),
        );
        static::assertTrue($items['a']->isHit());
        static::assertFalse($items['b']->isHit());
    }

    public function testSaveRefusesForeignCacheItemImplementations(): void
    {
        $foreign = new class implements \Psr\Cache\CacheItemInterface {
            public function getKey(): string { return 'k'; }
            public function get(): mixed { return null; }
            public function isHit(): bool { return false; }
            public function set(mixed $value): static { return $this; }
            public function expiresAt(?\DateTimeInterface $expiration): static { return $this; }
            public function expiresAfter(null|int|DateInterval $time): static { return $this; }
        };

        static::assertFalse(new CachePool(new ArrayCache())->save($foreign));
    }
}
