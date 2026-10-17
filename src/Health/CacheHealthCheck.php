<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Health;

use Throwable;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Health\HealthCheckInterface;
use Waffle\Commons\Contracts\Health\HealthResult;

/**
 * Probes a cache backend for `/readyz` ([OPS-01], RFC-023 §3.3): one `has()` on a fixed key — a single read
 * round trip, never a write. A Redis-backed cache that cannot reach Redis throws, and the probe reports it
 * down. Not critical by default: an application that cannot serve without its cache says so.
 */
final readonly class CacheHealthCheck implements HealthCheckInterface
{
    public const string PROBE_KEY = 'waffle.health.probe';

    public function __construct(
        private CacheInterface $cache,
        private string $name = 'cache',
        private bool $critical = false,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function isCritical(): bool
    {
        return $this->critical;
    }

    #[\Override]
    public function check(): HealthResult
    {
        try {
            $this->cache->has(self::PROBE_KEY);
        } catch (Throwable $e) {
            // The contract forbids throwing: an unreachable backend is reported as down, with the reason.
            return HealthResult::down('The cache backend is unreachable: ' . $e->getMessage());
        }

        return HealthResult::up();
    }
}
