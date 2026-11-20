# Security Architecture

<!-- markdownlint-disable MD013 -->

Snapshot of the security posture of the **SSO** stack
(`back/` Laravel 12, `front/` Ionic Vue 8, the Composer library
`packages/sso-server/`, the npm library `packages/sso-client/`). Maintained
as part of epic **LBDS-249 [EPIC] SSO Hardening: Crypto, Token Binding &
Passkeys**.

**Release timeline covered by this document:**

* **2026-09-27 — monorepo `v0.35.0` (tag `ed3ff5fd`).** Code state of `back/`,
  `front/`, and the package sources. All P0 and P1 hardening sub-tickets of
  LBDS-249 are reflected in this commit's tree.
* **2026-09-27 — `dowardev/sso-server` v0.1.2** (Packagist). Published on
  the **same day, after** the monorepo `v0.35.0` tag, via commit `1f9100c`
  (`release(sso-server): v0.1.2`) on `main` — a bump that the mirror's
  subtree split captured into the registry. The mirror tag lives at
  `gitlab.com:dowardev/sso-server` as `v0.1.2` (the SHA shown by `git
  ls-remote` is `ff861b191e3aacbec7fa84d682485127a5c9433b`). The library
  code is bit-identical to the monorepo `v0.35.0` tree, so downstream
  apps embedding the library receive the multi-instance DPoP replay fix
  (`LBDS-255`) and the `cnf.jkt` access-token binding in one go.
* **2026-09-27 — `@dowardev/sso-client` v0.3.0** (npm). Published on the
  **same day, after** the monorepo `v0.35.0` tag, via commit `0c2a0af`
  (`release(sso-client): v0.3.0`) on `main`. Mirrors the DPoP-aware refresh
  path required by `LBDS-253` together with the fragment-cleanup fix from
  `LBDS-250`.

> **Scope.** This document tracks **what is implemented, what is
> intentionally deferred, and the rationale**. The cryptographic primitives
> (PBKDF2, HKDF, AES-GCM/AES-KW, WebAuthn ceremonies, ZKE key hierarchy,
> JWK thumbprints per RFC 7638, DPoP proofs per RFC 9449, JWT per RFC 7519,
> OAuth2 per RFC 6749 / RFC 7636) are documented in
> [`AUTHENTICATION.md`](https://gitlab.com/dowardev/sso/-/blob/main/docs/AUTHENTICATION.md)
> (Spanish: [`AUTENTICACION.md`](https://gitlab.com/dowardev/sso/-/blob/main/docs/AUTENTICACION.md)).
> That document is the canonical reference for **flows**; this one is the
> canonical reference for **security status**.
>
> **Out of this document's scope (lives elsewhere or does not exist yet):**
> threat model, trust-boundary diagram, key-rotation policy, secret-handling
> runbook, incident-response procedure, audit-log retention, supply-chain
> scanning cadence, vulnerability-management SLA. If you need one of those,
> open a follow-up ticket — none of them are tracked here on purpose.

---

## 1. Authentication

| Capability | Status | Where |
| --- | --- | --- |
| Password-based login (basic / advanced) | Shipped | `back/app/Http/Controllers/AuthController.php` |
| Passkeys (WebAuthn, ES256 / RS256) | Shipped | `back/app/Services/PasskeyService.php`, `front/src/services/passkeyService.ts` |
| OAuth2 Authorization Code + PKCE (S256) | Shipped | `back/app/Services/OAuth2Service.php` |
| Zero-Knowledge Encryption (ZKE) for advanced users | Shipped | `packages/sso-client/src/crypto/` |
| TOTP (RFC 6238) as 2FA fallback | Shipped | LBDS-8. `back/app/Services/TotpService.php`, `back/app/Http/Controllers/AuthController.php` (enrollment + login challenge), `front/src/components/user/TotpSettings.vue`. Secret encrypted at rest via `Crypt::encryptString`; backup codes Argon2id-hashed, single-use, regenerable. Opt-in only — passkey users are never forced through TOTP. |
| Passkey cross-device (hybrid transport / QR) | Partially verified | LBDS-225 — needs real-device testing |
| Ed25519 (alg -8) in Passkeys | Not implemented | LBDS-257 — kept open in Backlog |
| Inbound IdP federation (OIDC/SAML) | Not implemented | Blocked until a paying client requires it. Readiness audit + ADR tracked under **LBDS-226** (parent: LBDS-7). |

## 2. Transport hardening

| Capability | Status | Notes |
| --- | --- | --- |
| HTTPS + HSTS | Required in production | `DEPLOY.md` |
| Cookie `Secure` flag | Enforced | `config/session.php` (`LBDS-252`) |
| Cookie `SameSite=Strict` | Enforced | `config/session.php` (`LBDS-252`) |
| `Cookie::forget()` parity with emission | Backlog (smell from LBDS-252) | Tracked in **LBDS-278** (see §9). The four emission sites use the `refreshTokenCookie()` helper; the `logout()` clear still hardcodes `secure=true`. Smell surfaced by the LBDS-252 pre-merge review, not yet fixed. |
| mTLS / Token Binding TLS | Not implemented | LBDS-259 — explicit YAGNI without regulatory driver |
| CORS dynamic per-client | Shipped | `HandleDynamicCors` (`LBDS-261`) |

## 3. Token binding (DPoP)

`Proof-of-possession` for OAuth2 tokens, per **RFC 9449**.

| Capability | Status | Notes |
| --- | --- | --- |
| DPoP proof on `/api/oauth/token` (authorization_code) | Shipped | `VerifyDpopProof::verify()` |
| DPoP proof on `/api/oauth/token` (refresh_token) | Shipped | `LBDS-253` |
| DPoP binding preserved across `/api/refresh-token` rotation | Shipped (binding, not proof enforcement) | Rotation inherits the `dpop_jkt` from the previous row. By design, `VerifyDpopProof` does **not** validate a proof on this endpoint: the LBDS-253 design review (internal notes, not tracked in this repository) documents that the cookie-based web flow does not send a DPoP header; binding-inheritance is what makes the proof mismatch detectable (see `DpopRefreshTokenTest`). |
| Refresh-token binding persisted (`user_refresh_tokens.dpop_jkt`) | Shipped | Migration `database/migrations/2026_09_19_000000_add_dpop_jkt_to_user_refresh_tokens_table.php` (nullable indexed `char(64)` column). A bound refresh token can only be rotated with a matching proof. |
| Access token carries `cnf.jkt` claim | Shipped | `JwtService::generateTokenForJkt()` |
| Replay protection (`dpop_jti`) | Shipped, atomic across instances | `LBDS-255` |
| **DPoP binding persists across refresh-token rotation (RFC 9449 §6)** | **Required by RFC 9449** | The new refresh inherits the previous `dpop_jkt`; the new access token is issued via `JwtService::generateTokenForJkt()` so it carries the same `cnf.jkt`. Verified by `DpopRefreshTokenTest`. Dropping the refresh-token `dpop_jkt` and re-creating it on each rotation would break the binding across the rotation boundary, opening a cross-pod replay window. |
| Header `DPoP` typing (`typ: dpop+jwt`) | Shipped | Verified by `VerifyDpopProof` |
| Required headers (`htm`, `htu`, `iat`, `jti`) | Shipped | ±60s window on `iat` |
| Optional `ath` claim (hash of access token) | Shipped | When the bearer is present, `ath` is validated against it |
| ECDSA P-256 (ES256) DPoP proofs | Not implemented | LBDS-258 — Backlog. Dual-support required during the transition. |

## 4. JWT hardening

| Capability | Status | Notes |
| --- | --- | --- |
| RS256 signature, 2048-bit minimum | Shipped | `JwtService` |
| Canonical `jti` claim (UUID v4) | Shipped | `LBDS-254`. Every token now carries a parseable UUID v4 `jti` for revocation + audit. |
| `kid` header on every issued JWT | Shipped | `LBDS-256`. JWKS clients resolve by `kid`, so key rotation does not invalidate cached keys. |
| Short access-token lifetime | `JWT_TOKEN_EXPIRATION=3600` | Configurable via `.env` |
| Refresh-token rotation on every use | Shipped | Old refresh is revoked atomically; replay triggers full session theft revocation. |
| Refresh-token `dpop_jkt` binding | Shipped | See §3. |
| RFC 7009 revocation endpoint (`/oauth/revoke`) | Not implemented | Follow-up (out of scope in LBDS-254): persists `jti ↔ session_id` on `user_sessions` and exposes `POST /oauth/revoke`. No Linear ticket yet — to be filed when work begins. The `jti` mapping foundation is in place since `LBDS-254` shipped. |

## 5. Rate limits

| Endpoint | Limit | Where |
| --- | --- | --- |
| `/api/oauth/token` | 10/min/generic + dedicated per-`(client_id, IP)` and per-IP sliding window | `LBDS-251`. `authorization_code` grant excluded (PKCE + short TTL cover that path). |
| All other API routes | Laravel's generic throttle | `bootstrap/app.php` |
| Login attempt tracking | Stale rows swept by cron | Required cron: `* * * * * cd /path/to/back && php artisan schedule:run` (`DEPLOY.md`). |

## 6. Input validation & errors

| Capability | Status | Notes |
| --- | --- | --- |
| Backend: structural validation of OAuth requests | Shipped | Including invalid `dpop_public_key` (422, not 500). |
| CORS rejection diagnostics | Shipped | `LBDS-261`. Rejected origins get `X-CORS-Rejected`, `X-CORS-Origin`, etc., to make browser-side CORS failures visible. **Never** discloses the union of every client's allowed origins when no `client_id` is resolvable. |
| Origin allowlist for OAuth redirect validation | Shipped | `LBDS-261`. `redirect_uri` whose origin is not in the client's effective CORS origins is rejected **before** the authorization code is issued. Public clients only (server-to-server clients bypass CORS). |
| Front: friendly bilingual error catalog | Shipped | `LBDS-232`. `console.error` keeps the raw backend payload for developers. |
| Front: single channel per error (no double toast/inline) | Shipped | `LBDS-267`. |

## 7. Observability

| Capability | Status | Notes |
| --- | --- | --- |
| `last_error` on `calendar_sources` rows | Shipped | Updated when sync fails (post-`LBDS-264` prune guard). |
| `Stale channel` log warning for non-atomic cache drivers | Shipped | `LBDS-255`. `cache.default=file` or `array` emits a `Log::warning` because replay protection is not atomic across processes. See `AUTHENTICATION.md` §12 "Cache Store Requirements" for the driver-by-driver matrix, TTL behavior, and operator checklist. |
| Logout cookies attribution to user even when refresh rotates | Shipped | `LBDS-267` / `logout()` now sends the rotated refresh in the body. |

## 8. Out of scope (deferred)

These are intentional gaps, documented as part of closing **LBDS-249**:

### 8.1 LBDS-258 — DPoP RSA-2048 → ECDSA P-256 (ES256)

**Decision: deferred.** Not implemented at release v0.35.0 / `sso-server` v0.1.2 / `sso-client` v0.3.0.

**Why deferred:** the migration is a **dual-support** exercise — clients and
servers have to keep accepting the legacy `alg: RS256` while issuing or
honoring `alg: ES256`. Today's RSA-2048 keys produce DPoP headers of 1.5–2 KB
and slow keypair generation on low-end mobile; ECDSA P-256 cuts the header to
300–400 B and is instantaneous. Both improvements are real, but the cost
isn't justified until a baseline of mobile-active users makes the latency or
bandwidth delta observable in p95.

**What would unblock this ticket:**

* Measured mobile p95 above the current acceptable threshold for DPoP overhead.
* A client requesting smaller headers for cost or bandwidth reasons.
* A hardware/regulatory requirement that mandates ES256 specifically.

### 8.2 LBDS-257 — Ed25519 (alg -8) in Passkeys

**Decision: deferred.** Not implemented at release v0.35.0.

**Why deferred:** the current registration flow already accepts both ES256
and RS256, and the deployed authenticator fleet supports at least one of
them. Ed25519 is the most modern `alg` value (`-8`) and is preferred by some
of the newest authenticators (recent YubiKey series, Windows Hello, modern
Android Passkey flows); it is additive (no breaking change to existing
ES256/RS256 credentials), but the win is incremental and the test matrix
requires real hardware.

**What would unblock this ticket:**

* A specific authenticator incompatibility in user reports.
* An enterprise contract that requires Ed25519 for hardware compliance.

### 8.3 LBDS-259 — mTLS / Token Binding TLS

**Decision: not implemented. Backlog-only, kept as a placeholder.**

**Why deferred:** DPoP already provides token binding at the application layer
(RFC 8471, RFC 9449). mTLS adds a second layer of proof-of-possession at
the TLS layer, validated through X.509 client certificates issued by a CA
the server trusts. The combination is meaningful only when a regulatory or
contractual requirement mandates it (PSD2 in EU banking, FedRAMP in US gov,
eIDAS in EU digital identity, regional banking regulations, etc.). YAGNI
without that driver.

**What would unblock this ticket:**

* A contract requiring mutual TLS, or a compliance audit demanding
  certificate-based possession proofs.
* Then the work is non-trivial — it touches the load balancer / ingress,
  a new trust store, and (optionally) a Laravel middleware to extract
  identity from the client cert and correlate it with the existing DPoP
  `jkt`.

## 9. Hardening audit trail

The full ticket history for this hardening wave lives in Linear under the
epic **LBDS-249 [EPIC] SSO Hardening: Crypto, Token Binding & Passkeys**.
For each sub-ticket, the related commits, migrations and tests are linked
in the ticket's evidence section.

| Tier | Tickets | Status |
| --- | --- | --- |
| P0 (epic LBDS-249) | LBDS-253, LBDS-250, LBDS-251, LBDS-252 | Shipped at monorepo `v0.35.0` |
| P1 (epic LBDS-249) | LBDS-256, LBDS-254, LBDS-255 | Shipped at monorepo `v0.35.0` (+ `sso-server v0.1.2` for LBDS-255) |
| P2 (epic LBDS-249) | LBDS-258 (DPoP ECDSA), LBDS-257 (Ed25519) | Deferred — tracked in §8 |
| P3 (epic LBDS-249) | LBDS-259 (mTLS) | Deferred, regulatory-only — tracked in §8.3 |

**Adjacent tickets shipped at the same release** (referenced from §1–§8 but
not formally children of the LBDS-249 epic):

| Ticket | Topic | Status |
| --- | --- | --- |
| LBDS-261 | CORS rejection diagnostics + `redirect_uri` origin allowlist (§2, §6) | Shipped at monorepo `v0.35.0` |
| LBDS-267 | Frontend single-channel error reporting (§6, §7) | Shipped at monorepo `v0.35.0` |
| LBDS-278 | `Cookie::forget()` parity with emission (§2) — smell surfaced by the LBDS-252 pre-merge review | Backlog, not deployed at `v0.35.0` |

**CHANGELOG lag note.** `back/CHANGELOG.md` currently lists LBDS-261 and
LBDS-267 under `## [Unreleased]` while the **code** for both is an ancestor
of `v0.35.0`. The claim of "Shipped" in §2 / §6 / §9 is grounded in the
release tag and the merge history, not in the CHANGELOG section. Closing
the CHANGELOG entries under their released version is tracked separately as
release hygiene; it does not affect security posture.

If a deferred ticket is reopened, this document's status table should be
updated to reflect the new state — **the epic itself remains closed**, the
work happens on a fresh ticket under whatever category re-opens it.
