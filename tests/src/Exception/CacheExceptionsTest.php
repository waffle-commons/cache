<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Cache\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Cache\Exception\CacheBackendUnavailableException;
use Waffle\Commons\Cache\Exception\CacheException;
use Waffle\Commons\Cache\Exception\InvalidCacheKeyException;
use Waffle\Commons\Contracts\Cache\Exception\CacheBackendUnavailableExceptionInterface;
use Waffle\Commons\Contracts\Cache\Exception\CacheExceptionInterface;
use Waffle\Commons\Contracts\Cache\Exception\InvalidCacheKeyExceptionInterface;
use WaffleTests\Commons\Cache\AbstractTestCase;

#[CoversClass(CacheException::class)]
#[CoversClass(InvalidCacheKeyException::class)]
#[CoversClass(CacheBackendUnavailableException::class)]
final class CacheExceptionsTest extends AbstractTestCase
{
    public function testCacheExceptionImplementsContractMarker(): void
    {
        static::assertInstanceOf(CacheExceptionInterface::class, new CacheException('boom'));
    }

    public function testInvalidCacheKeyExceptionCarriesKeyAndDefaultMessage(): void
    {
        $e = new InvalidCacheKeyException(key: 'bad/key');

        static::assertInstanceOf(InvalidCacheKeyExceptionInterface::class, $e);
        static::assertSame('bad/key', $e->getKey());
        static::assertStringContainsString('bad/key', $e->getMessage());
    }

    public function testInvalidCacheKeyExceptionAcceptsCustomMessage(): void
    {
        $e = new InvalidCacheKeyException(key: 'k', message: 'custom');

        static::assertSame('custom', $e->getMessage());
        static::assertSame('k', $e->getKey());
    }

    public function testCacheBackendUnavailableCarriesBackendAndDefaultMessage(): void
    {
        $e = new CacheBackendUnavailableException(backend: 'redis');

        static::assertInstanceOf(CacheBackendUnavailableExceptionInterface::class, $e);
        static::assertSame('redis', $e->getBackend());
        static::assertStringContainsString('redis', $e->getMessage());
    }
}
