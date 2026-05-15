<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Adapter;

use DateInterval;
use DateTimeImmutable;
use Predis\Client as PredisClient;
use Predis\PredisException;
use Throwable;
use Waffle\Commons\Cache\Exception\CacheBackendUnavailableException;
use Waffle\Commons\Cache\KeyValidator;
use Waffle\Commons\Cache\Trait\StampedeAwareTrait;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Cache\Constant;
use Waffle\Commons\Contracts\Cache\StampedeProtectionInterface;

/**
 * Redis-backed PSR-16 cache (RFC-013 §3.1).
 *
 * Production-grade adapter for distributed deployments (Sentinel target). Uses
 * Predis (pure-PHP, no ext-redis dependency). Values are serialized via PHP
 * `serialize()` to preserve types across language-level boundaries.
 *
 * Key prefix lets multiple Waffle apps share the same Redis instance without
 * collisions: e.g. `app:cache:user:42`.
 *
 * Stampede protection: implemented via {@see StampedeAwareTrait} (XFetch),
 * leveraging Redis-side TTL for the expiry timestamp.
 *
 * @implements StampedeProtectionInterface<mixed>
 */
final class RedisCache implements CacheInterface, StampedeProtectionInterface
{
    use StampedeAwareTrait;

    public function __construct(
        private readonly PredisClient $client,
        private readonly string $prefix = 'waffle:cache:',
        private readonly ?int $defaultTtl = null,
    ) {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        KeyValidator::assertValid($key);

        try {
            $raw = $this->client->get($this->prefix . $key);
        } catch (PredisException $e) {
            throw $this->backendUnavailable($e);
        }

        if (!is_string($raw)) {
            return $default;
        }

        $entry = $this->unserialize($raw);
        return $entry === null ? $default : $entry['value'];
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        KeyValidator::assertValid($key);

        return $this->writeEntry($key, $value, $this->resolveTtlSeconds($ttl), 0.0);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        KeyValidator::assertValid($key);

        try {
            $this->client->del([$this->prefix . $key]);
        } catch (PredisException $e) {
            throw $this->backendUnavailable($e);
        }
        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        try {
            $cursor = 0;
            do {
                /** @var array{0: string|int, 1: array<int, string>} $batch */
                $batch = $this->client->scan($cursor, ['MATCH' => $this->prefix . '*', 'COUNT' => 500]);
                $cursor = (int) $batch[0];
                $keys = $batch[1];
                if ($keys !== []) {
                    $this->client->del($keys);
                }
            } while ($cursor !== 0);
        } catch (PredisException $e) {
            throw $this->backendUnavailable($e);
        }
        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $validated = KeyValidator::assertValidAll($keys);
        if ($validated === []) {
            return [];
        }

        try {
            $prefixed = array_map(fn(string $k): string => $this->prefix . $k, $validated);
            $values = $this->client->mget($prefixed);
        } catch (PredisException $e) {
            throw $this->backendUnavailable($e);
        }

        $result = [];
        foreach ($validated as $i => $key) {
            $raw = $values[$i] ?? null;
            $entry = is_string($raw) ? $this->unserialize($raw) : null;
            $result[$key] = $entry === null ? $default : $entry['value'];
        }
        return $result;
    }

    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ttlSeconds = $this->resolveTtlSeconds($ttl);
        $ok = true;
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new \Waffle\Commons\Cache\Exception\InvalidCacheKeyException(
                    key: '',
                    message: sprintf('Cache key must be a string, got %s.', get_debug_type($key)),
                );
            }
            $ok = $this->writeEntry($key, $value, $ttlSeconds, 0.0) && $ok;
        }
        return $ok;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $validated = KeyValidator::assertValidAll($keys);
        if ($validated === []) {
            return true;
        }
        try {
            $prefixed = array_map(fn(string $k): string => $this->prefix . $k, $validated);
            $this->client->del($prefixed);
        } catch (PredisException $e) {
            throw $this->backendUnavailable($e);
        }
        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        KeyValidator::assertValid($key);

        try {
            return $this->client->exists($this->prefix . $key) > 0;
        } catch (PredisException $e) {
            throw $this->backendUnavailable($e);
        }
    }

    #[\Override]
    public function compute(string $key, callable $callback, int $ttl, float $beta = Constant::DEFAULT_BETA): mixed
    {
        KeyValidator::assertValid($key);

        try {
            $raw = $this->client->get($this->prefix . $key);
            $remainingTtl = is_string($raw) ? $this->client->ttl($this->prefix . $key) : -2;
        } catch (PredisException $e) {
            throw $this->backendUnavailable($e);
        }

        if (is_string($raw) && $remainingTtl > 0) {
            $entry = $this->unserialize($raw);
            if ($entry !== null) {
                $expiresAt = time() + $remainingTtl;
                if (!$this->xfetchShouldRecompute($expiresAt, $entry['delta'], $beta)) {
                    return $entry['value'];
                }
            }
        }

        $start = microtime(true);
        $value = $callback();
        $duration = microtime(true) - $start;

        $this->writeEntry($key, $value, $ttl, $duration);
        return $value;
    }

    /** @return array{value: mixed, delta: float}|null */
    private function unserialize(string $raw): ?array
    {
        $decoded = @unserialize($raw, ['allowed_classes' => true]);
        if (!is_array($decoded) || !array_key_exists('value', $decoded)) {
            return null;
        }
        $delta = array_key_exists('delta', $decoded) ? $decoded['delta'] : null;
        return [
            'value' => $decoded['value'],
            'delta' => is_numeric($delta) ? (float) $delta : 0.0,
        ];
    }

    private function writeEntry(string $key, mixed $value, ?int $ttlSeconds, float $delta): bool
    {
        $payload = serialize(['value' => $value, 'delta' => $delta]);
        try {
            if ($ttlSeconds !== null && $ttlSeconds > 0) {
                $this->client->setex($this->prefix . $key, $ttlSeconds, $payload);
            } else {
                $this->client->set($this->prefix . $key, $payload);
            }
        } catch (PredisException $e) {
            throw $this->backendUnavailable($e);
        }
        return true;
    }

    private function resolveTtlSeconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }
        if (is_int($ttl)) {
            return $ttl;
        }
        return new DateTimeImmutable()->add($ttl)->getTimestamp() - time();
    }

    private function backendUnavailable(Throwable $previous): CacheBackendUnavailableException
    {
        return new CacheBackendUnavailableException(
            backend: Constant::BACKEND_REDIS,
            message: 'Redis backend unavailable: ' . $previous->getMessage(),
            previous: $previous,
        );
    }
}
