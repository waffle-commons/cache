<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Exception;

use Throwable;
use Waffle\Commons\Contracts\Cache\Exception\InvalidCacheKeyExceptionInterface;

/**
 * Thrown when a cache key violates PSR-16 §1.3.
 *
 * The offending key is captured for diagnostics + structured logging.
 */
final class InvalidCacheKeyException extends CacheException implements InvalidCacheKeyExceptionInterface
{
    public function __construct(
        private(set) string $key,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = sprintf('Invalid cache key "%s".', $key);
        }
        parent::__construct($message, $code, $previous);
    }

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }
}
