# Changelog

All notable changes to `dowardev/sso-server` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.1] - 2026-10-25

### Fixed

- **P-256 EC coordinates with a stripped leading zero byte are accepted again**: a JWK whose `x` or `y` coordinate decoded to 31 bytes (the leading `0x00` was missing, which happens for roughly 1 in every 256 P-256 keys) was rejected with `EC coordinates for P-256 must be exactly 32 bytes`. The coordinate is now left-padded with `0x00` back to 32 bytes before use, so those keys verify instead of failing. A coordinate of any other length is still rejected.

## [0.2.0] - 2026-10-11

### Added

- **DPoP verifier accepts ECDSA P-256 / ES256 alongside RS256 (LBDS-258)**: `DpopVerifier::verify()` now allows `ES256` in `SUPPORTED_ALGS`. The signer is selected from the proof's protected header (`Lcobucci\JWT\Signer\Ecdsa\Sha256` vs `Rsa\Sha256`) inside `JwtValidator::validateDpopProofSignature()`, so the library dispatches the right algorithm per request. `JwkToPemConverter` adds a dedicated EC P-256 path: the SubjectPublicKeyInfo is built by hand per RFC 5480 §2.1.1 with the `id-ecPublicKey` OID (`1.2.840.10045.2.1`) and the `prime256v1` curve OID (`1.2.840.10045.3.1.7`). The curve allowlist is intentionally narrow: P-384 / P-521 are rejected before the bytes reach the signer. `JwkThumbprint` adds the EC branch of RFC 7638 §3.2 (`{ crv, kty, x, y }`) and is now strict about it — a partial EC JWK throws `InvalidArgumentException` instead of silently hashing empty fields.
- **Defense-in-depth checks around the dual-alg dispatch (LBDS-258 review)**: `DpopVerifier::extractAndValidateJwk()` now takes the `alg` header and rejects a proof whose `alg` does not match the JWK's `kty` (for example `alg: ES256` with an RSA JWK) with a clear error before the signature stage. The same cross-check runs in the Laravel middleware (`back/app/Http/Middleware/VerifyDpopProof.php`). `JwkToPemConverter::convertEc()` validates that the decoded `x` / `y` coordinates are exactly 32 bytes before assembling the PEM, so a malformed JWK fails fast with a clear message instead of getting a generic OpenSSL error at import time.
- **Documentation: hardening snapshot at `v0.35.0` (LBDS-249)**: new `docs/architecture/security.md` documents the security posture of the stack — authentication, transport hardening, token binding (DPoP), JWT hardening, rate limits, validation, observability — with explicit deferral decisions for LBDS-258 (DPoP ECDSA P-256), LBDS-257 (Ed25519 Passkeys), and LBDS-259 (mTLS, regulatory-only). Internal tracking at `odd/tasks/lbds-249-epic-close-decision.md`.
- **DPoP cutover is operator-driven via a config flag (LBDS-290)**: `SsoConfig::$dpopAllowedAlgs` defaults to `SsoConfig::DEFAULT_DPOP_ALLOWED_ALGS = ['ES256', 'RS256']` and reads from `config('sso.dpop_allowed_algs')` / `SSO_DPOP_ALLOWED_ALGS` at runtime via `SsoServerServiceProvider::resolveDpopAllowedAlgsConfig()`. Setting `SSO_DPOP_ALLOWED_ALGS=ES256` tightens the allowlist without a redeploy and survives `config:cache` (the env read happens on every request). The verifier rejects any `alg` outside the allowlist with a clear `Algoritmo DPoP no soportado` exception. A typo (e.g. `SSO_DPOP_ALLOWED_ALGS=HS256`) is sanitised against the supported set and logged as a `Log::warning` at boot.
- **DPoP proof-verified metric emitted per request (LBDS-290)**: `DpopVerifier` now logs a single structured line `event=dpop_proof_verified alg=<RS256|ES256> result=<accepted|rejected>` on every verification path. The line goes through the configured Laravel log channel with a triple-fallback chain (primary → `single` → `error_log`) so a broken log pipeline never silently drops the cutover counter. The captured `alg` is the real header value on every rejection path (allowlist miss, signature, thumbprint mismatch, claims, replay). The line is best-effort: a logging failure does NOT turn a valid proof into an invalid one.

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
