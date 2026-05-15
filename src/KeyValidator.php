<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache;

use Waffle\Commons\Cache\Exception\InvalidCacheKeyException;
use Waffle\Commons\Contracts\Cache\Constant;

/**
 * Validates cache keys against PSR-16 §1.3.
 *
 * Rules:
 *  - Keys are non-empty strings.
 *  - Length at most {@see Constant::MAX_KEY_LENGTH} characters.
 *  - No PSR-16 reserved characters: `{}()/\@:`.
 *
 * Static-only — adapters compose this validator without inheritance.
 */
final class KeyValidator
{
    /** @throws InvalidCacheKeyException */
    public static function assertValid(string $key): void
    {
        if ($key === '') {
            throw new InvalidCacheKeyException(key: $key, message: 'Cache key must not be empty.');
        }

        if (strlen($key) > Constant::MAX_KEY_LENGTH) {
            throw new InvalidCacheKeyException(
                key: $key,
                message: sprintf(
                    'Cache key "%s" exceeds %d characters.',
                    $key,
                    Constant::MAX_KEY_LENGTH,
                ),
            );
        }

        if (strpbrk($key, Constant::RESERVED_CHARACTERS) !== false) {
            throw new InvalidCacheKeyException(
                key: $key,
                message: sprintf(
                    'Cache key "%s" contains reserved characters (any of "%s").',
                    $key,
                    Constant::RESERVED_CHARACTERS,
                ),
            );
        }
    }

    /**
     * Asserts every key in an iterable. Used by `getMultiple`/`setMultiple`/`deleteMultiple`.
     *
     * @param iterable<mixed> $keys
     * @return list<string> the validated keys as a list (allows callers to rely on indexable access).
     * @throws InvalidCacheKeyException when a key is non-string or invalid.
     */
    public static function assertValidAll(iterable $keys): array
    {
        $validated = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidCacheKeyException(
                    key: '',
                    message: sprintf('Cache key must be a string, got %s.', get_debug_type($key)),
                );
            }
            self::assertValid($key);
            $validated[] = $key;
        }
        return $validated;
    }
}
