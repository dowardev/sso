# Changelog

All notable changes to `@dowardev/sso-client` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Zero-Knowledge Encryption (v2)** foundation for the advanced-encryption SSO mode:
  - `crypto/core.ts` — pure WebCrypto primitives (PBKDF2, HKDF-SHA256, AES-GCM, AES-KW, HMAC, BIP39) with named-parameter API.
  - `crypto/cryptoWorker.ts` + `crypto/workerClient.ts` — an isolated Web Worker where the DEK lives only inside it (never in the main-thread heap), defending against key exfiltration by XSS.
  - `crypto/prfService.ts` — WebAuthn PRF key service (`detectPrfSupport`, `createPrfCredential`, `getPrfOutput`, `persistPrfWrappedDek`); wraps the DEK with HKDF-derived K_PRF and persists per-credentialId. Fail-secure: when PRF is unsupported nothing is persisted (password re-auth fallback).
  - `crypto/profileCipher.ts` — profile encrypt/decrypt via the worker, tab-scoped in-memory cache with `profile_version`, and unicode-safe `#profile=` fragment encode/decode/build for delivering the decrypted profile to OAuth apps without touching server logs/query.
  - `crypto/profileFreshness.ts` — `syncProfileSilently` silent cross-session profile refresh driven by the server `profile_version` counter.
  - `crypto/ZkeClient.ts` — high-level injectable orchestration (`unlockWithPassword`, `fetchProfileFromServer`, `refreshProfileSilently`, `enrollPrf`, `restoreDekWithPrf`, `buildAppRedirectUrl`, `clear`).

### Notes

- Requires the SSO backend to emit the WebAuthn `prf` extension on credential registration (multi-credential support).

## [0.0.4] - 2026-08-15

### Added

- `SSOClient.checkSession()` exposes a real HTTP session check (no cache) so OAuth clients can revalidate their session against the server.

### Changed

- `AuthService.refreshToken()` now sends `client_id` alongside the refresh token and maps the `refresh_token` response field; `TokenRefreshManager` stores the rotated refresh token from either `refreshToken` or `refresh_token`.

## [0.0.3] - 2026-08-11

### Fixed

- The `SSOClient` constructor now validates `ssoUrl`, `ssoFrontendUrl`, `apiBaseUrl`, and `clientId`, throwing clear errors when required URLs are missing or invalid. Previously a production build with an empty `VITE_SSO_FRONTEND_URL` only crashed at login with `TypeError: URL constructor: is not a valid URL.`

## [0.0.2] - 2026-07-24

### Added

- Package `README.md` with installation, basic usage, and API reference.
- `LICENSE` (MIT).
- SPDX license headers on all source files.

### Changed

- `TokenStorage` localStorage keys renamed from `@tu-org/sso-client:*` to `@dowardev/sso-client:*`.

## [0.0.1] - 2026-07-15

### Added

- Initial package consolidated into the monorepo (moved from `@tu-org/sso-client`): `SSOClient`, `AuthService`, `TokenRefreshManager`, `DpopService`, `HttpClient`, `createAuthGuard`, `TokenStorage`, and PKCE utilities.
