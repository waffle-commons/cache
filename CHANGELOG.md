# Changelog — waffle-commons/cache

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [0.1.0-beta4] — 2026-06-13

**Theme: worker-mode diagnostics.**

### Added
- Optional dev-only `?ConnectionTrackerInterface` hook in `Adapter\RedisCache` + `Factory\CacheFactory` — reports the Redis client (`ConnectionKind::Redis`) to the orphaned-connection tracer; `null` in production (zero-cost no-op) (DIAG-03).

### Changed
- Worker-safety migration to igor-php 0.7 (`#[WorkerSafe]`).

## [0.1.0-beta3] — 2026-06-07

**Theme: identity federation & stateless persistence (ecosystem wave).**

### Added
- `ArrayCache` and `CachePool` implement `ResettableInterface` and join the kernel reset chain between FrankenPHP worker requests: the in-memory store is cleared, and pending deferred writes are flushed (mirroring `__destruct`) then dropped so nothing bleeds into the next worker iteration.

### Changed
- `FileCache` / `RedisCache` TTL signatures normalised (`int|DateInterval|null`, PSR-16); `CacheItem` expiry plumbing aligned.
- Lockstep version bump; `composer.lock` refreshed with the beta-3 dependency wave.

## [0.1.0-beta2.1] — 2026-05-30

### Changed
- Lockstep re-tag of `0.1.0-beta2` (umbrella housekeeping patch) — no source changes in this component.

## [0.1.0-beta2] — 2026-05-29

### Changed
- Lockstep version bump only. No behavioural changes since `0.1.0-beta1`.
- `composer.lock` refreshed to align with the ecosystem-wide dependency wave.

## [0.1.0-beta1]

See the umbrella [CHANGELOG](../CHANGELOG.md#010-beta1) for the full Beta-1 narrative — JSON-only serialization (eliminating the insecure-deserialization RCE vector, OWASP A08), atomic temp-file writes, and PSR-6/16 surfaces landed in Beta-1.
