<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Waffle\Commons\Cache\Health\CacheHealthCheck;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Health\Enum\HealthStatus;
use WaffleTests\Commons\Cache\AbstractTestCase;

#[CoversClass(CacheHealthCheck::class)]
final class CacheHealthCheckTest extends AbstractTestCase
{
    public function testUpWhenTheBackendAnswersARead(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('has')->with(CacheHealthCheck::PROBE_KEY)->willReturn(false);

        static::assertSame(HealthStatus::Up, new CacheHealthCheck($cache)->check()->status);
    }

    public function testDownWithTheReasonWhenTheBackendIsUnreachable(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('has')->willThrowException(new RuntimeException('Connection refused'));

        $result = new CacheHealthCheck($cache)->check();

        static::assertSame(HealthStatus::Down, $result->status);
        static::assertStringContainsString('Connection refused', $result->detail);
    }

    public function testNamedCacheAndNotCriticalByDefault(): void
    {
        $cache = $this->createStub(CacheInterface::class);

        $default = new CacheHealthCheck($cache);
        static::assertSame('cache', $default->name());
        static::assertFalse($default->isCritical());

        static::assertTrue(new CacheHealthCheck($cache, 'cache.redis', critical: true)->isCritical());
    }
}
