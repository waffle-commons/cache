<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Exception;

use RuntimeException;
use Waffle\Commons\Contracts\Cache\Exception\CacheExceptionInterface;

/**
 * Concrete base for all Waffle cache failures.
 *
 * Per RFC-013, cache errors are RECOVERABLE (callers can fall back to recompute);
 * this class extends `RuntimeException` rather than `LogicException` to make that
 * recoverable nature explicit.
 */
class CacheException extends RuntimeException implements CacheExceptionInterface {}
