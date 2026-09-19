# Changelog

All notable changes to the backend (`back/`, Laravel 12) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **CORS rejection diagnostics (LBDS-261)**: `HandleDynamicCors` now stamps every rejected origin with `X-CORS-Rejected: origin-not-allowed`, plus `X-CORS-Origin`, `X-CORS-Client` and `X-CORS-Allowed`, so a missing origin is visible in devtools instead of surfacing as a generic browser CORS error. A rejected preflight (`OPTIONS`) answers `204` with those headers instead of falling through to a `405`, and it still omits `Access-Control-Allow-Origin` so the browser keeps blocking it. The allowlist header is only emitted when the request names a resolvable `client_id`, so the union of every active client's origins is never published.
- `App\Support\OriginNormalizer`: canonical `scheme://host[:port]` comparison shared by the CORS middleware and the OAuth redirect validation (returns `null` for non-http schemes such as native app callbacks).
- `App\Support\CorsOrigins`: single source of truth for the origins the CORS layer honours for a client, cache included, consumed by both the middleware and the OAuth redirect validation so the two cannot disagree.

### Changed

- **OAuth redirect validation (LBDS-261)**: `POST /api/oauth/authorize` and `POST /api/oauth/authorize/approve` now reject with `400` a `redirect_uri` whose origin is not covered by the client's effective CORS origins, before issuing an authorization code. Previously the code was issued, the browser was redirected, and the token exchange then died silently in CORS, leaving the user logged into the SSO but not into the app. The check only applies to public clients: a client holding a secret may exchange the code server-to-server, where CORS never applies.
- **`redirect_url` is required for `authorization_code` clients**: the OAuth endpoints return `400` for a client without a configured `redirect_url` (previously any redirect URI was accepted), and the admin create/update requests now require it when `grant_types` includes `authorization_code`, so a client can no longer be saved into a state that always fails at authorize.

### Fixed

- **`Allowed origins` field validation no longer swallowed by the app-config copy**: the frontend catalog entry for the origin mismatch requires both `allowed_origins` and `redirect_uri` to match, so an `allowed_origins`-only field-validation error keeps its own message.

### Security

- The CORS rejection diagnostic no longer discloses the union of every active client's allowed origins when no `client_id` can be resolved from the request.
