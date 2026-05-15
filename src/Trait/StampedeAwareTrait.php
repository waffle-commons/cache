<?php

declare(strict_types=1);

namespace Waffle\Commons\Cache\Trait;

/**
 * XFetch helper for adapters implementing `StampedeProtectionInterface` (RFC-013 §4).
 *
 * Adapters that include this trait expose an `xfetchShouldRecompute()` helper
 * that decides — given the wall-clock TTL and how long the value historically
 * took to compute — whether to recompute *early* to avoid the thundering-herd
 * stampede when many workers see an expired key at once.
 *
 * The canonical XFetch formula (Vattani et al., 2015):
 *
 *     now - (delta * beta * ln(rand_unit())) >= expiry
 *
 * `delta` is the historical recompute cost (recorded alongside the value);
 * `beta` is a tunable parameter (1.0 = canonical); `rand_unit()` is a uniform
 * `(0, 1)` random.
 *
 * This trait does NOT decide what to do — the adapter calls `xfetchShouldRecompute`
 * and either returns the cached value or invokes the callback.
 */
trait StampedeAwareTrait
{
    /**
     * Returns true when the cached entry is "about to expire" by XFetch's
     * probabilistic test and the worker should re-execute the callback now.
     *
     * @param int   $expiresAt Absolute Unix timestamp when this entry would expire.
     * @param float $delta     Recorded compute time (seconds, may be fractional).
     * @param float $beta      XFetch tuning factor (>= 0). `0.0` disables early recompute.
     */
    private function xfetchShouldRecompute(int $expiresAt, float $delta, float $beta): bool
    {
        if ($beta <= 0.0) {
            return false;
        }

        // mt_rand() / mt_getrandmax() gives a uniform value in [0, 1]; we need
        // strictly (0, 1) for ln() to be defined, so clamp away from 0.
        $r = mt_rand() / mt_getrandmax();
        if ($r <= 0.0) {
            $r = PHP_FLOAT_EPSILON;
        }

        $jitter = $delta * $beta * -log($r);
        return (microtime(true) + $jitter) >= $expiresAt;
    }
}
