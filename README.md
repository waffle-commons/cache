[![PHP Version Require](http://poser.pugx.org/waffle-commons/cache/require/php)](https://packagist.org/packages/waffle-commons/cache)
[![PHP CI](https://github.com/waffle-commons/cache/actions/workflows/main.yml/badge.svg)](https://github.com/waffle-commons/cache/actions/workflows/main.yml)
[![codecov](https://codecov.io/gh/waffle-commons/cache/graph/badge.svg?token=d74ac62a-7872-4035-8b8b-bcc3af1991e0)](https://codecov.io/gh/waffle-commons/cache)
[![Latest Stable Version](http://poser.pugx.org/waffle-commons/cache/v)](https://packagist.org/packages/waffle-commons/cache)
[![Latest Unstable Version](http://poser.pugx.org/waffle-commons/cache/v/unstable)](https://packagist.org/packages/waffle-commons/cache)
[![Total Downloads](https://img.shields.io/packagist/dt/waffle-commons/cache.svg)](https://packagist.org/packages/waffle-commons/cache)
[![Packagist License](https://img.shields.io/packagist/l/waffle-commons/cache)](https://github.com/waffle-commons/cache/blob/main/LICENSE.md)

Waffle Cache Component
======================

> **Release:** `v0.1.0-beta2` &nbsp;|&nbsp; [`CHANGELOG.md`](./CHANGELOG.md)
> **PSR Compliance:** PSR-6 (`Psr\Cache`) + PSR-16 (`Psr\SimpleCache`)

PSR-6 and PSR-16 compliant cache implementation for the Waffle Framework. Designed for FrankenPHP resident worker mode — every adapter is stateless across requests, fail-secure, and zero-baseline under Mago static analysis.

## 📦 Installation

```bash
composer require waffle-commons/cache
```

## 🧱 Adapters

| Adapter | Use case | Worker scope |
| :--- | :--- | :--- |
| `ArrayCache` | In-memory, ultra-fast. Lifetime = worker request cycle. | Single worker |
| `FileCache` | Persistent filesystem cache with strict permission handling (no `/tmp` foot-guns). | Per host |
| `RedisCache` | Distributed cache across multiple FrankenPHP workers via the Predis client. | Cluster-wide |

All adapters implement both `Psr\SimpleCache\CacheInterface` (PSR-16) and route through the same `KeyValidator`. `CachePool` provides the PSR-6 `CacheItemPoolInterface` view on top of any adapter.

## 🛡️ Hardening features

- **JSON-only payloads (Beta 1):** `FileCache` and `RedisCache` serialize entries with `json_encode` / `json_decode` — **never** PHP `unserialize`. This eliminates the PHP Object Injection → RCE vector (OWASP A08) that native deserialization exposes in a long-lived worker process. JSON parse failures fail-secure (treated as a cache miss). Trade-off: only JSON-encodable values round-trip — objects come back as `stdClass`/arrays.
- **Stampede protection (`StampedeAwareTrait`):** probabilistic early expiration to prevent thundering-herd misses under high load.
- **Strict key validation (`KeyValidator`):** enforces the PSR-16 key character set; rejects invalid keys with `InvalidCacheKeyException`.
- **Stateless adapters:** no per-process mutable state — safe for FrankenPHP worker reuse.
- **Fail-secure exceptions:** `CacheException`, `CacheBackendUnavailableException`, `InvalidCacheKeyException` — all rooted in the `waffle-commons/contracts` exception hierarchy.

## 🐘 PHP 8.5 features used

- **Typed constants** for adapter defaults.
- **Asymmetric visibility** (`public private(set)`) on adapter configuration.
- **Property hooks** for validating constructor inputs at the source.
- **`readonly` classes** for value objects (`CacheItem`).

## 🚀 Quick start

```php
use Waffle\Commons\Cache\Adapter\ArrayCache;
use Waffle\Commons\Cache\Pool\CachePool;

$psr16 = new ArrayCache();
$psr16->set('user:42', ['name' => 'Ada'], ttl: 60);

$psr6 = new CachePool($psr16);
$item = $psr6->getItem('user:42');
```

Factory-based wiring (preferred in framework code) is provided by `CacheFactory`.

## 🧭 Architectural boundary (`mago guard`)

An active dependency **perimeter** is enforced on every CI run by `vendor/bin/mago guard` (bundled into `composer mago`; zero baselines). The rules live in [`mago.toml`](./mago.toml) under `[guard.perimeter]` — a forbidden `use` statement fails the build, not a reviewer.

Production code under `Waffle\Commons\Cache` may depend **only** on:

- `Waffle\Commons\Cache\**` — itself
- `Waffle\Commons\Contracts\**` — the shared contracts package, the **only** Waffle dependency permitted
- `Predis\**` — the Predis client behind the `RedisCache` adapter
- `Psr\**` — PSR interfaces (PSR-6 / PSR-16)
- `@global` + `Psl\**` — PHP core and the PHP Standard Library

Test code under `WaffleTests\Commons\Cache` is unrestricted (`@all`). Structural rules are guarded too: interfaces must be named `*Interface`, `Exception\**` classes must end in `*Exception`, and any `Enum\**` namespace may hold only `enum` declarations.

Contract-first, component-agnostic by construction: components compose through `waffle-commons/contracts`, never directly through one another.

## 🧪 Testing

```bash
docker exec -w /waffle-commons/cache waffle-dev composer tests
```

PHPUnit 11+ suite targets `>= 95%` line coverage.

## 🤝 Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md). All contributions must respect the Mago Purge Protocol (zero baselines) and the FrankenPHP statelessness contract.

## 📄 License

MIT — see [LICENSE.md](./LICENSE.md).
