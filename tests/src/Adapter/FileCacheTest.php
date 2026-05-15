<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Cache\Adapter\FileCache;
use Waffle\Commons\Cache\Exception\CacheBackendUnavailableException;
use WaffleTests\Commons\Cache\AbstractTestCase;

#[CoversClass(FileCache::class)]
final class FileCacheTest extends AbstractTestCase
{
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/waffle_filecache_' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->recursiveDelete($this->dir);
        }
        parent::tearDown();
    }

    public function testRoundtripAcrossInstances(): void
    {
        new FileCache($this->dir)->set('user.42', ['name' => 'Alice']);

        // New instance reading the same dir — verifies file persistence.
        static::assertSame(
            ['name' => 'Alice'],
            new FileCache($this->dir)->get('user.42'),
        );
    }

    public function testDeleteAndClear(): void
    {
        $cache = new FileCache($this->dir);
        $cache->setMultiple(['a' => 1, 'b' => 2]);
        $cache->delete('a');
        static::assertFalse($cache->has('a'));
        static::assertTrue($cache->has('b'));

        $cache->clear();
        static::assertFalse($cache->has('b'));
    }

    public function testHasRespectsExpiry(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('soon', 'v', -1);
        static::assertFalse($cache->has('soon'));
    }

    public function testDefaultTtlAppliesWhenNoneGiven(): void
    {
        $cache = new FileCache($this->dir, defaultTtl: -1);
        $cache->set('expiring', 'v');
        static::assertNull($cache->get('expiring'));
    }

    public function testGetMultipleReturnsKeysWithDefaults(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('a', 1);
        $result = iterator_to_array(
            (function () use ($cache) {
                yield from $cache->getMultiple(['a', 'missing'], 'fallback');
            })(),
        );
        static::assertSame(['a' => 1, 'missing' => 'fallback'], $result);
    }

    public function testRejectsUnwritableDirectory(): void
    {
        $this->expectException(CacheBackendUnavailableException::class);
        new FileCache('/proc/0/this-cannot-be-created');
    }

    public function testComputeCachesAndReusesValue(): void
    {
        $cache = new FileCache($this->dir);
        $calls = 0;
        $factory = static function () use (&$calls): string {
            $calls++;
            return 'computed-' . $calls;
        };

        $first = $cache->compute('key', $factory, ttl: 60);
        $second = $cache->compute('key', $factory, ttl: 60);

        static::assertSame('computed-1', $first);
        static::assertSame('computed-1', $second);
        static::assertSame(1, $calls, 'callback must run exactly once when the value is fresh');
    }

    public function testComputeRecomputesAfterExpiry(): void
    {
        $cache = new FileCache($this->dir);
        // Negative TTL = effectively already expired by the time we read again.
        $first = $cache->compute('k', static fn() => 'one', ttl: -1);
        $second = $cache->compute('k', static fn() => 'two', ttl: -1);

        static::assertSame('one', $first);
        static::assertSame('two', $second);
    }

    public function testComputeWithBetaZeroDisablesEarlyRecompute(): void
    {
        $cache = new FileCache($this->dir);
        $calls = 0;
        $factory = static function () use (&$calls): string {
            $calls++;
            return 'v' . $calls;
        };
        // beta=0 means "never early-recompute": with a long TTL the entry stays.
        $cache->compute('key', $factory, ttl: 600, beta: 0.0);
        $cache->compute('key', $factory, ttl: 600, beta: 0.0);
        static::assertSame(1, $calls);
    }

    private function recursiveDelete(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
