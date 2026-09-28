# Changelog

All notable changes to `dowardev/sso-server` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Documentation: hardening snapshot at `v0.35.0` (LBDS-249)**: new `docs/architecture/security.md` documents the security posture of the stack — authentication, transport hardening, token binding (DPoP), JWT hardening, rate limits, validation, observability — with explicit deferral decisions for LBDS-258 (DPoP ECDSA P-256), LBDS-257 (Ed25519 Passkeys), and LBDS-259 (mTLS, regulatory-only). Internal tracking at `odd/tasks/lbds-249-epic-close-decision.md`.

## [0.1.2] - 2026-09-27

### Security

- **DPoP replay protection is now atomic across instances (LBDS-255)**: `ReplayProtection::checkAndStore()` used a non-atomic `Cache::has` + `Cache::put` pair against a per-instance Cache. Any embedding application running more than one pod (k8s, load balancer) could accept the same DPoP `jti` twice — once per pod. The implementation now uses a single `Cache::add('dpop_jti:'.$jti, true, $ttl)` call, which dispatches to the atomic primitive of the configured cache driver: `SET NX EX` on Redis, `INSERT IGNORE` on the `database` cache store, Memcached `add`, or in-process for `array`. The `file` driver is not atomic across processes; `SsoServerServiceProvider::boot()` now emits a `Log::warning` when `cache.default` is `file` or `array` so multi-instance deployments never silently lose replay protection. Tests cover the atomicity contract via two `Repository` instances backed by the same `ArrayStore`, plus driver parity for `array` and `database` (the two drivers available without external services in the test environment). Operator config knob is unchanged: `CACHE_STORE=redis|database|memcached` is safe across pods; `CACHE_STORE=file` is not.

## [0.1.1] - 2026-07-24

### Added

- Full AGPL-3.0 license text (`LICENSE`).
- Package `README.md` with installation, configuration, and usage.

### Changed

- Unified commercial contact email across package metadata.

## [0.1.0] - 2026-07-23

### Changed

- Renamed `TuOrg` namespace to `Dowardev` across all classes, config, and provider.
- License changed from MIT to AGPL-3.0-only.

### Added

- Initial public release of the package (consolidated into the monorepo): DPoP verification (`DpopVerifier`, `JwkThumbprint`, `ReplayProtection`), JWT validation (`JwtValidator`, `JwksFetcher`, `JwkToPemConverter`), auth middleware (`SsoAuthMiddleware`, `SsoRoleMiddleware`), `SsoServerClient`, `SsoAuth` facade, config, and exceptions.
