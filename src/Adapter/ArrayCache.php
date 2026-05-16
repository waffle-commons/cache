<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Adapter;

use DateInterval;
use DateTimeImmutable;
use Waffle\Commons\Cache\KeyValidator;
use Waffle\Commons\Contracts\Cache\CacheInterface;

/**
 * In-memory, worker-scoped PSR-16 cache (RFC-013 §3.1).
 *
 * Lifecycle: lives for one FrankenPHP worker iteration. Extremely fast — direct
 * array lookups — but useless across requests/workers. Pair with a persistent
 * adapter (File/Redis) via a layered cache strategy if cross-worker visibility
 * is required.
 *
 * Not stampede-protected: single-worker memory has no concurrent contention.
 */
final class ArrayCache implements CacheInterface
{
    /**
     * @var array<string, array{value: mixed, expires: int|null}>
     */
    private array $store = [];

    /**
     * @param int|null $defaultTtl Default TTL in seconds when callers omit one.
     *                             `null` means "never expires for this worker".
     */
    public function __construct(
        private readonly ?int $defaultTtl = null,
    ) {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        KeyValidator::assertValid($key);

        if (!array_key_exists($key, $this->store)) {
            return $default;
        }

        $entry = $this->store[$key];
        if ($entry['expires'] !== null && $entry['expires'] <= time()) {
            unset($this->store[$key]);
            return $default;
        }

        return $entry['value'];
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        KeyValidator::assertValid($key);

        $expires = $this->resolveExpiry($ttl);
        $this->store[$key] = ['value' => $value, 'expires' => $expires];

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        KeyValidator::assertValid($key);

        unset($this->store[$key]);
        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $validated = KeyValidator::assertValidAll($keys);
        $result = [];
        foreach ($validated as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new \Waffle\Commons\Cache\Exception\InvalidCacheKeyException(key: '', message: sprintf(
                    'Cache key must be a string, got %s.',
                    get_debug_type($key),
                ));
            }
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach (KeyValidator::assertValidAll($keys) as $key) {
            unset($this->store[$key]);
        }
        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->get($key, '__waffle_cache_miss__') !== '__waffle_cache_miss__';
    }

    /** Converts a PSR-16 TTL spec to an absolute expiry timestamp (or null = never). */
    private function resolveExpiry(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return $this->defaultTtl === null ? null : time() + $this->defaultTtl;
        }
        if (is_int($ttl)) {
            return time() + $ttl;
        }
        // DateInterval: compute against now.
        return new DateTimeImmutable()->add($ttl)->getTimestamp();
    }
}
