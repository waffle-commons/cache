<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Adapter;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Cache\Adapter\ArrayCache;
use Waffle\Commons\Cache\Exception\InvalidCacheKeyException;
use WaffleTests\Commons\Cache\AbstractTestCase;

#[CoversClass(ArrayCache::class)]
final class ArrayCacheTest extends AbstractTestCase
{
    public function testGetReturnsDefaultWhenMissing(): void
    {
        $cache = new ArrayCache();
        static::assertSame('fallback', $cache->get('missing', 'fallback'));
    }

    public function testSetAndGetRoundtrip(): void
    {
        $cache = new ArrayCache();
        $cache->set('user.42', ['name' => 'Alice']);
        static::assertSame(['name' => 'Alice'], $cache->get('user.42'));
    }

    public function testHasReportsExistence(): void
    {
        $cache = new ArrayCache();
        $cache->set('present', 1);
        static::assertTrue($cache->has('present'));
        static::assertFalse($cache->has('missing'));
    }

    public function testDelete(): void
    {
        $cache = new ArrayCache();
        $cache->set('k', 'v');
        $cache->delete('k');
        static::assertFalse($cache->has('k'));
    }

    public function testClearRemovesEverything(): void
    {
        $cache = new ArrayCache();
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->clear();
        static::assertFalse($cache->has('a'));
        static::assertFalse($cache->has('b'));
    }

    public function testGetMultipleAndSetMultiple(): void
    {
        $cache = new ArrayCache();
        $cache->setMultiple(['a' => 1, 'b' => 2]);
        $result = iterator_to_array(
            (function () use ($cache) {
                yield from $cache->getMultiple(['a', 'b', 'missing'], 'fallback');
            })(),
        );
        static::assertSame(['a' => 1, 'b' => 2, 'missing' => 'fallback'], $result);
    }

    public function testDeleteMultiple(): void
    {
        $cache = new ArrayCache();
        $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
        $cache->deleteMultiple(['a', 'c']);
        static::assertFalse($cache->has('a'));
        static::assertTrue($cache->has('b'));
        static::assertFalse($cache->has('c'));
    }

    public function testIntegerTtlExpiresAfterSimulatedClockAdvance(): void
    {
        // TTL of -1 effectively means already-expired (resolveExpiry = time() - 1).
        $cache = new ArrayCache();
        $cache->set('expiring', 'value', -1);
        static::assertNull($cache->get('expiring'));
    }

    public function testDateIntervalTtlIsHonored(): void
    {
        $cache = new ArrayCache();
        $cache->set('lives', 'value', new DateInterval('PT60S'));
        static::assertSame('value', $cache->get('lives'));
    }

    public function testDefaultTtlAppliesWhenNonePassed(): void
    {
        $cache = new ArrayCache(defaultTtl: -1); // immediate expiry
        $cache->set('expiring', 'value');
        static::assertNull($cache->get('expiring'));
    }

    public function testRejectsInvalidKey(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        new ArrayCache()->set('bad:key', 'v');
    }

    public function testRejectsNonStringKeyInSetMultiple(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        new ArrayCache()->setMultiple([42 => 'value']);
    }

    public function testResetClearsStoreBetweenRequests(): void
    {
        $cache = new ArrayCache();
        $cache->set('user.42', ['name' => 'Alice']);
        static::assertTrue($cache->has('user.42'));

        $cache->reset();

        static::assertFalse($cache->has('user.42'));
        static::assertSame('fallback', $cache->get('user.42', 'fallback'));
    }
}
