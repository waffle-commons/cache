# Changelog — waffle-commons/cache

All notable changes to this component are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Released in lockstep with the Waffle Commons umbrella tag.

## [Unreleased] — targeting `0.1.0-beta3`

**Theme: identity federation & stateless persistence (ecosystem wave).**

### Added
- The cache adapters (`ArrayCache`, `FileCache`, `RedisCache`) implement `ResettableInterface` and join the kernel reset chain between FrankenPHP worker requests — the in-memory adapter clears its store on `reset()`.

### Changed
- Lockstep version bump; `composer.lock` refreshed with the beta-3 dependency wave.

## [0.1.0-beta2] — 2026-05-29

### Changed
- Lockstep version bump only. No behavioural changes since `0.1.0-beta1`.
- `composer.lock` refreshed to align with the ecosystem-wide dependency wave.

## [0.1.0-beta1]

See the umbrella [CHANGELOG](../CHANGELOG.md#010-beta1) for the full Beta-1 narrative — JSON-only serialization (eliminating the insecure-deserialization RCE vector, OWASP A08), atomic temp-file writes, and PSR-6/16 surfaces landed in Beta-1.
