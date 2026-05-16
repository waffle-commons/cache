<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Adapter;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use Predis\Client as PredisClient;
use Predis\PredisException;
use Throwable;
use Waffle\Commons\Cache\Adapter\RedisCache;
use Waffle\Commons\Cache\Exception\CacheBackendUnavailableException;
use WaffleTests\Commons\Cache\AbstractTestCase;

/**
 * Targets the previously-uncovered error paths and multi-key operations on
 * `RedisCache`, bringing the adapter to >=95% line coverage per Alpha 6 DoD.
 */
#[CoversClass(RedisCache::class)]
final class RedisCacheCoverageTest extends AbstractTestCase
{
    public function testSetWrapsPredisExceptionAsBackendUnavailable(): void
    {
        $client = $this->failingClient('setex');
        $this->expectException(CacheBackendUnavailableException::class);
        new RedisCache($client)->set('k', 'v', 60);
    }

    public function testDeleteWrapsPredisException(): void
    {
        $client = $this->failingClient('del');
        $this->expectException(CacheBackendUnavailableException::class);
        new RedisCache($client)->delete('k');
    }

    public function testClearWrapsPredisException(): void
    {
        $client = $this->failingClient('scan');
        $this->expectException(CacheBackendUnavailableException::class);
        new RedisCache($client)->clear();
    }

    public function testHasWrapsPredisException(): void
    {
        $client = $this->failingClient('exists');
        $this->expectException(CacheBackendUnavailableException::class);
        new RedisCache($client)->has('k');
    }

    public function testSetMultiplePersistsAllValuesAndWrapsErrors(): void
    {
        $client = $this->recordingClient();
        new RedisCache($client)->setMultiple(['a' => 1, 'b' => 2], ttl: 60);

        static::assertCount(2, $client->callsFor('setex'));
    }

    public function testSetMultipleRejectsNonStringKey(): void
    {
        $client = $this->recordingClient();
        $this->expectException(\Waffle\Commons\Cache\Exception\InvalidCacheKeyException::class);
        new RedisCache($client)->setMultiple([42 => 'value']);
    }

    public function testSetMultipleWrapsPredisException(): void
    {
        $client = $this->failingClient('setex');
        $this->expectException(CacheBackendUnavailableException::class);
        new RedisCache($client)->setMultiple(['k' => 'v'], 60);
    }

    public function testDeleteMultipleHappyPath(): void
    {
        $client = $this->recordingClient();
        $result = new RedisCache($client)->deleteMultiple(['a', 'b', 'c']);

        static::assertTrue($result);
        $calls = $client->callsFor('del');
        static::assertCount(1, $calls);
        static::assertSame(['waffle:cache:a', 'waffle:cache:b', 'waffle:cache:c'], $calls[0][0]);
    }

    public function testDeleteMultipleWithEmptyListIsNoOp(): void
    {
        $client = $this->recordingClient();
        static::assertTrue(new RedisCache($client)->deleteMultiple([]));
        static::assertCount(0, $client->callsFor('del'));
    }

    public function testDeleteMultipleWrapsPredisException(): void
    {
        $client = $this->failingClient('del');
        $this->expectException(CacheBackendUnavailableException::class);
        new RedisCache($client)->deleteMultiple(['k']);
    }

    public function testGetMultipleWithEmptyKeysReturnsEmpty(): void
    {
        $client = $this->recordingClient();
        $out = new RedisCache($client)->getMultiple([], 'fb');

        static::assertSame(
            [],
            iterator_to_array(
                (function () use ($out) {
                    yield from $out;
                })(),
            ),
        );
    }

    public function testGetMultipleWrapsPredisException(): void
    {
        $client = $this->failingClient('mget');
        $this->expectException(CacheBackendUnavailableException::class);
        new RedisCache($client)->getMultiple(['a', 'b']);
    }

    public function testSetWithoutTtlUsesSetCommand(): void
    {
        $client = $this->recordingClient();
        new RedisCache($client, defaultTtl: null)->set('k', 'v');

        static::assertCount(1, $client->callsFor('set'));
        static::assertCount(0, $client->callsFor('setex'));
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $client = $this->recordingClient();
        new RedisCache($client)->set('k', 'v', new DateInterval('PT2M'));

        $calls = $client->callsFor('setex');
        static::assertCount(1, $calls);
        // resolveTtlSeconds returns approximately 120 — allow ±2s clock drift.
        static::assertGreaterThanOrEqual(118, $calls[0][1]);
        static::assertLessThanOrEqual(122, $calls[0][1]);
    }

    public function testComputeWrapsPredisException(): void
    {
        $client = $this->failingClient('get');
        $this->expectException(CacheBackendUnavailableException::class);
        new RedisCache($client)->compute('k', static fn(): string => 'x', ttl: 60);
    }

    public function testComputeIgnoresCorruptedPayloadAndRecomputes(): void
    {
        $client = $this->recordingClient(getResponse: 'not-a-serialized-array', ttlResponse: 60);
        $calls = 0;
        $result = new RedisCache($client)->compute(
            'k',
            function () use (&$calls): string {
                $calls++;
                return 'recomputed';
            },
            ttl: 60,
        );

        static::assertSame('recomputed', $result);
        static::assertSame(1, $calls);
    }

    private function failingClient(string $failingCommand): PredisClient
    {
        $boom = new class('boom') extends PredisException {};

        return new class($failingCommand, $boom) extends PredisClient {
            public function __construct(
                private string $failingCommand,
                private Throwable $exception,
            ) {
                // Intentionally skip parent::__construct — never open a connection.
            }

            #[\Override]
            public function __call($commandID, $arguments)
            {
                if ($commandID === $this->failingCommand) {
                    throw $this->exception;
                }
                return match ($commandID) {
                    'get' => null,
                    'ttl' => -2,
                    'exists' => 0,
                    'scan' => [0, []],
                    'mget' => [],
                    default => true,
                };
            }
        };
    }

    private function recordingClient(mixed $getResponse = null, int $ttlResponse = -2): PredisClient
    {
        return new class($getResponse, $ttlResponse) extends PredisClient {
            /** @var array<string, list<array<array-key, mixed>>> */
            public array $calls = [];

            public function __construct(
                private mixed $getResponse,
                private int $ttlResponse,
            ) {
                // Intentionally skip parent::__construct — never open a connection.
            }

            #[\Override]
            public function __call($commandID, $arguments)
            {
                $this->calls[$commandID][] = $arguments;
                return match ($commandID) {
                    'get' => $this->getResponse,
                    'ttl' => $this->ttlResponse,
                    'mget' => [],
                    'exists' => 0,
                    'scan' => [0, []],
                    default => true,
                };
            }

            /** @return list<array<array-key, mixed>> */
            public function callsFor(string $command): array
            {
                return $this->calls[$command] ?? [];
            }
        };
    }
}
