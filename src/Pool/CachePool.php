<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Pool;

use Psr\Cache\CacheItemInterface;
use Waffle\Commons\Cache\KeyValidator;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Cache\CacheItemPoolInterface;

/**
 * PSR-6 pool that wraps any Waffle PSR-16 `CacheInterface`.
 *
 * This bridge lets consumers needing PSR-6's richer API (deferred saves,
 * `CacheItem` value-object) layer on top of any adapter — ArrayCache,
 * FileCache, RedisCache — without changing the underlying storage choice.
 *
 * Deferred items live in-memory until `commit()` (or `__destruct`) flushes them.
 */
final class CachePool implements CacheItemPoolInterface
{
    /** @var array<string, CacheItem> */
    private array $deferred = [];

    public function __construct(
        private readonly CacheInterface $store,
    ) {}

    public function __destruct()
    {
        if ($this->deferred !== []) {
            $this->commit();
        }
    }

    #[\Override]
    public function getItem(string $key): CacheItemInterface
    {
        KeyValidator::assertValid($key);

        $sentinel = new \stdClass();
        $value = $this->store->get($key, $sentinel);
        $item = new CacheItem(key: $key, hit: $value !== $sentinel);
        if ($value !== $sentinel) {
            $item->set($value);
        }
        return $item;
    }

    #[\Override]
    public function getItems(array $keys = []): iterable
    {
        // PSR-6 signature guarantees keys are strings; KeyValidator enforces shape.
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getItem($key);
        }
        return $result;
    }

    #[\Override]
    public function hasItem(string $key): bool
    {
        KeyValidator::assertValid($key);
        return $this->store->has($key);
    }

    #[\Override]
    public function clear(): bool
    {
        $this->deferred = [];
        return $this->store->clear();
    }

    #[\Override]
    public function deleteItem(string $key): bool
    {
        KeyValidator::assertValid($key);
        unset($this->deferred[$key]);
        return $this->store->delete($key);
    }

    #[\Override]
    public function deleteItems(array $keys): bool
    {
        // PSR-6 signature guarantees keys are strings; KeyValidator enforces shape.
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->deleteItem($key) && $ok;
        }
        return $ok;
    }

    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            // PSR-6 allows pools to refuse foreign CacheItem implementations.
            return false;
        }
        return $this->store->set($item->getKey(), $item->get(), $item->getTtlSeconds());
    }

    #[\Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    #[\Override]
    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $item) {
            $ok = $this->save($item) && $ok;
        }
        $this->deferred = [];
        return $ok;
    }
}
