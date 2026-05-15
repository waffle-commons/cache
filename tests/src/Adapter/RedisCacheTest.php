<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use Predis\Client as PredisClient;
use Predis\PredisException;
use Throwable;
use Waffle\Commons\Cache\Adapter\RedisCache;
use Waffle\Commons\Cache\Exception\CacheBackendUnavailableException;
use WaffleTests\Commons\Cache\AbstractTestCase;

/**
 * Unit-test RedisCache against a stub Predis client.
 *
 * Predis routes commands through `__call`, so PHPUnit's standard method-mocking
 * can't capture them directly. We use a small in-memory stub that records calls
 * and returns scripted responses.
 */
#[CoversClass(RedisCache::class)]
final class RedisCacheTest extends AbstractTestCase
{
    public function testGetReturnsDefaultOnMiss(): void
    {
        $client = $this->stubClient(getResponse: null);
        static::assertSame('fallback', new RedisCache($client)->get('missing', 'fallback'));
    }

    public function testSetEncodesAndDelegatesToSetex(): void
    {
        $client = $this->stubClient();
        new RedisCache($client)->set('k', 'value', 60);

        $calls = $client->callsFor('setex');
        static::assertCount(1, $calls);
        static::assertSame('waffle:cache:k', $calls[0][0]);
        static::assertSame(60, $calls[0][1]);
        static::assertIsString($calls[0][2]);
    }

    public function testSetWithoutTtlDelegatesToSet(): void
    {
        $client = $this->stubClient();
        new RedisCache($client)->set('k', 'value'); // no TTL

        static::assertCount(1, $client->callsFor('set'));
        static::assertCount(0, $client->callsFor('setex'));
    }

    public function testGetRoundtripsValue(): void
    {
        $payload = serialize(['value' => 'hello', 'delta' => 0.0]);
        $client = $this->stubClient(getResponse: $payload);

        static::assertSame('hello', new RedisCache($client)->get('k'));
    }

    public function testGetReturnsDefaultWhenPayloadCorrupt(): void
    {
        $client = $this->stubClient(getResponse: 'not-a-serialized-array');
        static::assertSame('fb', new RedisCache($client)->get('k', 'fb'));
    }

    public function testPredisExceptionWrappedAsBackendUnavailable(): void
    {
        $boom = new class('boom') extends PredisException {};
        $client = $this->stubClient(throwOnGet: $boom);

        $this->expectException(CacheBackendUnavailableException::class);
        $this->expectExceptionMessage('Redis backend unavailable');
        new RedisCache($client)->get('k');
    }

    public function testDeleteCallsDel(): void
    {
        $client = $this->stubClient();
        new RedisCache($client)->delete('k');

        $calls = $client->callsFor('del');
        static::assertCount(1, $calls);
        static::assertSame(['waffle:cache:k'], $calls[0][0]);
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $client = $this->stubClient(existsResponse: 1);
        static::assertTrue(new RedisCache($client)->has('k'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $client = $this->stubClient(existsResponse: 0);
        static::assertFalse(new RedisCache($client)->has('k'));
    }

    public function testClearScansAndDeletesPrefixedKeys(): void
    {
        $client = $this->stubClient(scanResponses: [[0, ['waffle:cache:a', 'waffle:cache:b']]]);
        static::assertTrue(new RedisCache($client)->clear());

        static::assertCount(1, $client->callsFor('del'));
    }

    public function testComputeFreshKeyInvokesCallbackAndCaches(): void
    {
        $client = $this->stubClient(getResponse: null);
        $calls = 0;
        $result = new RedisCache($client)->compute('k', function () use (&$calls): string {
            $calls++;
            return 'computed';
        }, ttl: 60);

        static::assertSame('computed', $result);
        static::assertSame(1, $calls);
        static::assertCount(1, $client->callsFor('setex'));
    }

    public function testGetMultipleReturnsKeyedMap(): void
    {
        $values = [
            serialize(['value' => 'A', 'delta' => 0.0]),
            null,
        ];
        $client = $this->stubClient(mgetResponse: $values);

        $result = iterator_to_array(
            (function () use ($client) {
                yield from new RedisCache($client)->getMultiple(['a', 'b'], 'fb');
            })(),
        );

        static::assertSame(['a' => 'A', 'b' => 'fb'], $result);
    }

    /**
     * @param list<array{0: int, 1: list<string>}> $scanResponses
     * @param list<mixed>|null $mgetResponse
     */
    private function stubClient(
        mixed $getResponse = null,
        mixed $existsResponse = null,
        array $scanResponses = [[0, []]],
        ?array $mgetResponse = null,
        ?Throwable $throwOnGet = null,
    ): PredisClient {
        return new class($getResponse, $existsResponse, $scanResponses, $mgetResponse, $throwOnGet) extends PredisClient {
            /** @var array<string, list<array<array-key, mixed>>> */
            public array $calls = [];

            /**
             * @param list<array{0: int, 1: list<string>}> $scanResponses
             * @param list<mixed>|null $mgetResponse
             */
            public function __construct(
                private mixed $getResponse,
                private mixed $existsResponse,
                private array $scanResponses,
                private ?array $mgetResponse,
                private ?Throwable $throwOnGet,
            ) {
                // Intentionally skip parent::__construct — never open a real connection.
            }

            #[\Override]
            public function __call($commandID, $arguments)
            {
                $this->calls[$commandID][] = $arguments;

                return match ($commandID) {
                    'get' => $this->throwOnGet !== null ? throw $this->throwOnGet : $this->getResponse,
                    'mget' => $this->mgetResponse ?? [],
                    'ttl' => 60,
                    'exists' => $this->existsResponse ?? 0,
                    'scan' => array_shift($this->scanResponses) ?? [0, []],
                    'setex', 'set', 'del' => true,
                    default => null,
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
