<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Factory;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Predis\Client as PredisClient;
use Predis\Connection\NodeConnectionInterface;
use ReflectionProperty;
use Waffle\Commons\Cache\Adapter\ArrayCache;
use Waffle\Commons\Cache\Adapter\FileCache;
use Waffle\Commons\Cache\Adapter\RedisCache;
use Waffle\Commons\Cache\Exception\CacheBackendUnavailableException;
use Waffle\Commons\Cache\Factory\CacheFactory;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Cache\Constant;
use WaffleTests\Commons\Cache\AbstractTestCase;

#[CoversClass(CacheFactory::class)]
final class CacheFactoryTest extends AbstractTestCase
{
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/waffle_cache_factory_' . bin2hex(random_bytes(4));
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

    public function testArrayAdapter(): void
    {
        $cache = new CacheFactory()->create(Constant::BACKEND_ARRAY, ['default_ttl' => 60]);
        static::assertInstanceOf(ArrayCache::class, $cache);

        $cache->set('k', 'v');
        static::assertSame('v', $cache->get('k'));
    }

    public function testFileAdapter(): void
    {
        $cache = new CacheFactory()->create(Constant::BACKEND_FILE, ['directory' => $this->dir, 'default_ttl' => 60]);
        static::assertInstanceOf(FileCache::class, $cache);

        $cache->set('user.7', ['id' => 7]);
        static::assertSame(['id' => 7], $cache->get('user.7'));
    }

    public function testFileAdapterRequiresDirectory(): void
    {
        $this->expectException(CacheBackendUnavailableException::class);
        $this->expectExceptionMessage('non-empty "directory"');
        new CacheFactory()->create(Constant::BACKEND_FILE, []);
    }

    public function testFileAdapterRejectsEmptyDirectoryString(): void
    {
        $this->expectException(CacheBackendUnavailableException::class);
        new CacheFactory()->create(Constant::BACKEND_FILE, ['directory' => '']);
    }

    public function testRedisAdapterBuildsWithDefaults(): void
    {
        // Predis::Client lazy-connects, so constructing the adapter doesn't require a live Redis.
        $cache = new CacheFactory()->create(Constant::BACKEND_REDIS, []);
        static::assertInstanceOf(RedisCache::class, $cache);
    }

    public function testRedisAdapterAcceptsDsnAndPrefix(): void
    {
        $cache = new CacheFactory()->create(Constant::BACKEND_REDIS, [
            'dsn' => 'tcp://localhost:6379',
            'prefix' => 'custom:cache:',
            'default_ttl' => 120,
        ]);
        static::assertInstanceOf(RedisCache::class, $cache);
    }

    /**
     * RFC-023 §3.2: the cache's /readyz check must give up before the readiness probe does, so the
     * Predis client connects and reads within one second unless the DSN says otherwise.
     */
    public function testRedisAdapterTimesOutWithinASecondByDefault(): void
    {
        $parameters = self::redisParameters(new CacheFactory()->create(Constant::BACKEND_REDIS, [
            'dsn' => 'redis://:secret@waffle-redis:6380',
        ]));

        static::assertSame(CacheFactory::REDIS_TIMEOUT, $parameters['timeout'] ?? null);
        static::assertSame(CacheFactory::REDIS_TIMEOUT, $parameters['read_write_timeout'] ?? null);
        static::assertSame('waffle-redis', $parameters['host'] ?? null);
        static::assertSame(6380, $parameters['port'] ?? null);
        static::assertSame('secret', $parameters['password'] ?? null);
    }

    public function testATimeoutTheDsnSetsIsKept(): void
    {
        $parameters = self::redisParameters(new CacheFactory()->create(Constant::BACKEND_REDIS, [
            'dsn' => 'redis://waffle-redis:6379?timeout=3&read_write_timeout=4',
        ]));

        static::assertSame('3', $parameters['timeout'] ?? null);
        static::assertSame('4', $parameters['read_write_timeout'] ?? null);
    }

    public function testWithoutADsnTheTimeoutsStillApply(): void
    {
        $parameters = self::redisParameters(new CacheFactory()->create(Constant::BACKEND_REDIS, []));

        static::assertSame(CacheFactory::REDIS_TIMEOUT, $parameters['timeout'] ?? null);
        static::assertSame(CacheFactory::REDIS_TIMEOUT, $parameters['read_write_timeout'] ?? null);
    }

    /**
     * The connection parameters of the Predis client a Redis cache holds.
     *
     * @return array<string, scalar|null>
     */
    private static function redisParameters(CacheInterface $cache): array
    {
        static::assertInstanceOf(RedisCache::class, $cache);
        $client = new ReflectionProperty(RedisCache::class, 'client')->getValue($cache);
        static::assertInstanceOf(PredisClient::class, $client);
        $connection = $client->getConnection();
        static::assertInstanceOf(NodeConnectionInterface::class, $connection);

        $all = $connection->getParameters()->toArray();
        static::assertIsArray($all);
        $parameters = [];
        foreach ($all as $name => $value) {
            if (is_string($name) && (is_scalar($value) || $value === null)) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }

    public function testUnknownAdapterRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown cache adapter "memcached"');
        new CacheFactory()->create('memcached');
    }

    public function testNonIntegerTtlIgnored(): void
    {
        // Strings or other non-int payloads in `default_ttl` are silently dropped — the adapter
        // receives null (no implicit TTL), preventing accidental cast bugs.
        $cache = new CacheFactory()->create(Constant::BACKEND_ARRAY, ['default_ttl' => '60']);
        static::assertInstanceOf(ArrayCache::class, $cache);

        $cache->set('lasting', 'value');
        static::assertSame('value', $cache->get('lasting'));
    }
}
