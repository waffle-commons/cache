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
        $this->value = $value;
        $this->hit = true;
        return $this;
    }

    #[\Override]
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiration = $expiration;
        return $this;
    }

    #[\Override]
    public function expiresAfter(null|int|DateInterval $time): static
    {
        if ($time === null) {
            $this->expiration = null;
            return $this;
        }
        $now = new DateTimeImmutable();
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
