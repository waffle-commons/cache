<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Exception;

use Throwable;
use Waffle\Commons\Contracts\Cache\Exception\CacheBackendUnavailableExceptionInterface;

/**
 * Thrown when the cache backend is unreachable (Redis down, file dir unwritable).
 *
 * Per RFC-013 §4, callers may catch this specifically to fall back to a
 * degraded mode while logging the outage.
 */
final class CacheBackendUnavailableException extends CacheException implements
    CacheBackendUnavailableExceptionInterface
{
    public function __construct(
        private(set) string $backend,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = sprintf('Cache backend "%s" is unavailable.', $backend);
        }
        parent::__construct($message, $code, $previous);
    }

    #[\Override]
    public function getBackend(): string
    {
        return $this->backend;
    }
}
