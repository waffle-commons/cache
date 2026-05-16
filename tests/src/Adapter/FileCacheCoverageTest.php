<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Cache\Adapter\FileCache;
use WaffleTests\Commons\Cache\AbstractTestCase;

/**
 * Targets uncovered branches in FileCache: clear-with-no-dir, corrupt payload
 * unserialization, and `has()` returning false on expired entries.
 */
#[CoversClass(FileCache::class)]
final class FileCacheCoverageTest extends AbstractTestCase
{
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/waffle_filecache_cov_' . bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testSetMultipleRejectsNonStringKey(): void
    {
        $cache = new FileCache($this->dir);
        $this->expectException(\Waffle\Commons\Cache\Exception\InvalidCacheKeyException::class);
        $cache->setMultiple([42 => 'value']);
    }

    public function testDeleteMultipleRejectsNonStringKey(): void
    {
        $cache = new FileCache($this->dir);
        $this->expectException(\Waffle\Commons\Cache\Exception\InvalidCacheKeyException::class);
        $cache->deleteMultiple([7]);
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('lasting', 'value', new \DateInterval('PT60S'));
        static::assertSame('value', $cache->get('lasting'));
    }

    public function testClearOnEmptyButValidDirIsNoOp(): void
    {
        $cache = new FileCache($this->dir);
        static::assertTrue($cache->clear());
    }

    public function testCorruptedPayloadReturnsDefaultOnRead(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('user.99', ['id' => 99]);

        // Find the actual stored file and corrupt it.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $cacheFile = null;
        foreach ($iterator as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getPathname(), '.cache')) {
                $cacheFile = $entry->getPathname();
                break;
            }
        }
        static::assertNotNull($cacheFile, 'FileCache should have written at least one .cache file');

        // Overwrite with garbage — the unserialize fallback should kick in.
        file_put_contents($cacheFile, 'this is not a serialized array');
        static::assertSame('fallback', $cache->get('user.99', 'fallback'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $cache = new FileCache($this->dir);
        static::assertFalse($cache->has('never-stored'));
    }

    public function testDeleteOnMissingKeyReturnsTrue(): void
    {
        $cache = new FileCache($this->dir);
        // PSR-16 §2.1: delete returns true even when the key did not exist.
        static::assertTrue($cache->delete('never-stored'));
    }

    public function testGetReturnsDefaultForExpiredEntry(): void
    {
        $cache = new FileCache($this->dir);
        $cache->set('soon', 'v', -1); // expired immediately
        static::assertSame('fallback', $cache->get('soon', 'fallback'));
    }

    public function testComputeXFetchRecomputeFiresEarlyWithHighBeta(): void
    {
        $cache = new FileCache($this->dir);

        // First compute records a non-trivial delta (~100ms callback). The next
        // call uses a high beta so XFetch's jitter (delta * beta * -ln(r)) far
        // exceeds the remaining TTL, forcing an early recompute even though the
        // entry is still technically valid.
        $first = $cache->compute(
            'key',
            static function (): string {
                usleep(100_000);
                return 'v1';
            },
            ttl: 2,
        );

        $second = $cache->compute('key', static fn(): string => 'v2', ttl: 60, beta: 1000.0);

        static::assertSame('v1', $first);
        static::assertSame('v2', $second);
    }

    public function testClearReturnsTrueWhenBaseDirIsAlreadyGone(): void
    {
        $cache = new FileCache($this->dir);
        // Simulate an external `rm -rf` between construct and clear — clear must
        // still report success because there's nothing left to wipe.
        @rmdir($this->dir);
        static::assertTrue($cache->clear());
    }

    public function testComputeReusesCachedValueForNeverExpiringEntry(): void
    {
        $cache = new FileCache($this->dir); // defaultTtl: null
        $cache->set('immortal', 'cached-value');
        $calls = 0;
        $result = $cache->compute(
            'immortal',
            function () use (&$calls): string {
                $calls++;
                return 'should-not-run';
            },
            ttl: 60,
        );

        static::assertSame('cached-value', $result);
        static::assertSame(0, $calls);
    }

    public function testSetReturnsFalseWhenShardDirectoryCannotBeCreated(): void
    {
        $cache = new FileCache($this->dir);

        // Plant a regular file at the SHA-256 shard prefix of `$key`. The next set()
        // must hit writeEntry's `mkdir` failure branch — there's a non-directory
        // squatting on the path FileCache wants to create.
        $key = 'blocked-key';
        $shard = substr(hash('sha256', $key), 0, 2);
        $blocker = $this->dir . DIRECTORY_SEPARATOR . $shard;
        file_put_contents($blocker, 'blocking-non-dir');

        static::assertFalse($cache->set($key, 'value'));

        // Cleanup — release the blocker so tearDown can rmdir the parent.
        @unlink($blocker);
    }
}
