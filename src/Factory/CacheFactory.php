<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Factory;

use InvalidArgumentException;
use Predis\Client as PredisClient;
use Predis\Connection\Parameters;
use Waffle\Commons\Cache\Adapter\ArrayCache;
use Waffle\Commons\Cache\Adapter\FileCache;
use Waffle\Commons\Cache\Adapter\RedisCache;
use Waffle\Commons\Cache\Exception\CacheBackendUnavailableException;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Cache\Constant;
use Waffle\Commons\Contracts\Data\Connection\ConnectionTrackerInterface;

/**
 * Builds a concrete PSR-16 cache adapter from a backend identifier and a flat options array.
 *
 * Kept parameter-driven (no `ConfigInterface` dependency) so the `cache` component remains
 * agnostic to how the host application stores configuration. The framework's
 * AppKernelFactory reads the YAML config and forwards an `array<string, mixed>`.
 *
 * Adapter selection follows {@see Constant::BACKEND_ARRAY}, {@see Constant::BACKEND_FILE},
 * and {@see Constant::BACKEND_REDIS}. Any other identifier yields an
 * `InvalidArgumentException` listing the supported names.
 */
final class CacheFactory
{
    /**
     * RFC-023 §3.2: on its own, Predis waits 5 s to connect and PHP's
     * `default_socket_timeout` (60 s) to read, so the cache's `/readyz` check could
     * outlast the readiness probe. Both default to 1 s here; a DSN that sets its own
     * (`?timeout=…&read_write_timeout=…`) keeps them.
     */
    public const float REDIS_TIMEOUT = 1.0;

    /**
     * @param ?ConnectionTrackerInterface $tracker DIAG-03 tracer forwarded to the Redis
     *        adapter so its worker-resident connection is surfaced (dev only; null = off).
     */
    public function __construct(
        private readonly ?ConnectionTrackerInterface $tracker = null,
    ) {}

    /**
     * @param array<string, mixed> $options Adapter-specific configuration.
     *   - array  : `default_ttl?: int`
     *   - file   : `directory: string` (required), `default_ttl?: int`
     *   - redis  : `dsn?: string` (defaults to redis://localhost:6379; connect and
     *              read/write time out after 1 s unless the DSN sets them),
     *              `prefix?: string`, `default_ttl?: int`
     *
     * @throws CacheBackendUnavailableException When a backend cannot be instantiated
     *                                          (e.g. missing required option, unreachable storage).
     * @throws InvalidArgumentException         When `$adapter` is not a supported identifier.
     */
    public function create(string $adapter, array $options = []): CacheInterface
    {
        return match ($adapter) {
            Constant::BACKEND_ARRAY => new ArrayCache(defaultTtl: $this->intOrNull($options, 'default_ttl')),
            Constant::BACKEND_FILE => $this->createFile($options),
            Constant::BACKEND_REDIS => $this->createRedis($options),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown cache adapter "%s". Supported: "%s", "%s", "%s".',
                $adapter,
                Constant::BACKEND_ARRAY,
                Constant::BACKEND_FILE,
                Constant::BACKEND_REDIS,
            )),
        };
    }

    /** @param array<string, mixed> $options */
    private function createFile(array $options): FileCache
    {
        $directory = $options['directory'] ?? null;
        if (!is_string($directory) || $directory === '') {
            throw new CacheBackendUnavailableException(
                backend: Constant::BACKEND_FILE,
                message: 'File cache adapter requires a non-empty "directory" option.',
            );
        }

        return new FileCache(baseDir: $directory, defaultTtl: $this->intOrNull($options, 'default_ttl'));
    }

    /** @param array<string, mixed> $options */
    private function createRedis(array $options): RedisCache
    {
        $dsn = $options['dsn'] ?? null;
        $parsed = is_string($dsn) && $dsn !== '' ? Parameters::parse($dsn) : [];
        $parameters = (is_array($parsed) ? $parsed : [])
        + [
            'timeout' => self::REDIS_TIMEOUT,
            'read_write_timeout' => self::REDIS_TIMEOUT,
        ];
        $prefixOption = $options['prefix'] ?? null;
        $prefix = is_string($prefixOption) && $prefixOption !== '' ? $prefixOption : 'waffle:cache:';

        return new RedisCache(
            client: new PredisClient($parameters),
            prefix: $prefix,
            defaultTtl: $this->intOrNull($options, 'default_ttl'),
            tracker: $this->tracker,
        );
    }

    /** @param array<string, mixed> $options */
    private function intOrNull(array $options, string $key): ?int
    {
        $value = $options[$key] ?? null;
        return is_int($value) ? $value : null;
    }
}
