<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Pool;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

/**
 * Concrete PSR-6 cache item.
 *
 * Mutable by design (PSR-6 `set`/`expiresAt`/`expiresAfter` all return `static`),
 * but the parent `CachePool` is responsible for `save()` — items themselves
 * never reach into the backend.
 */
final class CacheItem implements CacheItemInterface
{
    private mixed $value = null;

    private ?DateTimeInterface $expiration = null;

    public function __construct(
        private readonly string $key,
        private bool $hit = false,
    ) {}

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }

    #[\Override]
    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    #[\Override]
    public function isHit(): bool
    {
        return $this->hit;
    }

    #[\Override]
    public function set(mixed $value): static
    {
        // @igor-ignore: PSR-6 mutable value object (created per key in CachePool, never shared across requests)
        $this->value = $value;
        // @igor-ignore: PSR-6 mutable value object (created per key in CachePool, never shared across requests)
        $this->hit = true;
        return $this;
    }

    #[\Override]
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        // @igor-ignore: PSR-6 mutable value object (created per key in CachePool, never shared across requests)
        $this->expiration = $expiration;
        return $this;
    }

    #[\Override]
    public function expiresAfter(int|DateInterval|null $time): static
    {
        if ($time === null) {
            // @igor-ignore: PSR-6 mutable value object (created per key in CachePool, never shared across requests)
            $this->expiration = null;
            return $this;
        }
        $now = new DateTimeImmutable();
        // @igor-ignore: PSR-6 mutable value object (created per key in CachePool, never shared across requests)
        $this->expiration = is_int($time) ? $now->modify('+' . $time . ' seconds') : $now->add($time);
        return $this;
    }

    /**
     * Returns the TTL in seconds, or `null` if the item has no expiration.
     *
     * Used by `CachePool` to translate PSR-6 expirations into PSR-16 TTLs
     * when delegating to the underlying simple-cache store.
     */
    public function getTtlSeconds(): ?int
    {
        if ($this->expiration === null) {
            return null;
        }
        return $this->expiration->getTimestamp() - time();
    }
}
