<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Adapter;

use DateInterval;
use DateTimeImmutable;
use Waffle\Commons\Cache\Exception\CacheBackendUnavailableException;
use Waffle\Commons\Cache\KeyValidator;
use Waffle\Commons\Cache\Trait\StampedeAwareTrait;
use Waffle\Commons\Contracts\Cache\CacheInterface;
use Waffle\Commons\Contracts\Cache\Constant;
use Waffle\Commons\Contracts\Cache\StampedeProtectionInterface;

/**
 * Filesystem-backed PSR-16 cache (RFC-013 §3.1).
 *
 * Each key is stored as a separate file under `$baseDir/{sha256(key)[0..2]}/{sha256(key)[2..]}.cache`,
 * keeping any one directory small.
 *
 * Security: directories created with `0700`, files with `0600` — addresses the
 * `/tmp` RCE risk RFC-013 calls out. Writes are atomic via tempfile + `rename`.
 *
 * Stampede protection: implemented via {@see StampedeAwareTrait} (XFetch).
 *
 * @implements StampedeProtectionInterface<mixed>
 */
final class FileCache implements CacheInterface, StampedeProtectionInterface
{
    use StampedeAwareTrait;

    public function __construct(
        private readonly string $baseDir,
        private readonly ?int $defaultTtl = null,
    ) {
        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0o700, true) && !is_dir($this->baseDir)) {
            throw new CacheBackendUnavailableException(backend: Constant::BACKEND_FILE, message: sprintf(
                'Cache directory "%s" cannot be created.',
                $this->baseDir,
            ));
        }
        if (!is_writable($this->baseDir)) {
            throw new CacheBackendUnavailableException(backend: Constant::BACKEND_FILE, message: sprintf(
                'Cache directory "%s" is not writable.',
                $this->baseDir,
            ));
        }
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        KeyValidator::assertValid($key);

        $entry = $this->readEntry($key);
        if ($entry === null) {
            return $default;
        }
        if ($entry['expires'] !== null && $entry['expires'] <= time()) {
            $this->delete($key);
            return $default;
        }
        return $entry['value'];
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        KeyValidator::assertValid($key);

        return $this->writeEntry($key, [
            'value' => $value,
            'expires' => $this->resolveExpiry($ttl),
            'delta' => 0.0,
        ]);
    }

    #[\Override]
    public function delete(string $key): bool
    {
        KeyValidator::assertValid($key);

        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return true;
        }
        return @unlink($path);
    }

    #[\Override]
    public function clear(): bool
    {
        if (!is_dir($this->baseDir)) {
            return true;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach (KeyValidator::assertValidAll($keys) as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new \Waffle\Commons\Cache\Exception\InvalidCacheKeyException(key: '', message: sprintf(
                    'Cache key must be a string, got %s.',
                    get_debug_type($key),
                ));
            }
            $ok = $this->set($key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach (KeyValidator::assertValidAll($keys) as $key) {
            $ok = $this->delete($key) && $ok;
        }
        return $ok;
    }

    #[\Override]
    public function has(string $key): bool
    {
        KeyValidator::assertValid($key);
        $entry = $this->readEntry($key);
        if ($entry === null) {
            return false;
        }
        return $entry['expires'] === null || $entry['expires'] > time();
    }

    #[\Override]
    public function compute(string $key, callable $callback, int $ttl, float $beta = Constant::DEFAULT_BETA): mixed
    {
        KeyValidator::assertValid($key);

        $entry = $this->readEntry($key);
        $now = time();

        $cached = $entry !== null && ($entry['expires'] === null || $entry['expires'] > $now);
        if ($cached) {
            $expiresAt = $entry['expires'] ?? PHP_INT_MAX;
            $delta = $entry['delta'];
            if (!$this->xfetchShouldRecompute($expiresAt, $delta, $beta)) {
                return $entry['value'];
            }
        }

        $start = microtime(true);
        $value = $callback();
        $duration = microtime(true) - $start;

        $this->writeEntry($key, [
            'value' => $value,
            'expires' => $now + $ttl,
            'delta' => $duration,
        ]);

        return $value;
    }

    /** @return array{value: mixed, expires: int|null, delta: float}|null */
    private function readEntry(string $key): ?array
    {
        $path = $this->pathFor($key);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = @unserialize($contents, ['allowed_classes' => true]);
        if (!is_array($decoded) || !array_key_exists('value', $decoded)) {
            return null;
        }

        $expires = array_key_exists('expires', $decoded) ? $decoded['expires'] : null;
        $delta = array_key_exists('delta', $decoded) ? $decoded['delta'] : null;
        return [
            'value' => $decoded['value'],
            'expires' => is_int($expires) ? $expires : null,
            'delta' => is_numeric($delta) ? (float) $delta : 0.0,
        ];
    }

    /** @param array{value: mixed, expires: int|null, delta: float} $entry */
    private function writeEntry(string $key, array $entry): bool
    {
        $path = $this->pathFor($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $written = @file_put_contents($tmp, serialize($entry), LOCK_EX);
        if ($written === false) {
            return false;
        }
        @chmod($tmp, 0o600);
        return @rename($tmp, $path);
    }

    private function pathFor(string $key): string
    {
        $hash = hash('sha256', $key);
        return (
            $this->baseDir
            . DIRECTORY_SEPARATOR
            . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR
            . substr($hash, 2)
            . '.cache'
        );
    }

    private function resolveExpiry(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return $this->defaultTtl === null ? null : time() + $this->defaultTtl;
        }
        if (is_int($ttl)) {
            return time() + $ttl;
        }
        return new DateTimeImmutable()->add($ttl)->getTimestamp();
    }
}
