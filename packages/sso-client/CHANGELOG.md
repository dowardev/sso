# Changelog

All notable changes to `@dowardev/sso-client` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-09-27

### Added

- `client/profileFragmentCleanup.test.ts` (new) — covers the fragment erase, the match guard's negative cases (`origin`, `pathname`, `search`, `hash`), idempotence, preservation of the host router's `history.state`, a throwing `replaceState`, and a malformed callback URL. Runs in the `jsdom` environment via `// @vitest-environment jsdom`; the rest of the suite stays on `node`, which the crypto tests need for `crypto.subtle`.
- `client/SSOClient.test.ts` — covers that `handleCallback` erases the fragment from the live location and passes the host router's existing `history.state` through to `replaceState`, with the OAuth query string preserved.

### Changed

- `client/SSOClient.ts` — a DPoP proof is requested whenever a usable key pair exists, instead of only when an access token is cached. The refresh path needs it: a refresh token issued with a DPoP binding can only be rotated when the request proves possession of that key. `ath` rides along only when an access token is present, and both request paths (`internalPost`, `SSOClientInternal.request`) now warn and continue without a proof when building one throws, so a missing key pair cannot turn a legal request into a thrown error.
- `auth/TokenRefreshManager.ts` — the 401 retry and the auto-refresh paths build the DPoP proof the same way, so a refresh works with no cached access token.

### Fixed

- `client/profileFragmentCleanup.ts` (new), `client/SSOClient.ts` — the decrypted ZKE profile handed to an OAuth app as a `#profile=` URL fragment is now erased from the document that received it. `handleCallback` copies the fragment into memory and immediately rewrites the live location with `history.replaceState`, dropping only the hash so the OAuth `code` and `state` query params survive. Before this, the fragment stayed visible in `window.location` and persisted in the session history entry created by the redirect.
  - The erase is guarded: it runs only when the callback URL passed to `handleCallback` matches the live document on `origin`, `pathname`, `search` and `hash`. An app that passes a stored URL, an iframe URL or a popup URL never has an unrelated location rewritten, and an app that does not pass the live document gets no erase at all.
  - The existing `history.state` is preserved instead of replaced by a fresh object, so host routers that keep back/forward position, scroll bookkeeping and their replacement flag there (Vue Router 4, `@ionic/vue-router`) are not corrupted.
  - A `replaceState` that throws (`SecurityError` in a sandboxed or cross-origin-embedded document) is swallowed and reported as `false`, honoring the helper's never-throws contract, so a failed cleanup cannot abort the token exchange that follows.
  - The fragment reader is unchanged: it still only matches `#profile=` when it is the first complete segment of the hash.

## [0.2.3] - 2026-09-10

### Fixed

- `dpop/DpopService.ts` — the stored key pair is deleted by removing the record inside a short-lived readwrite transaction, instead of `deleteDatabase()`. The database-level delete fires `onblocked` and never completes while another connection holds the database open, so the key pair survived into the next `init()` and was silently reused; a record delete cannot be blocked. Every IndexedDB path now also settles on `tx.onabort`, `request.onblocked` and `db.onversionchange`, so a blocked upgrade or an aborted transaction can no longer hang `init()` or `logout()`.
- `client/SSOClient.ts` — `logout()` sends the refresh token in the request body, so the server revokes it even when the bearer cannot be attributed to a user (an expired access token); when the refresh above rotated it, the rotated secret is the one revoked. It also persists the refreshed credentials directly instead of going through `handleAuthResponse`, which spent a `GET user-auth` round trip whose result was discarded a moment later by the logout.
- `client/SSOClient.ts` — a DPoP proof is only requested when a key pair actually exists. Asking for one before `initialize()` threw, which failed a legal request and warned on every logout made after a fresh boot.
- `storage/TokenStorage.ts` — PKCE entries carry a timestamp and are swept once older than 10 minutes. `sessionStorage` used to clean itself up when the tab closed; `localStorage` does not, so every abandoned login left a verifier and its redirect URI behind forever. Entries written without a timestamp (by an older version) are preserved, so a login in flight across an upgrade still completes.

### Added

- `dpop/DpopService.test.ts` (new) — covers record removal on `clearKeys()`, no hang while another connection holds the database open, and a fresh key pair after `clearKeys()`.
- `client/SSOClient.test.ts` — covers the logout body carrying the rotated refresh token, no `user-auth` round trip during logout, logout without an initialized DPoP key pair, and the PKCE sweep (expired entries removed, timestamp-less entries kept).

## [0.2.2] - 2026-09-10

### Fixed

- `client/SSOClient.ts` — `logout()` refreshes the access token before `POST /logout` when a refresh token is available, and warns on failure instead of swallowing it. OAuth access tokens last 1h and never auto-refresh, so logging out late sent an expired bearer: the server answered 401 and revoked nothing, so the SSO session survived while local state was cleared (seen as a silent re-login on the next attempt).
- `dpop/DpopService.ts` — IndexedDB connections are always closed and `deleteDatabase` resolves on `onblocked`. Leaving handles open made `clearKeys()` (called by `logout()`) block forever once the DPoP keys had been initialized.
- `storage/TokenStorage.ts` — the PKCE `code_verifier` and the per-`state` `redirect_uri` live in `localStorage` instead of `sessionStorage`. On native the OS recreates the activity while the external browser is in front, so a recreated WebView lost the verifier and the exchange died with "Missing PKCE verifier". The verifier is single-use, ~10 min TTL, and useless without the authorization code; key names are unchanged.

### Added

- `client/SSOClient.test.ts` — tests for refresh-before-logout ordering and bearer, local cleanup when the refresh fails, and PKCE persistence across a recreated WebView.

## [0.2.1]

### Added

- `client/SSOClient.ts` — `logout({ redirectUri?, openUrl? })` opens the SSO logout page externally on native (default keeps `window.location.href`).
- Build uses a relative `base` so the emitted crypto-worker URL resolves against the package dist (fixes PWA consumer builds).

## [0.2.0] - 2026-09-08

### Added

- **Native deep-link login support (LBDS-235)**:
  - `client/SSOClient.ts` — `loginWithRedirect` accepts `openUrl` (open the authorize URL externally, e.g. Capacitor Browser); `handleCallback(url, { redirectUri })` accepts an explicit exchange redirect URI; the authorize-time redirect URI is persisted per-`state` and reused at exchange (explicit option > stored > web default); clear errors for malformed callbacks and missing PKCE state; PKCE keys are cleaned up when `openUrl` rejects.
  - `storage/TokenStorage.ts` — per-state `get/set/removePkceRedirectUri`.
  - `utils/randomUuid.ts` (new) — `randomUUID()` with a `getRandomValues` fallback for insecure contexts.
  - `router/createAuthGuard.ts` — shared exported `OpenUrlHandler` type.

- **Zero-Knowledge Encryption (v2)** foundation for the advanced-encryption SSO mode:
  - `crypto/core.ts` — pure WebCrypto primitives (PBKDF2, HKDF-SHA256, AES-GCM, AES-KW, HMAC, BIP39) with named-parameter API.
  - `crypto/cryptoWorker.ts` + `crypto/workerClient.ts` — an isolated Web Worker where the DEK lives only inside it (never in the main-thread heap), defending against key exfiltration by XSS.
  - `crypto/prfService.ts` — WebAuthn PRF key service (`detectPrfSupport`, `createPrfCredential`, `getPrfOutput`, `persistPrfWrappedDek`); wraps the DEK with HKDF-derived K_PRF and persists per-credentialId. Fail-secure: when PRF is unsupported nothing is persisted (password re-auth fallback).
  - `crypto/profileCipher.ts` — profile encrypt/decrypt via the worker, tab-scoped in-memory cache with `profile_version`, and unicode-safe `#profile=` fragment encode/decode/build for delivering the decrypted profile to OAuth apps without touching server logs/query.
  - `crypto/profileFreshness.ts` — `syncProfileSilently` silent cross-session profile refresh driven by the server `profile_version` counter.
  - `crypto/ZkeClient.ts` — high-level injectable orchestration (`unlockWithPassword`, `fetchProfileFromServer`, `refreshProfileSilently`, `enrollPrf`, `restoreDekWithPrf`, `buildAppRedirectUrl`, `clear`).

- **Uniform advanced-user identity with zero ZKE code in the app (LBDS-205)**:
  - `crypto/profileSignature.ts` — `verifyProfileFragment({fragment, ssoUrl, expectedUserId})` verifies the backend JWS against the SSO JWKS and checks `sub == expected user` + `exp`; rejects forged/unsigned/expired/mismatched tokens.
  - `crypto/profileVault.ts` — AES-GCM non-extractable at-rest vault for the verified profile (IndexedDB).
  - `client/SSOClient.ts` — captures, verifies and merges the profile fragment in `handleCallback` (bound to the authenticated user id), persists at rest, and clears on logout; `getUser()` now returns a uniform identity for advanced users.
  - `client/SSOClient.test.ts` — 4 tests (signed-merge + reload recovery, forged-reject, subject-mismatch-reject, vault-clear).
  - devDependency: `fake-indexeddb` for vault tests.

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
