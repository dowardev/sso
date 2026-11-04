# Changelog

<!-- markdownlint-disable MD013 MD024 -->

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]


## [v0.37.0] - 2026-10-25

### Added

- **The SSO is now a standards-compliant OpenID Connect identity provider (LBDS-303)**: external apps can configure themselves against the SSO the same way they configure against Auth0 / Keycloak / Okta. The new surface covers OIDC Discovery (`/.well-known/openid-configuration`), the `userinfo` endpoint (Bearer access token, scope-filtered claim set), an OIDC-shaped `id_token` issued alongside the access token when the `openid` scope is granted (10-minute lifetime, `aud=client_id`, `nonce` echoed back, scope-driven claim groups), RP-initiated logout at `end_session_endpoint` (id_token_hint-anchored, open-redirect protected, every refresh token and session row for the user revoked), and per-client claim injection so an RP can ask for the exact claim shape it expects. PKCE is required for public clients and optional for confidential ones (RFC 7636 §1). Integration is documented end-to-end in `back/docs/oidc-idp.md` with a copy-paste curl run.

### Changed

- **Login with a second factor is now one step instead of a chooser (LBDS-304)**: when an account has both a passkey and an authenticator app, the second step of the login offers the passkey straight away and keeps the code-based challenge behind a "use another method" link, instead of asking which method to use first. Accounts without a passkey go directly to the code prompt, and the "Olvidaste tu contraseña?" and "Cambiar de usuario" links now sit right below the login card instead of inside it.

## [v0.37.2] - 2026-10-25

### Fixed

- **Passkey now works as a second factor for accounts without an authenticator app**: an account that has a passkey but no TOTP can no longer log in with just the password — the backend issues a `PASSKEY_REQUIRED` challenge (with a single-use, 5-minute pending token) that the client completes with a WebAuthn assertion. The login step 2 is now an explicit user choice (passkey or code) instead of firing the passkey ceremony automatically.

## [v0.37.3] - 2026-11-04

### Changed

- **The pipeline now validates merge requests**: the lint, test and build jobs run on merge requests as well as on release tags, while deploy and rollback stay tag-only.
    - `.gitlab-ci.yml` — new `.pipeline_rules` anchor; `frontend_build` falls back to `.env.production.example` when the production env variable is not exposed to the pipeline.
- **The node toolchain is resolved through mise instead of a pinned image**: the CI installs mise and resolves node from `mise.toml` (`node = "lts"`), and pnpm from `package.json` (`packageManager`), so the versions have a single source of truth.
    - `.gitlab-ci.yml` — new `.mise_setup` anchor; the node jobs run on `debian:bookworm-slim` because the official node builds need glibc.

### Security

- **The deploy CI variables are protected**: `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`, `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `DEPLOY_PATH_BACKEND`, `DEPLOY_PATH_FRONTEND`, `HEALTHCHECK_URL_BACKEND`, `HEALTHCHECK_URL_FRONTEND` and `FRONTEND_ENV_FILE` are now only exposed to protected refs, and the `v*` tag pattern is protected. Before this, a merge-request pipeline would have received the deploy key in its environment.

### Fixed

- **The account page is now reachable from the UI (GL #6)**: `/account` existed as a route but no screen linked to it, so the TOTP / passkey management UI was only reachable by typing the URL. The sidebar now has a **Mi Cuenta** entry available to every authenticated user (not only admins), and on mobile the header avatar opens a menu with **Mi Cuenta**, **Seguridad** (deep-links to the security tab) and **Cerrar Sesión**. The account page itself also gained the navigation menu button, so it is no longer a dead end on phones.

## [0.36.0] - 2026-10-11

### Added

- **TOTP (RFC 6238) 2FA fallback (LBDS-8)**: opt-in second factor for users who cannot use a passkey (notably Firefox + Linux without a local biometric). After enrollment (QR + 6-digit confirmation), the next login shows a TOTP challenge modal that accepts the current 6-digit code from any RFC 6238 authenticator app or one of 10 single-use backup codes. The secret is encrypted at rest, the modal is keyboard-navigable and auto-focuses the input, and disabling or regenerating backup codes requires re-authentication. Passkey users are never forced through TOTP. Pre-merge review fixes: anti-reuse race (lockForUpdate), ambiguous backup-code characters (Crockford-style alphabet), enrollment rate limits, advanced (ZKE) `auth_proof` derivation for disable/regenerate.
- **DPoP proofs can now use ECDSA P-256 / ES256 (LBDS-258)**: every new DPoP key the SSO client generates is ECDSA P-256 — the proof header shrinks from roughly 1.5–2 KB to 300–400 B per request and key generation becomes effectively instant on mobile (WebCrypto on Capacitor, browsers, and Node alike). The server validates both `ES256` and the legacy `RS256` during the gradual migration, so a refresh issued under either algorithm keeps working. A new defense-in-depth check rejects proofs whose `alg` header does not match the JWK's key type before reaching the signature stage, closing an algorithm-confusion class surfaced by the pre-merge review. Stored RSA keys from a previous version of the SSO client still sign `RS256` until the next logout/login rotates them. The migration is gradual by design; a follow-up (LBDS-290) will add the metrics, config flag, and force-rotation needed to retire the RS256 path.
- **DPoP RSA cutover is now operator-controlled and observable (LBDS-290)**: the server-side DPoP allowlist is a runtime config flag (default `['ES256','RS256']`; set `DPOP_ALLOWED_ALGS=ES256` to cut over). A structured per-request metric (`dpop_proofs_total{alg,result}` built from `event=dpop_proof_verified` log lines) tells ops when the migration is ready to close. The sso-client exposes `forceRotateLegacyKeys()` so apps can shorten the tail of pre-migration RSA bindings at logout / session restart. A cutover runbook documents the criterion (< 1% RS256 sustained for 30 days), the two-stage procedure, the rollback path, and the smoke checklist. No user-visible change today; the cutover is a separate decision once the metric settles.
- **Passkeys now support Ed25519 (LBDS-257)**: authenticators that prefer EdDSA — Android Passkeys, modern Linux/Firefox setups, and credentials that rely on self-attestation — can register a passkey against the SSO without being forced to ES256 or RS256. ES256 and RS256 stay available for older devices. Certificate-based packed attestations (YubiKey 5 series, Windows Hello with TPM) are still tracked separately under LBDS-286.

### Fixed

- **Cross-device QR login for passkeys is now reachable (LBDS-225)**: logging in from a desktop browser without a local passkey can scan a QR with the phone's biometrics (Chrome/Edge/Safari + a phone with a hybrid-registered credential). The backend used to hard-code the `allowCredentials.transports` list to `['usb','nfc','ble','internal']` for every credential, so the browser silently filtered hybrid-registered credentials out of its authenticator picker. The login ceremony now forwards the credential's stored transports (including `hybrid` when present) and omits the field entirely when no transports were persisted — letting the browser surface every available authenticator. The passkey hint copy was also updated so a browser without a local passkey knows the QR option exists. Firefox is unaffected (no upstream hybrid support). No new cryptographic surface.
- **Passkey registration now works with YubiKey 5, Windows Hello, and Android devices using Ed25519 + x5c certificate chains (LBDS-286)**: the LBDS-257 release advertised Ed25519 for the algorithm, but the underlying library could not verify packed attestations that carry a certificate chain — exactly the format a YubiKey 5, Windows Hello with TPM, or an Android device returns. Such registrations used to fail server-side with a generic 500. A local wrapper now handles the Ed25519 + x5c case while every other flow continues to use the upstream library unchanged. ES256 and RS256 are unaffected.
- **Logout `refresh_token` clear cookie is now config-consistent (LBDS-278)**: the Set-Cookie that drops `refresh_token` on logout now reflects `session.secure` and `session.same_site` the same way the login cookie does, so an operator with `SESSION_SECURE_COOKIE=false` in dev no longer sees a `Secure` flag on the logout response that was absent on login. No user-visible behavior change (the cookie was being deleted correctly before); this is defense-in-depth cleanup surfaced by the LBDS-252 review (F4).

## [0.35.0] - 2026-09-27

> **Note:** v0.35.0 was tagged on 2026-09-27 but the changelog was not versioned at that time; entries below were in `[Unreleased]` at the tag point. `package.json` still showed `0.34.4` for this release; that historical manifest inconsistency is left in place and not retroactively corrected.

### Fixed

- **The same API error is no longer shown twice (LBDS-267)**: every form-bound error in the SSO frontend used to fire both a toast and an inline message at the same time, so the user saw the same text in two places at once. The translation helper is now pure (returns the friendly copy and keeps the raw detail in the developer console), and each screen explicitly picks one channel — inline for form errors, a toast for actions that have no form. The fix lives entirely in the frontend; the SSO backend and OAuth flow are unchanged.
- **OAuth sign-in no longer leaves you half logged in (LBDS-261)**: when an app's callback origin was not registered among its allowed origins, the SSO logged you in, sent you back to the app, and the app then failed silently to finish signing you in — you stayed logged into the SSO but not into the app, with nothing explaining why. The SSO now detects that mismatch before sending you back and reports it clearly, and the browser-side rejection names the origin that is missing.

### Security

- **Brute force on the OAuth token endpoint is now rate-limited per client and IP (LBDS-251)**: `/oauth/token` is no longer wide open to credential stuffing. After 10 requests in a minute from the same `(client_id, IP)` — or any 10 attempts from the same IP with random `client_id` values trying to discover a real one — the endpoint returns `429 Too Many Requests` with a `Retry-After` header. The standard login flow (authorization code + PKCE) is untouched, and the previous generic per-IP throttle still runs underneath as a backstop.
- **Access tokens now carry a canonical UUID v4 `jti` claim (LBDS-254)**: every JWT the SSO issues — Bearer login, DPoP login, refresh-token rotation, client credentials, and profile attestations — now contains a `jti` (JWT ID) claim formatted as a canonical RFC 4122 UUID v4. This is the prerequisite for per-token granular revocation (RFC 7009), per-token audit trails, and selective device logout: two access tokens for the same user are always distinct, and the predecessor can be revoked independently of any other active session. Persistence of the `jti ↔ session` mapping and the `/oauth/revoke` endpoint itself are tracked as a follow-up.
- **Refresh tokens issued through OAuth can no longer renew a session without proving possession of the device's DPoP key (LBDS-253)**: exchanging a refresh token for a new session now requires a cryptographic proof of possession, so a stolen refresh token alone (for example from an app's local storage) no longer keeps a session alive. The key binding is stored with the refresh token and survives every rotation. OAuth sessions created before this release must log in again once.
- **The decrypted profile handed to an OAuth app no longer lingers in the browser (LBDS-250)**: for advanced (zero-knowledge) accounts the SSO hands the app a decrypted copy of the profile so the server never sees it. That copy travelled in the address fragment, and the app used to leave it there: it stayed visible in the address bar and was kept in the browsing history. Apps that use the client library now erase it the moment they have read it, leaving the rest of the callback URL untouched, and the erase never rewrites a page that is not the callback itself. If you build against the published client, your users get this once you upgrade to the version that includes it.
- **Login and refresh cookies now ship with hardened defaults (LBDS-252)**: every session cookie the SSO emits now defaults to `SameSite=Strict` and `Secure` in production, so CSRF and cross-site cookie theft have one less line of attack. Local dev still works because the underlying flags stay env-overridable, and the production template was updated to the new defaults. OAuth apps that rely on cross-site cookies on the redirect will need to confirm their flow still works (this server's OAuth flows travel tokens in the JSON body or `Authorization` header, not cookies, so nothing in this repo changes client behavior).
- **JWTs now carry a `kid` header and verification resolves by `kid` (LBDS-256)**: every JWT the SSO emits — Bearer login, DPoP login, refresh-token rotation, client credentials, and profile attestations — includes a `kid` header so consumers can match the signing key against the published JWKS. The verification path resolves the key by that `kid`, falling back to the primary key when a token carries no `kid` (tokens issued before this release keep working until they expire). The `kid` value is configurable via `JWT_KID` (default `sso-key-1`); the JWKS endpoint now derives its `keys` array from the same config so future rotation is a config-only change (see `docs/AUTHENTICATION.md` § 7 — Key Rotation). An unknown `kid` produces a clear error rather than a silent verification failure.
- **DPoP replay protection in `sso-server` is now atomic across instances (LBDS-255)**: the package's `ReplayProtection::checkAndStore()` used a non-atomic `Cache::has` + `Cache::put` pair against a per-instance Cache, so any embedding app running more than one pod (k8s, load balancer) could accept the same DPoP `jti` twice — once per pod. It now uses a single `Cache::add()` call, which dispatches to the atomic primitive of whichever driver Laravel is configured to use (`SET NX EX` on Redis, `INSERT IGNORE` on the `database` cache store, Memcached `add`, or in-process for `array`). The operator-facing config knob is unchanged: `CACHE_STORE=redis|database|memcached` is safe across pods; `CACHE_STORE=file` is not. `SsoServerServiceProvider` now emits a `Log::warning` at boot when the configured driver is `file` or `array` so multi-pod deployments never silently lose replay protection. The back side was already correct — `dpop_nonces` has `jti` as PRIMARY KEY plus `INSERT IGNORE` in `VerifyDpopProof` — so this ticket touched only the package. The back's `CleanupDpopNonces` console command stays (the MySQL table still needs periodic cleanup until Redis is provisioned). See `docs/AUTHENTICATION.md` § 12 — Cache Store Requirements.

## [0.34.4] - 2026-09-16

### Fixed

- **Setup wizard unreachable with stale auth token after DB reset (LBDS-265)**:
  - `front` — `router/index.ts`: the navigation guard on `/` and `/login` now checks `GET /api/setup/status` before the authenticated branch — a non-expired JWT from before `migrate:fresh --seed` no longer blocks the redirect to `/setup`. An in-session confirmation flag and a shared in-flight promise dedupe repeat status calls within the same page load (no valid localStorage shortcut).

### Changed

- **Login UX overhaul (LBDS-248)**:
  - `front` — `AuthLoginForm.vue`: step 2 now shows an `ion-segment` method selector (`Contraseña | Passkey`) instead of always displaying the password field with a secondary passkey button. Each method has its own panel: password panel (field + submit), passkey panel (hint + primary button + master-password unlock as sub-state), and a fallback for accounts without passkeys.
  - `front` — `AuthLoginForm.vue`: single vertical rhythm using project tokens (`--space-*`, `--radius-*`, `--glass-*`); `remember-me` row now mirrors `.input-item` glass box, aligned with fields; added `button` + explicit `@click` on the `ion-item` for full-row toggle.
  - `front` — `AuthLoginForm.vue`: passkey hint shortened; master-password unlock rendered as a sub-state inside the passkey panel (with "Volver" returning to the passkey panel, not to password).
  - `front` — `AuthLoginForm.vue`: eliminated all duplicated blocks (remember-me 3x → 1, submit 2x → 1 per method, error 3x → 1, secondary links 2x → 1 per state).
  - `front` — `AuthCard.vue`: auth screens now render inside a glass surface (`--glass-bg`, `--glass-border`, `--radius-lg`) with tokenized padding/spacing and increased max-width (440px). This shell is shared by register, recovery, verify-email, OAuth authorization, and setup screens.

### Added

- `front` — `AuthLoginForm.vue`: `usePasswordMethod` computed to centralize the logic for which panel is active (password when selected OR when account has no passkeys).

### Fixed

- `front` — `AuthLoginForm.vue`: remember-me toggle now reliably responds to clicks anywhere on its row (explicit `@click` handler added alongside Ionic's `button` attribute).
- `front` — `AuthLoginForm.vue`: master-password unlock no longer shows the remember-me toggle (the value was already consumed by the passkey ceremony).
- **Login form and setup guard hardening (pre-release review of v0.34.4)**:
  - `front` — `AuthLoginForm.vue`: `Enter` in step 2 now follows the active method (password → password login, passkey → passkey ceremony, master-password unlock → unlock) instead of always firing the password flow.
  - `front` — `AuthLoginForm.vue`: method selector now uses `:value` + `@ionChange` (repo pattern) and persists the choice in `localStorage` (`sso_login_method`); focus after step 1 and after cancel-unlock follows the active method.
  - `front` — `AuthLoginForm.vue`: remember-me toggle no longer double-flips when clicking the toggle itself (`@click.stop`); "Usar otro usuario" resets the method to the persisted preference; removed manual `aria-selected` from segment buttons.
  - `front` — `router/index.ts`: setup guard re-queries `GET /api/setup/status` on every visit to `/` or `/login` (in-flight dedup only) and clears the stale `localStorage` `setup_completed` claim when setup is incomplete, so a mid-session DB wipe still redirects to `/setup`.
  - `front` — `AuthCard.vue`: glass CSS variables now have safe fallbacks and `max-width` is exposed as a prop (default `min(440px, 100%)`).

## [0.34.3] - 2026-09-10

### Fixed

- **Passkey `rp.id` / allowed origins on split-domain deploys**: `config/passkey.php` defaulted `rp_id` to the `APP_URL` host (API domain), so registration from the SPA on another host died with `'rp.id' cannot be used with the current origin` — and the default `allowed_origins` didn't include the frontend origin either. `setup.sh` now derives `PASSKEY_RP_ID` (frontend host) and `PASSKEY_ALLOWED_ORIGINS` (frontend origin) from `FRONTEND_URL`, filling only when absent so manual overrides survive re-runs; `back/.env.production.example` documents both keys and `DEPLOY.md` troubleshooting covers the failure. Existing installs must set the two vars and refresh `config:cache` (changing `rp.id` invalidates previously registered credentials).
- OAuth clients accept custom-scheme `redirect_url` values (e.g. mobile deep links) in admin CRUD; `approveAuthorization` enforces the registered `redirect_uri` exact match like `authorize` does (LBDS-236).
- **Mobile apps can register for sign-in**: the requirements for native clients are now written down. An app served from inside its own package identifies itself as `https://localhost`, which must be among the client's allowed origins, and a native build must ship with the native client id — otherwise sign-in fails on the token exchange with a generic error and nothing reaches the server logs. `DEPLOY.md` and the `sso-client` README carry the checklist.
- **The logout page no longer trusts the address it is asked to return to**: only an app scheme or a link back to this same site is accepted, so a crafted logout link cannot be used to send users somewhere else.

## [0.34.2] - 2026-09-08

### Fixed

- **Login throttle portable a SQLite**: `ThrottleLoginAttempts` usaba SQL crudo solo-MySQL (`ON DUPLICATE KEY UPDATE`, `IF()`, `NOW()`, `DATE_ADD`) y reventaba con 500 en el stage `backend_test` del CI (SQLite `:memory:`) en vez del 401 esperado. Reescrito con query builder agnóstico, misma semántica (bloqueo de 15 minutos desde el 4to intento fallido).
- **`VerifyEmailTest` sin tabla `settings`**: los Unit tests construían el Mailable — que consulta `settings` en el constructor — sin migraciones. Agregado `RefreshDatabase` siguiendo el patrón de los Feature tests.

## [0.34.1] - 2026-09-08

### Fixed

- **Backend lint gate (pint)**: auto-fix de 31 archivos de `back/` con deuda de estilo preexistente (`single_blank_line_at_eof`, `unary_operator_spaces`, `ordered_imports`, imports sin uso, indentación). El pipeline de `v0.34.0` falló en `backend_lint` y bloqueó el deploy; sin cambios funcionales.

## [0.34.0] - 2026-09-08

### Changed

- **Login simplified to one primary action (LBDS-227)**:
  - `front` — step 2 shows a single primary CTA ("Ingresar"); passkey is a small secondary button; forgot-password / another-user collapsed into one subtle link row; the advanced-encryption banner is gone; master-password unlock is an isolated sub-step with its own cancel link; footer "Volver" only shows on step 1.
  - `front` — accessibility: `aria-invalid` only when invalid, `enterkeyhint`, mobile keyboard hints (`autocapitalize none`, `autocorrect off`, no spellcheck) on the username field, focus management (first invalid field, error region, step transitions), screen-reader step announcements plus a `sr-only` loading live region (replacing `display:none`-based `ion-hide`), toggle follows `$event.detail.checked`.
  - `front` — new global `.sr-only` helper in `theme/variables.css`; removed the duplicate `.field-error` (canonical definition stays in `styles/validation.css`).

### Fixed

- **Login form hardening (pre-merge review of LBDS-229)**:
  - `front` — `AuthLoginForm.vue`: auto-OAuth on mount now resets `loading` in a `finally` (no more stuck disabled buttons when the account is already authorized); step 1 shows one uniform message for every lookup failure (enumeration-safe); master-password unlock no longer trims the password (only the empty check trims); advanced login sets `loading` consistently; the post-login OAuth/dashboard redirect is now a single `runPostLoginRedirect` helper instead of three copies; unverified-email redirect no longer leaks the opaque `login_id` as email.

- **Password login no longer triggers passkey prompt (LBDS-229)**:
  - `front` — `AuthLoginForm.vue` no longer invokes the passkey ceremony after an explicit password login (basic + advanced); passkey only runs via "Iniciar con passkey" (`handlePasskeyLogin`). Removed the now-dead `completePasskey2FA` helper, its unused `passkeyService` import, and an orphaned `loginResult`.

- **Remember-me sessions dropped (LBDS-222)**:
  - `back` — refresh-token reuse detection mistook every rotated token for theft (any token with `rotated_from_id` set), so the second auto-refresh (~2h after login) revoked the whole chain and forced logout. Theft detection now fires only when a revoked token is replayed, with a 30s grace window for cross-tab rotation races; revoke+rotate now run in a DB transaction.
  - `back` — a failed refresh no longer sends `Cookie::forget('refresh_token')`, so the losing side of a tab race can no longer wipe the fresh cookie the winning tab just set.
  - `back` — regression coverage: `RefreshTokenRotationTest` (consecutive rotations, theft replay, race grace window without chain revocation, cookie not cleared on raced 401, persistent vs session cookies for remember-me on/off and their rotation).

    - **Login performance improved (LBDS-202)**: the login screen no longer slows down the browser/PC while idle — the animated background is now static with the same look and zero idle cost.

    - **Friendly error messages (LBDS-232)**: forms and toasts no longer surface raw technical backend text; users see short ES/EN messages while developers keep the full detail in the console. Backend responses unchanged.

### Removed

- **Dead relock wiring**: `front`'s `relockService.ts` and its never-invoked `setupZkeRelock` import in `main.ts` (plus an unused `WrappedKey` import in `prfService.ts`). The ZKE idle re-lock mitigation was never active; reintroduce it as a designed feature if wanted.

### Added

- **Identifier-first login (LBDS-216)**:
  - `back` — new public `POST /api/auth/methods` (`throttle:5,1`) resolves a username-only identifier and reports the account's supported methods: `{ account_found, encryption_level, has_passkeys, login_id }`. Advanced (ZKE) accounts are matched via the deterministic HMAC login_id (`login_id_salt_v1`); email and raw login_id are not valid identifiers and return `account_found: false`. `login_id` is returned for advanced accounts so the client never recomputes the HMAC.
  - `front` — the login screen is now a two-step identifier-first flow: the second step renders only the methods the account supports ("Iniciar con contraseña" always; "Iniciar con passkey" only when enrolled). Passkey login is now a user-scoped ceremony (username/login_id) instead of a discoverable resident-key prompt.

### Removed

- **Login mode toggle**: the `ion-segment` "Contraseña | Passkey" switch and its passkey legend are gone (LBDS-216). Logging in with an email address or a raw Login ID is no longer possible; the identifier is the username only.

### Added

- **Uniform advanced-user (ZKE) identity for OAuth apps (LBDS-205)**:
  - `back` — `GET /api/user/profile-token` (jwt.auth) issues a short-lived (300s) RS256 JWS attesting `(sub, profile_version)` for the authenticated user (`JwtService::issueProfileToken`, `AuthService::issueProfileToken`, `AuthController::profileToken`). The backend never sees the plaintext ZKE profile.
  - `front` — `authController.buildAppRedirectUrl` now fetches `/user/profile-token` after the advanced unlock and appends the signed `{profile, token}` envelope as `#profile=`; omits the fragment when the token is unavailable (advanced identity stays `null`, safe default).

- **Passkey 2FA on SSO login**:
  - `back` — passkey ceremony endpoints (`/passkey/*`) enabled via session middleware, configurable allowed origins, and base64url credential matching; `GET /passkey` lists the authenticated user's passkeys; `DELETE /passkey` requires re-authentication (password for basic, auth_proof for advanced).
  - `front` — passkey as a second factor on login (basic + advanced); enrollment and per-device management in the profile panel; re-auth (password/auth_proof) required to remove a passkey.
- **Password minimum length 12**: enforced server-side for basic/admin flows and attested (`password_length`) on ZKE endpoints; frontend gates updated.

### Changed

- **Rate limiting is production-only**: `ThrottleRequests` passes through outside production so local/testing is not interrupted by "Too Many Attempts".

### Fixed

- **Passkey ceremony**: session middleware on passkey routes ("Session store not set on request"); allowed-origins config for the frontend origin ("Invalid origin"); base64url credential-id matching ("credencial no encontrada").
- **Passkey deletion**: sends the specific `credential_id` (+ re-auth secret) instead of failing.

### Removed

- Spanish docs mirror (`docs/AUTENTICACION.md`).
- Internal `docs/superpowers/*` planning docs are no longer tracked.

### Changed

- **Passkey-only (passwordless) login — LBDS-208**:
  - `front` — login screen now offers a `Contraseña | Passkey` mode. Passkey login is username-less/discoverable (resident keys): the browser presents the user's passkeys and the server resolves the account from the assertion `userHandle`; the passkey JWT becomes the session. Advanced (ZKE) users restore their encrypted profile via silent PRF restore when the passkey has PRF enrolled, otherwise fall back to the master password to unlock (clearly labelled). Passkey registration now wires PRF enrollment for advanced users, and removing a passkey also clears its PRF entry.
  - `back` — passkey registration requires resident keys; passkey auth supports a discoverable (no-identifier) ceremony; `userHandle` is persisted on `passkey_credentials` so resident assertions verify; `rpId` is sent explicitly on authentication options so the `rpIdHash` check matches registration; `login`, advanced-login and passkey-login responses expose `encryption_level`/`login_id` to their own session so the frontend restores advanced state after refresh.
  - **Breaking (no migration backfill)**: passkeys registered before LBDS-208 stored a different `userHandle` and are NOT resident — they will fail the new assertions. Since there are no production users, delete and re-register them. Known-limits are tracked in LBDS-215 (re-enroll PRF after seed-phrase recovery) and PRF restore is scoped to the credential that authenticated (fixing multi-account browsers).


## [0.33.0] - 2026-08-21

### Added

- **Email verification system**: complete flow requiring email verification before login
  - **`back/app/Services/EmailVerificationService.php`**: domain service for create, verify, resend, and purge operations with token hashing (SHA-256), anti-enumeration on resend, and previous token revocation on new issuance
  - **`back/app/Models/EmailVerificationToken.php`**: token model with `pending()` scope, `isValid()` check, and TTL-based expiration
  - **`back/app/Mail/VerifyEmail.php`**: mailable with inline data URI for local logos, bulletproof CTA button, and plain-text fallback
  - **`back/app/Http/Controllers/EmailVerificationController.php`**: `verify` (GET), `resend` (POST) endpoints with throttle middleware
  - **`back/app/Console/Commands/CleanupVerificationTokens.php`**: daily Artisan command to purge expired tokens
  - **`back/database/migrations/2026_08_21_000000_create_email_verification_tokens_table.php`**: table with `verified_at` backfill for existing users
  - **`back/routes/api.php`**: `GET /email/verify/{token}`, `POST /email/verify/resend`
- **Login gate**: `AuthController` blocks login with `EMAIL_NOT_VERIFIED` code when `email_verified_at` is null; frontend `AuthLoginForm.vue` catches the error and redirects to the pending verification screen
- **Frontend verification pages**:
  - **`front/src/views/VerifyEmailPendingPage.vue`**: pending screen with resend button, countdown timer, and link back to login
  - **`front/src/views/VerifyEmailPage.vue`**: auto-verifies token from URL params, restores OAuth context, and redirects to the correct login
- **OAuth context persistence**: `front/src/services/oauthPending.ts` stores PKCE params (including `code_verifier`) in localStorage with 30-minute TTL, surviving the verification redirect hop; `refreshOauthPendingTtl()` extends TTL on resend
- **Email verify origin tracking**: `front/src/services/emailVerifyOrigin.ts` persists the registration origin (admin/public) so post-verify redirect targets the correct login
- **Email branding**: `front/src/composables/useEmailBranding.ts` composable for configurable email logo (separate from auth branding), with upload/remove in `DesignPage.vue`
- **Test coverage**: 40 backend tests (EmailVerificationService, EmailVerificationController, CleanupVerificationTokens, LoginEmailVerificationGate, RegisterEmailVerification, SetupAdminEmailVerified, EmailLogo, EmailVerificationMigration) + 20 frontend tests (useEmailBranding, emailVerifyOrigin, oauthPending)
- **Password recovery system (LBDS-203)**: entry point + UI flow for both basic (email-backed) and advanced (ZKE) users
  - **`back/app/Services/PasswordResetService.php`**: `sendResetLink()` (anti-enumeration, generic 200), `reset()` (token hash, single-use, TTL, revocation), `purgeExpired()`
  - **`back/app/Models/PasswordResetToken.php`**: token model with `pending()` scope (used/revoked/expired), `isValid()`, `markAsUsed()`, `revoke()`
  - **`back/app/Mail/ResetPassword.php`**, **`back/resources/views/emails/reset-password.blade.php`**: reset email with deep link to `/reset-password/{token}`
  - **`back/app/Http/Controllers/AuthController.php`**: `forgotPassword` (`POST /password/forgot`, generic 200, throttle 5,1) and `resetPassword` (`POST /password/reset`, token-based, throttle 5,1)
  - **`back/database/migrations/2026_08_28_000000_create_password_reset_tokens_table.php`**: `user_id`/`token_hash` table (drops the Laravel starter default first)
  - **`front/src/views/RecoveryPage.vue`**: smart detection (email → basic email flow; username → probe `/crypto/blobs` → ZKE seed-phrase or basic), handles `/reset-password/:token` deep link
  - **`front/src/router/index.ts`**: `/recovery` and `/reset-password/:token` public routes
  - **`back/tests/Feature/PasswordResetTest.php`**: feature tests for anti-enumeration, weak-password rejection, single-use and expired-token handling

### Changed

- **`front/src/controllers/authController.ts`**: `register()` no longer auto-logs-in; the account must be email-verified first; OAuth context is persisted via `saveOauthPending`
- **`front/src/views/PublicRegisterPage.vue`**, **`front/src/views/RegisterPage.vue`**: registration navigates to VerifyEmailPending instead of auto-login; saves OAuth pending context before redirect
- **`front/src/services/api.ts`**: added `EMAIL_NOT_VERIFIED` error code handling
- **`front/src/router/index.ts`**: added `VerifyEmail` and `VerifyEmailPending` routes
- **`back/app/Http/Controllers/SetupController.php`**: first admin is created with `email_verified_at` set
- **`back/app/Http/Controllers/SettingController.php`**: email logo upload/remove endpoints (separate from auth logo)
- **`back/app/Services/SettingService.php`**: added `uploadEmailLogo()` and `clearEmailLogo()` for the `email_logo_url` setting
- **`back/app/Models/SsoClient.php`**: added `email_logo_url` to fillable
- **`back/routes/console.php`**: scheduled `tokens:cleanup` daily command
- **`back/.env.development.example`**, **`back/.env.testing.example`**: added `MAIL_MAILER=log` for local email verification testing
- **`front/src/controllers/authController.ts`** moved to **`front/src/composables/authController.ts`**: the module exposes reactive state via `computed`, so it now lives in `composables/` per the Controller vs Composable convention; imports updated across 13 Vue components/views

### Fixed

- **`1c951c4`**: post-verify redirect now routes to the corresponding login (admin vs public) instead of always targeting the public login
- **`730e10b`**: backfill test user isolation for MySQL DDL in migration tests
- **LBDS-203**: `resetPassword` and the recovery UI enforced `min:8` while registration and profile update require `min:10` + complexity (uppercase, lowercase, number, symbol) — a password-policy downgrade on reset. Both now enforce the platform policy
- **PKCE (RFC 7636) enforcement**: the server no longer stores `code_verifier` in the `oauth_auth_codes` table nor accepts it as a fallback during token exchange, closing the security hole where an intercepted authorization code could be redeemed without the real verifier. `code_verifier` is now required at the token endpoint (`required_if:grant_type,authorization_code`) and verified against the stored `code_challenge` with `hash_equals()` for timing-safe comparison
  - **`back/app/Services/OAuth2Service.php`**: removed stored-verifier fallback, enforced verifier requirement, timing-safe challenge comparison
  - **`back/app/Http/Requests/TokenRequest.php`**, **`back/app/Http/Requests/OAuth/TokenRequest.php`**: `code_verifier` now required for `authorization_code` grants
  - **`back/app/Http/Controllers/OAuth2Controller.php`**, **`back/app/Http/Requests/AuthorizeRequest.php`**, **`back/app/Http/Requests/OAuth/AuthorizeRequest.php`**: removed `code_verifier` acceptance at the authorize endpoint (only `code_challenge`/`code_challenge_method` belong there)
  - **`back/app/Models/OAuthAuthCode.php`**: removed `code_verifier` from fillable
  - **`back/database/migrations/2026_08_21_000000_drop_code_verifier_from_oauth_auth_codes_table.php`**: drops the `code_verifier` column
  - **`front/src/controllers/authController.ts`**: removed `code_verifier` transmission to the authorize/approve endpoints

## [0.32.0] - 2026-08-18

## [0.32.0] - 2026-08-18

### Added

- **`packages/sso-client`** (0.0.4): `SSOClient.checkSession()` exposes a real HTTP session check (no cache) so OAuth clients can revalidate their session against the server. `AuthService.refreshToken()` now sends `client_id` and maps the `refresh_token` response field; `TokenRefreshManager` stores the rotated refresh token from either `refreshToken` or `refresh_token`.
- **`back/app/Http/Controllers/OAuth2Controller.php`**: `token()` now accepts the `refresh_token` grant, delegating to `OAuth2Service::handleRefreshToken()` (rotation via `AuthService::refreshAccessToken`)
- **`back/app/Services/OAuth2Service.php`**: `handleAuthorizationCode()` now emits a `refresh_token` in the token response so OAuth clients can refresh without relying on cookies; added `handleRefreshToken()` for the new grant
- **`back/app/Http/Middleware/RefreshToken.php`**: refresh token is now read from the request body (`refreshToken`/`refresh_token`) before falling back to the cookie, aligning transport with the `sso-client` package
- **`back/routes/api.php`**, **`back/app/Http/Controllers/SettingController.php`**: new public endpoint `GET /api/settings/public` exposing `auth_logo_url`, plus admin-only `POST`/`DELETE /api/admin/settings/auth-logo` to upload and remove the auth logo
- **`back/app/Http/Requests/UploadLogoRequest.php`**: upload validation for the auth logo (mimes `png`/`jpg`/`jpeg`/`svg`/`webp`, max 2 MB)
- **`back/app/Services/SettingService.php`**: added `uploadLogo()` and `clearLogo()` to persist the logo on the public disk under `branding/` and clear the `auth_logo_url` setting (deleting the stored file)
- **`front/src/composables/useBranding.ts`**: new `useBranding()` composable that reads `GET /api/settings/public` and exposes `logoUrl`, `loadBranding`, `uploadLogo` and `removeLogo`, resolving the returned logo URL against the API origin
- **`front/src/views/admin/DesignPage.vue`**: new "Logo" section to preview, upload, save and remove the custom auth logo

### Changed

- **`front/src/components/AuthCard.vue`**, **`front/src/views/OAuthAuthorizationPage.vue`**: the configured logo replaces the default logo on the login, register and OAuth authorization screens; a custom logo renders standalone without the monochrome filter or decorative circle, falling back to the default asset otherwise
- **`setup.sh`**: `migrate_db()` now runs `php artisan storage:link` so uploaded logos are served through the public `/storage` symlink

### Security

- **`back/app/Http/Controllers/AuthController.php`**: `logout()` now revokes the refresh token sent in the body (OAuth client flow), the cookie refresh token (SSO web flow), every refresh token of the user, and every active session — a logout in the SSO invalidates OAuth clients and other devices instead of waiting for the access token TTL
- **`back/app/Services/AuthService.php`**: `checkSessionFromRequest()` removed the `sso_user_id` bypass (a request with only `{sso_user_id: 1}` no longer returns `authenticated: true` without a token), rejects `client_credentials` service tokens, and now requires at least one active `user_sessions` row per user — so `/session/check` detects revoked/logged-out sessions
- **`back/tests/Feature/OAuth2RedirectUrlProtectionTest.php`**: Added feature tests proving the `redirect_url` allowlist protection in `OAuth2Controller::authorize`. A client with a configured `redirect_url` rejects a foreign `redirect_uri` with `400`, while a client without `redirect_url` (the pre-fix production state of oneSpend/portafolio) accepts any redirect — documenting the token-leakage gap that PKCE alone does not cover

### Fixed

- **`scripts/deploy/deploy.sh`**: `deploy_setup` now uploads the `.env.*.example` templates into the deployed backend/frontend directories alongside `setup.sh`. The deploy tarballs exclude every `.env*` file (secrets policy), which also stripped the non-secret example templates, so `setup.sh` would abort on a fresh server with "example file not found" before it could create the real `.env` files
- **`setup.sh`**: `configure_backend` falls back to `.env.example` when the specific example file is missing, aborting only when no example exists. `configure_frontend` is now skipped when the frontend is built on a dev machine (shared hosting, no Node.js/pnpm, or `--skip-front-build`), since Vite bakes `VITE_*` values at compile time and the uploaded `dist/` never reads `.env.production` on the server — previously setup blocked on a file it never used
- **`packages/sso-client`** (0.0.3): The `SSOClient` constructor now validates `ssoUrl`, `ssoFrontendUrl`, `apiBaseUrl`, and `clientId`, throwing clear errors when required URLs are missing or invalid. Previously a production build with an empty `VITE_SSO_FRONTEND_URL` only crashed at login with `TypeError: URL constructor: is not a valid URL.`

## [0.31.0] - 2026-08-10

### Added

- **`.gitlab-ci.yml`**: `frontend_build` and `rollback_frontend` now copy the `FRONTEND_ENV_FILE` CI/CD File variable into `front/.env.production` before building, so the pipeline compiles the frontend with the configured production URLs without versioning any `.env.*` file

### Changed

- **`setup.sh`**: `configure_frontend` now verifies that the frontend env file (`.env.development` in `--dev`, `.env.production` otherwise) exists before building. Development mode continues and creates the file from the example template; production mode aborts with an error asking for a configured file with the real URLs
- **`front/src/services/api.ts`**, **`front/src/controllers/authController.ts`**: Removed the hardcoded `http://localhost:8001/api` fallbacks; the API URL is read only from `import.meta.env.VITE_API_URL`, so a production build without a configured `.env.production` breaks loudly instead of silently calling localhost

## [0.30.1] - 2026-08-10

### Fixed

- **`back/app/Http/Controllers/SetupController.php`**: The setup wizard now ensures the `admin` role exists when creating the first administrator user. If the `RoleSeeder` did not run (user skipped seeding in `setup.sh` or accessed the wizard manually), the controller creates the `admin` role inline before assigning it, preventing the admin from being created without the role and being redirected to `/account` instead of `/admin/dashboard` after login.

## [0.30.0] - 2026-08-09

### Added

- **`scripts/deploy/deploy.sh`**: Unified, hardened deploy script with npm and composer version verification, dry-run mode, and optional upload of the setup script to the server (`SETUP_SCRIPT`). New config in `scripts/deploy/deploy.conf` (+ `.example`)
- **`front/public/.htaccess`**: SPA rewrite rule so history-mode routing works on page reload (`RewriteRule . /index.html [L]`)

### Changed

- **`setup.sh`**: Existing `.env` is no longer overwritten on re-run — the script copies the example only if the file does not exist, captures previous values as question defaults, and persists each answer immediately so a cancelled or failed run preserves what was answered. New `unquote_env_val` helper to avoid double-escaping quoted values

### Fixed

- **`scripts/deploy/deploy.sh`**: Glob expansion disabled while building tarball excludes, preventing local `.env` files from traveling to the server and overwriting production config
- **`scripts/deploy/deploy.sh`**: Remote command creates `storage/framework/{views,cache/data,sessions}` before `artisan`, fixing "Please provide a valid cache path"
- **Frontend production**: Configured `front/.env.production` with `VITE_API_URL` pointing to the real domain (`https://auth.dowar.xyz/api`), removing the fallback to `http://localhost:8001/api` that broke CORS calls in the browser. Note: this file is git-ignored (`.env.*`) and is deploy config, not versioned

## [0.29.1] - 2026-07-28

### Removed

- **`sso-demo/` workspace**: Removed the vanilla TS demo directory that consumed `@dowardev/sso-client`. All references were removed: entry in `pnpm-workspace.yaml`, `sso-demo` entry in `package.json` workspaces, `demo` npm script, importer in `pnpm-lock.yaml` (with orphaned dependencies `@faker-js/faker`, `typescript`, `vite`), and mentions in `AGENTS.md`, `DEPLOY.md`, `README.md`, `.gitignore`

### Changed

- **`packages/sso-client/README.md`**: The Demo section now references `front/` as the main reference app for SSO integration, instead of the removed demo link

## [0.29.0] - 2026-07-27

### Added

- **Setup form visual checklist**: Reactive password requirements indicator on `SetupPage.vue` with live status per rule (10 chars, uppercase, lowercase, number, symbol), using `ion-icon` (checkmark/close) for accessible contrast
- **End-to-end test coverage for first-run setup**: 11 PHPUnit Feature tests in `SetupAdminRequestTest` covering password complexity rules, confirmation match, 403 guards (users already exist / setup token missing/invalid), and successful admin creation with admin role assignment
- **One-command setup script**: New `setup.sh` (713 lines) for full project initialization — `.env` generation, key creation, JWT keys, DB migration/seeding, and frontend configuration

### Changed

- **`SetupAdminRequest`**: Moved password complexity regex rules (uppercase, lowercase, number, symbol) from `rules()` into `withValidator()` so each rule can return a specific translated error message instead of a generic regex failure
- **Frontend error UX**: Removed duplicate password error message above the checklist (redundant with checklist's per-rule indicators); submit button now disables only when visible errors exist (`v$.$invalid && v$.$dirty`)
- **Accessibility**: Replaced emoji ✅/❌ indicators with `ion-icon` (checkmark/close) and added `aria-live="polite"` to password checklist for screen reader announcements
- **Wizard security**: Replaced `APP_SETUP_TOKEN` with `User::exists()` guard — setup self-protects once any admin exists, no env token required
- **Frontend router**: Always calls `/api/setup/status` API instead of caching in localStorage
- **Env templates**: Added `APP_KEY=` placeholder, removed `APP_SETUP_TOKEN=` entries

### Fixed

- **Missing trailing newline** in `SetupAdminRequest.php` (POSIX file convention)
- **Setup script regressions**: DB password check, `APP_ENV` configuration, wizard spinner visibility, and code review feedback

### Removed

- **`APP_SETUP_TOKEN`**: Removed from backend (`SetupController`, `EnsureSetupNotCompleted`, `config/app.php`) — setup wizard now relies solely on `User::exists()` guard

### Affected Files

- Modified: `back/app/Http/Requests/SetupAdminRequest.php`, `front/src/views/SetupPage.vue`, `back/.env.example`, `back/.env.production.example`, `AGENTS.md`
- Added: `back/tests/Feature/SetupAdminRequestTest.php`
- Added: `setup.sh`
- Modified: `back/bootstrap/app.php`, `back/config/app.php`, `back/app/Http/Controllers/SetupController.php`, `front/src/router/index.ts`, `DEPLOY.md`, `README.md`

## [0.28.1] - 2026-06-28

### Fixed

- Fixed `.gitignore` to allow root `pnpm-lock.yaml` for pnpm workspaces
- Fixed `package.json` scripts to use `pnpm --filter` for front workspace
- Fixed `DEPLOY.md` documentation: Laravel 11→12, npm→pnpm, GPL→MIT
- Fixed `README.md` database instructions (SQLite→MySQL) and env vars table
- Fixed `back/.env.example` with all required JWT and cache variables
- Fixed markdown lint issues in `CHANGELOG.md`

## [0.28.0] - 2026-06-28

### Added

- **Security hardening**: DPoP nonce support with automatic cleanup, device fingerprint tracking for sessions, login attempt throttling with atomic locking, and CSP Report-Only mode with report endpoint
- **Health monitoring panel**: Real-time dashboard showing system metrics, pending cleanups, CSP reports, and active sessions
- **JWKS endpoint**: Public JSON Web Key Set with 24-hour caching for external JWT verification
- **Password policy enforcement**: Minimum 10 characters with uppercase, lowercase, number, and special character requirements
- **Console commands**: `dpop:cleanup` and `refresh-tokens:cleanup` for scheduled maintenance
- **Database migrations**: Missing performance indexes on OAuth and sessions tables, plain_token to SHA256 token_hash migration
- **XSRF-TOKEN support**: API service now handles XSRF cookie-based CSRF protection

### Changed

- **Design standardization**: Unified glassmorphism design system applied across all admin views, auth pages, user profile, and client forms (LBDS-97)
- **Performance optimizations**:
  - Fixed N+1 query in `UserService::getUserRoles()` by replacing `SsoClient::find` loop with batch `whereIn`
  - Pagination for `getUserSessions()` and `userAuthorizations()` endpoints
  - `revokeAllUserSessions()` uses `lazy()` cursor instead of `get()` to reduce memory
  - HealthController queries cached 60s via `Cache::remember`
  - Frontend: Request deduplication for concurrent identical GET requests
  - Frontend: Lazy-loaded HomePage via dynamic import
  - Frontend: Redundant watchers replaced with computed properties in ActivityLog, AuthorizationsList, ProfileForm
  - Frontend: Theme store initialization made lazy (no side effects on module import)
  - Frontend: `isUnmounted` guard in ProfilePage to prevent state updates after unmount
- **CORS caching**: Event-based invalidation on SsoClient updates with 30s cache TTL
- **Token security**: Plain token column migrated to SHA256 hash

### Fixed

- Infinite 401 loop on logout when session was already revoked
- Dependency tracking in frontend watchers causing unnecessary re-renders

## [0.27.0] - 2026-06-25

### Added

- **Responsive container utility classes**: `.container-sm`, `.container-md`, `.container-lg` for consistent max-width page layouts
- **Focus-visible system**: Global `:focus-visible` outline with primary color, `::selection` styling, and touch target minimum size (44x44)
- **Fluid typography scale**: `clamp()`-based text utilities (`text-fluid-xs` through `text-fluid-xl`) for responsive font sizing
- **Responsive visibility utilities**: `.hide-xs` / `.show-xs` for mobile-specific content control
- **Skeleton shimmer animation**: Reusable `.skeleton-shimmer` class with `prefers-reduced-motion` support
- **Skeleton card/list item components**: Updated with shimmer animation for loading states
- **AuthCard component**: Reusable authentication card with blob background animations, replacing duplicated inline auth screen markup across 4 pages
- **Focus trap composable** (`useFocusTrap`): Keyboard accessibility for modals and popovers

### Changed

- **Design token system**: Implemented comprehensive CSS custom property architecture — spacing scale (`--space-1` through `--space-16`), radius scale (`--radius-sm`/`--radius-md`/`--radius-lg`/`--radius-full`), elevation tokens (`--elevation-1` through `--elevation-4`), and typography scale (`--text-xs` through `--text-3xl`)
- **Border radius standardization**: All UI elements (buttons, modals, cards, inputs) now use consistent 4px radius (`--border-radius: 4px`) replacing previous 10–20px values
- **Ionic-first layout migration**: Dashboard, admin pages, and auth pages migrated from custom flexbox to `ion-grid`/`ion-row`/`ion-col` responsive grid system
- **Vue component structure normalization**: All components reordered to follow the numbered section convention (1–10 per AGENTS.md — Imports, Controllers/Composables, Components, Types, Props/Emits, Vars, Watch, Computed, OnMounted, Functions)
- **Named parameters pattern**: `handleOAuthResponse` and other functions refactored from positional to single-params object pattern
- **Dark/light theme refinement**: Increased contrast on glass morphism values, updated card shadows away from purple tint, elevated text contrast ratios (`--text-secondary` from 0.55 to 0.70, `--text-tertiary` from 0.35 to 0.65), tertiary color changed from purple to amber (`#FBBF24`)
- **Desktop layout enhancement**: Added `col-stack` pattern for card grouping, consistent responsive column sizing across admin views
- **Accessibility improvements**: Added `aria-label`, `aria-describedby`, `aria-hidden="true"` on decorative icons, `autocomplete` attributes on form fields, `role="alert"` on error messages, `prefers-reduced-motion` support on all CSS animations
- **Removed gradient button hover effects**: Removed `translateY` and `box-shadow` transitions from `.gradient-btn` for cleaner interaction design
- **AuthLoginForm**: Refactored to use `AuthCard` component — removed 200+ lines of inline auth screen CSS in favor of shared component
- **OAuthAuthorizationPage**: Simplified template, improved accessibility, and used `AuthCard` component
- **UserProfileWidget removed**: Replaced by `ProfilePage.vue` with standardized structure and `AccountPage` integration
- **Registration pages (Public & Admin)**: Refactored to use `AuthCard`, normalized component structure, removed duplicate auth screen CSS
- **SetupPage**: Standardized with numbered component structure

### Fixed

- **Color scheme contrast**: Improved text readability across light and dark themes with higher contrast ratios
- **Nested HTML issues**: Resolved invalid HTML nesting in responsive segment components
- **ESLint violations**: Fixed all lint errors after component structure normalization

## [0.26.0] - 2026-06-21

### Added

- **Refresh token rotation system**: New `UserRefreshToken` model with SHA256 hashed tokens, `rotated_from_id` chain tracking, and revocation support
- **Token theft detection**: Automatic revocation of entire token chain when a rotated refresh token is reused (rotation-based theft protection)
- **Inactivity timeout**: 7-day inactivity limit for refresh tokens (configurable via `JWT_REFRESH_INACTIVITY`)
- **HttpOnly cookie-based refresh tokens**: Secure, HttpOnly, SameSite=Lax cookies for refresh token storage (no localStorage exposure)
- **Automatic token cleanup**: New `tokens:cleanup` Artisan command scheduled daily to prune expired and revoked tokens
- **401 interceptor with auto-refresh**: Frontend `api.ts` now intercepts 401 responses and attempts cookie-based token refresh before logging out

### Changed

- **Auth service**: Replaced `generateAuthToken()` with `generateTokens()` supporting refresh token generation when `remember_me` is enabled
- **Auth store**: Removed `startAutoRefresh()`, `stopAutoRefresh()`, and interval-based token refresh — refresh is now handled on-demand by the API interceptor
- **JWT configuration**: Extended `refresh_expiration` default from 1 day to 30 days; added `refresh_inactivity` (7 days)
- **Login flow**: Sets `refresh_token` cookie when `remember_me` is enabled
- **Logout flow**: Revokes refresh token in database and clears the cookie
- **Frontend components**: Updated prop bindings to kebab-case convention across user profile and admin views

### Fixed

- **Token refresh endpoint**: Now reads refresh token from HttpOnly cookie via `RefreshToken` middleware instead of insecure request body

### Removed

- **Auto-refresh timer**: Removed interval-based token refresh from auth store (`AUTO_REFRESH_INTERVAL`, `REFRESH_THRESHOLD`)
- **Deprecated auth store methods**: `refreshTokenAsync()`, `isTokenNearExpiry()`, `startAutoRefresh()`, `stopAutoRefresh()`

## [0.25.0] - 2026-06-20

### Added

- **User self-management panel** (`/account` route): profile editing, password change, active sessions with device/location details (ua-parser-js), activity log, and OAuth authorizations list
- **Account deletion**: hard delete endpoint requiring password + "ELIMINAR" confirmation; revokes all tokens, sessions, OAuth authorizations, and activity logs before deleting user
- **Admin users filtering**: filter users by associated OAuth client with visual badge indicator on Users page
- **Role-based account page**: `/account` route replaces `/user/profile/limited`; sidebar hides admin navigation for non-admin users
- **OAuth authorization consent screen** with 3-state tracking (authorized/denied/revoked):
  - New `oauth_client_authorizations` table
  - Explicit consent page showing name, email, username only
  - Endpoints: approve, deny, revoke authorizations
  - Admin views: user authorizations list (UserDetailPage) and client authorizations list (ClientDetailPage)
  - Auth flow checks authorization state before proceeding with OAuth flow
- **New admin endpoints**: `GET /admin/users/authorizations` and `GET /admin/clients/authorizations`
- **Router updates**: added `/admin/clients/:id` route; ClientsPage navigates to ClientDetailPage on click

### Changed

- **Auth store**: `restoreAuth` is now synchronous; `refreshTokenAsync` for async token refresh
- **Router guards**: added `/me` safety net, admin route protection, proper returns
- **UsersPage**: uses `onIonViewWillEnter` for data refresh; SSO client filter badge
- **Template guides**: `TemplatePage.vue` and `TemplateComponent.vue` replace deprecated `TemplateView.vue`

### Fixed

- **api.delete()**: now supports request body (required for account deletion endpoint)
- **TypeScript errors**: resolved pre-existing issues in `themeController`, `UserDetailPage`, `UserProfileWidget`

## [0.24.0] - 2026-06-13

### Fixed

- Added missing show endpoints for SSO clients and roles
- Fixed toggle v-model binding in ClientsPage and AuthLoginForm components
- Resolved token column conflict by renaming to plain_token and updating related code
- Fixed UpdateSsoClientRequest client_id unique validation to use correct model ID
- Fixed toggleAdmin to properly send action parameter and return correct is_admin state
- Fixed UserSessionResource token_active validation to use whenLoaded properly

## [0.23.0] - 2026-06-12

### Added

- `useFormValidation` composable wrapping Vuelidate with `validate()`, `touchAll()`, `reset()` API
- `validators` object in `useValidation.ts` with 9 reusable Spanish-localized validators: `required`, `email`, `minLength`, `maxLength`, `url`, `sameAs`, `alphaNum`, `passwordComplex`, `hostOrIp`
- Client-side form validation on SSO Clients create/edit modal (name, client_id, client_secret, redirect_url, grant_types)
- Client-side form validation on Roles create/edit modal (name, slug, description)
- Client-side form validation on Users edit modal (name, user, email, password)
- Client-side form validation on SSL Exclusions modal (host/IP)
- `.field-error` visual error indicators with red dot prefix on all validated forms
- `aria-invalid` based input border highlighting (red for error, green for success)
- Validation stylesheet (`validation.css`) with input border color feedback
- Unit tests for `useFormValidation` — 22 tests covering all validators and edge cases

### Changed

- SecurityPage: replaced basic `alertController` prompt with validated `ion-modal` for SSL exclusion input
- Generic `catch` error handlers replaced with `handleApiError(err)` for consistent server error display across all admin forms
- Native HTML `required` attribute replaced with Vuelidate-based validation via `@ion-blur`

### Fixed

- `hostOrIp` validator now correctly rejects IP addresses with out-of-range octets (>255) using a custom function validator
- Validation CSS used non-functional Angular `ng-invalid`/`ng-touched` classes — replaced with `[aria-invalid]` attribute selectors

## [0.22.0] - 2026-06-11

### Added

- **SSL Security Panel** at `/admin/config/security` with toggle to require HTTPS on OAuth2 redirect URIs
- **Configurable exclusion list** for non-HTTPS redirect URIs (localhost excluded by default)
- **Persistent key-value settings system** via database (`settings` table, Setting model, SettingService, SettingController)
- **SSL validation on OAuth2 client create/update** (store/update request forms)
- **SSL validation in OAuth2 authorization flow** (rejects non-HTTPS redirect URIs when SSL toggle is active)
- **SSL warning indicators** in ClientsPage — warning icon on client rows and contextual note in the modal form
- **Security frontend stack**: `securityStore`, `securityController`, `Setting` type, route, and ConfigPage integration

### Fixed

- Missing `IonNote` import in ClientsPage causing runtime rendering failure for SSL form note
- Hyphenated event name in SecurityPage (`@ionChange` -> `@ion-change`) to comply with ESLint rules

---

## [0.21.1] - 2026-06-11

### Fixed

- Sidebar no longer appears on auth pages (login, register, setup)
- Route redirect now awaits completion after login, preventing a flash of unauthenticated state

### Changed

- Sidebar visibility computed uses route `meta.requiresAuth` instead of hardcoded path strings

---

## [0.21.0] - 2026-06-10

### Added

- First-run setup flow: new `SetupPage.vue` guides the first admin through initial registration
- `GET /api/setup/status` (public) endpoint to check if any users exist
- `POST /api/setup/admin` (public) endpoint to create the first admin user
- `EnsureSetupNotCompleted` middleware to block setup POST when users already exist
- Router guard that caches setup status in localStorage and redirects to `/setup` on first run
- `setupController.ts` with `checkSetupStatus` and `createFirstAdmin` functions

### Affected Files

- Added: `back/app/Http/Controllers/SetupController.php`, `back/app/Http/Middleware/EnsureSetupNotCompleted.php`, `back/app/Http/Requests/SetupAdminRequest.php`, `front/src/controllers/setupController.ts`, `front/src/views/SetupPage.vue`
- Modified: `back/bootstrap/app.php`, `back/routes/api.php`, `front/src/router/index.ts`

---

## [0.20.0] - 2026-06-10

### Added

- Service layer classes for all business logic: `AuthService`, `JwtService`, `OAuth2Service`, `RoleService`, `SessionService`, `SsoClientService`, `UserService`, `UserSessionService`
- Dedicated controllers replacing monolithic `AdminController`: `SsoClientController`, `UserController`, `UserSessionController`
- API Resource transformers for consistent JSON responses (`RoleResource`, `SsoClientResource`, `UserDetailResource`, `UserResource`, `UserRoleResource`, `UserSessionResource`)
- FormRequest validation classes for all endpoints (`AuthorizeRequest`, `LoginRequest`, `RefreshTokenRequest`, `RegisterRequest`, `StoreRoleRequest`, `StoreSsoClientRequest`, `StoreUserRequest`, `TokenRequest`, `UpdateRoleRequest`, `UpdateSsoClientRequest`, `UpdateUserRequest`)
- Pinia theme store (`stores/theme.ts`) as single source of truth for theme state
- Vuelidate validation composable (`useValidation.ts`) with Spanish messages for frontend forms
- Form validation on 6 components: AuthLoginForm, RegisterPage, PublicRegisterPage, UserDetailPage, RolesPage, ClientsPage

### Changed

- Refactored `AdminController` into single-responsibility controllers (`UserController`, `UserSessionController`, `SsoClientController`, `RoleController`)
- Extracted business logic from controllers into dedicated service layer
- Moved request validation from inline code into FormRequest classes with centralized validation rules
- Updated `AuthController`, `OAuth2Controller`, and `RoleController` to use services and Form Requests
- Moved theme management from `services/theme.ts` to `stores/theme.ts` (Pinia store)

### Fixed

- Fix 65+ ESLint errors across 13 frontend files (unused imports, kebab-case event names, caught error naming, attribute ordering)
- Fix 2 TypeScript errors (readonly mutation on services import, missing `useRouter` export)
- Enforce PKCE as mandatory in OAuth2 authorization code flow — authorization codes without `code_challenge` are now rejected
- Use authenticated user identity (`$request->user()->id`) instead of user-supplied `sso_user_id` for self-delete and self-revoke protection

### Infrastructure

- Add `node_modules`, `pnpm-lock.yaml`, `pnpm-workspace.yaml` to `.gitignore` files

### Removed

- `AdminController` (replaced by dedicated controllers)
- `ApiUsersController` (replaced by `UserController::apiIndex`)
- `front/src/services/auth.ts` (deprecated store proxy, superseded by Pinia stores)

### Affected Files

- Added: 8 Services, 11 FormRequests, 6 Resources, 3 Controllers, `front/src/stores/theme.ts`, `front/src/composables/useValidation.ts`
- Modified: Backend controllers, routes, frontend controllers, views, services
- Modified: `.gitignore`, `front/.gitignore`
- Deleted: `back/app/Http/Controllers/AdminController.php`, `ApiUsersController.php`, `front/src/services/auth.ts`

---

## [0.19.0] - 2026-06-08

### Features

- Add "Remember Me" toggle to login form for persistent session across browser closes
- Add `remember_me` column to `user_sessions` table with migration
- Add `remember_me` claim to JWT tokens for server-side session awareness
- Add automatic token refresh mechanism (50 min interval) when Remember Me is enabled
- Add token refresh on app init if existing token is near expiry (within 10 min threshold)
- Add delete confirmation dialog (`ion-alert`) before removing an SSO client in the admin panel
- Add inline delete button (trash icon) next to client status badge for quick access

### Refactor

- Refactor `registerSession` and `generateToken` to accept `rememberMe` parameter
- Refactor `refreshToken` to propagate `dpop_public_key` and `remember_me` from existing session
- Split `deleteClient` into `confirmDelete` + `deleteClient` two-step flow with alert state management

### Infrastructure

- Add `.gitignore` entries for planning, skills, and superpowers directories

### Affected Files

- Modified: `back/app/Http/Controllers/AuthController.php`
- Modified: `back/app/Models/UserSession.php`
- New: `back/database/migrations/2026_06_07_000001_add_remember_me_to_user_sessions.php`
- Modified: `front/src/components/AuthLoginForm.vue`
- Modified: `front/src/controllers/authController.ts`
- Modified: `front/src/stores/auth.ts`
- Modified: `front/src/views/admin/ClientsPage.vue`

---

## [0.18.0] - 2026-05-23

### Features

- Add OAuth2 `client_credentials` grant type support with `grant_types` column for SsoClient
- Add `ClientCredentialsAuth` middleware to protect machine-to-machine API routes
- Add `ApiUsersController` with searchable, paginated user listing endpoint (`GET /api/users`)
- Add client secret generator and copy-to-clipboard in admin clients page
- Add password visibility toggle for client secret input field
- Add `grant_types` toggle UI (`authorization_code`/`client_credentials`) in client create/edit form

### Refactor

- Refactor `OAuth2Controller::token()` to support multiple grant types (`authorization_code`, `client_credentials`)
- Refactor `JwtAuth` middleware to handle `client_credentials` tokens without user resolution

### Infrastructure

- Migrate frontend linter from ESLint 8 to ESLint 9 (flat config)
- Replace `@vue/eslint-config-typescript` and `@vue/eslint-config-prettier` with `typescript-eslint` + `eslint-plugin-oxlint`
- Add `oxlint` and `oxfmt` for Rust-based linting and formatting
- Remove `prettier` and related ESLint plugins and configs

## [0.17.3] - 2026-05-18

### Fixed

- Remove redundant `ValidateClientOrigin` middleware that was blocking login from external clients (like portfolio)
- Fix `normalizeOrigin()` default scheme from `https` to `http` (was causing CORS failures when FRONTEND_URL had no protocol)
- Fix `FRONTEND_URL` in `.env` to include protocol (`http://localhost:8000` instead of `localhost:8000`)

### Features

- Implement `/refresh-token` endpoint for token renewal (was missing - sso-client was calling it but backend didn't have it)

### Refactor

- Simplified CORS architecture: single `HandleDynamicCors` middleware instead of two-layer validation (ValidateClientOrigin + HandleDynamicCors)

### Affected Files

- Modified: `back/routes/api.php`
- Modified: `back/app/Http/Middleware/HandleDynamicCors.php`
- Modified: `back/.env`
- Modified: `back/app/Http/Controllers/AuthController.php`
- Deleted: `back/app/Http/Middleware/ValidateClientOrigin.php`
- Modified: `back/bootstrap/app.php`

---

## [0.17.2] - 2026-05-18

### Fixed

- Add missing `FRONTEND_URL` variable to backend `.env.example` template (required for CORS dynamic middleware and Sanctum auto-derive)
- Correct `VITE_API_URL` port in `front/.env.development.example` (8000 -> 8001)
- Update production API URL example in `front/.env.production.example` to match actual deployment domain

### Affected Files

- Modified: `back/.env.example`
- Modified: `front/.env.development.example`
- Modified: `front/.env.production.example`

---

## [0.17.1] - 2026-05-18

### Fixed

- Fixed admin login failing with "failed to fetch" on cross-origin deployments: removed `/login` route from `client.origin` middleware group (the admin panel is not an OAuth2 client, it should not require `client_id` validation)
- Fixed CORS errors in admin panel when frontend and backend are on different subdomains: `HandleDynamicCors` now automatically includes the SSO frontend origin from `FRONTEND_URL` env variable
- Fixed API fallback port mismatch: corrected `VITE_API_URL` default from `http://localhost:8000` to `http://localhost:8001`

### Changed

- Simplified `IsAdmin` middleware: removed per-client role logic, now only checks for global `admin` role
- Refactored `AuthController.me()` and `checkSession()`: removed `client_id` requirement and per-client role filtering
- Refactored `User` model: removed `withPivot('sso_client_id')` from roles relationship and removed `getEffectiveRole()` method
- Updated backend environment template (`.env.example`): switched from SQLite to MySQL defaults, added JWT configuration, Sanctum stateful domains, and Spanish locale
- Updated frontend environment structure: removed deprecated `.env` and `.env.example`, replaced with proper `.env.development` / `.env.development.example` / `.env.production` / `.env.production.example` split
- Updated `DEPLOY.md` documentation: added `FRONTEND_URL` configuration for CORS when frontend and backend are on different subdomains

### Style

- Fixed CSS syntax error in `UserDetailPage.vue` (missing closing brace)

### Affected Files

- Modified: `back/app/Http/Middleware/HandleDynamicCors.php`
- Modified: `back/app/Http/Middleware/IsAdmin.php`
- Modified: `back/app/Http/Controllers/AuthController.php`
- Modified: `back/app/Models/User.php`
- Modified: `back/config/app.php`
- Modified: `back/routes/api.php`
- Modified: `back/.env.example`
- Modified: `DEPLOY.md`
- Modified: `README.md`
- Modified: `front/src/services/api.ts`
- Modified: `front/src/views/admin/UserDetailPage.vue`
- Removed: `front/.env`, `front/.env.example`, `back/.env.example.production`
- Added: `front/.gitignore` entry for `.env.production`

---

## [0.17.0] - 2026-05-17

### Security

- Fixed CORS origin validation bypass when `client_id` is omitted (ValidateClientOrigin middleware now returns 403 if Origin header is present but no client_id)
- Added `Vary: Origin` header on all CORS responses to prevent cache poisoning
- Added `Vary: Origin` header on 403 rejection responses
- Mass assignment protection: replaced `$request->all()` with `$validator->validated()` in AdminController

### Added

- Dynamic CORS system with `allowed_origins` per OAuth2 client
- `ValidateClientOrigin` middleware for client-specific origin validation
- `HandleDynamicCors` middleware replacing Laravel's HandleCors
- `SsoClientObserver` for automatic cache invalidation on model changes
- URL normalization for origin comparison (handles trailing slashes, default ports, case sensitivity)
- Frontend URL validation and normalization in ClientsPage addOrigin function
- Per-client CORS configuration with `allowed_origins` array field and database migration
- Named parameters pattern for `deleteClient` function

### Changed

- Refactored CORS handling to read allowed methods/headers from `config('cors.*')` instead of hardcoded values
- Added `Access-Control-Expose-Headers` support from config
- Replaced `Cache::forget()` calls in AdminController with automatic invalidation via observer
- Cleaned up dead configuration keys from `cors.php` (`allowed_origins`, `allowed_origins_patterns`)
- Updated `Vary` header handling to preserve existing values instead of overwriting
- ValidateClientOrigin now checks `active` status of client
- Backend origin validation now uses regex `^https?://[^/:]+(:\d+)?$/` to ensure CORS-compliant origins (no paths)

### Fixed

- Fixed cache poisoning vulnerability in HandleDynamicCors (missing Vary: Origin on non-matching origins)
- Fixed cache poisoning vulnerability in ValidateClientOrigin (missing Vary: Origin on 403 responses)
- Fixed inconsistent origin comparison (now normalizes URLs before comparison)
- Fixed missing origin validation in frontend (addOrigin now validates URL format)

### Affected Files

- Added: `back/app/Http/Middleware/HandleDynamicCors.php`
- Added: `back/app/Http/Middleware/ValidateClientOrigin.php`
- Added: `back/app/Observers/SsoClientObserver.php`
- Added: `back/database/migrations/2026_05_12_000000_add_allowed_origins_to_sso_clients_table.php`
- Modified: `back/app/Http/Controllers/AdminController.php`
- Modified: `back/app/Http/Controllers/AuthController.php`
- Modified: `back/app/Models/SsoClient.php`
- Modified: `back/app/Providers/AppServiceProvider.php`
- Modified: `back/bootstrap/app.php`
- Modified: `back/config/cors.php`
- Modified: `front/src/controllers/clientsController.ts`
- Modified: `front/src/stores/clients.ts`
- Modified: `front/src/types/index.ts`
- Modified: `front/src/views/admin/ClientsPage.vue`

---

## [0.16.0] - 2026-05-09

### Added

- Client ID validation against SsoClient table in `/me`, `/user-auth`, and `/session/check` endpoints
- Proper internal sso_client_id mapping for role filtering

### Changed

- Refactored `AuthController.me()` to validate client_id and use internal sso_client_id for role queries
- Refactored `AuthController.checkSession()` to validate client_id and use internal sso_client_id for role queries
- Removed `is_admin` attribute from User model - now relies solely on role-based admin check
- Removed `is_admin` from AdminController update validation and data handling
- Updated AdminUserSeeder and DatabaseSeeder to work with role-based system

### Fixed

- Session check endpoint now properly validates and filters roles by client_id (was using raw client_id string in query)

### Affected Files

- Modified: `back/app/Http/Controllers/AdminController.php`
- Modified: `back/app/Http/Controllers/AuthController.php`
- Modified: `back/app/Models/User.php`
- Modified: `back/database/seeders/AdminUserSeeder.php`
- Modified: `back/database/seeders/DatabaseSeeder.php`

---

## [0.15.0] - 2026-05-08

### Added

- Filter roles by `client_id` in `/me`, `/user-auth`, `/session/check` endpoints

### Changed

- Migrated from `is_admin` boolean column to role-based system with three roles: `admin`, `editor`, `viewer`
- Refactored `IsAdmin` middleware to use `hasRole('admin')` instead of `is_admin` attribute
- Updated toggle endpoint to manage admin role instead of is_admin field
- Refactored `AuthController` to filter roles by `client_id`

### Breaking Changes

- Removed `is_admin` column from users table - existing users with is_admin=true now need admin role assigned
- Database migration required: roles table now uses slug-based system (admin/editor/viewer) instead of role names

### Affected Files

- Added: `back/database/migrations/2026_05_08_000001_update_roles_slugs.php`
- Added: `back/database/migrations/2026_05_08_000002_drop_is_admin_column.php`
- Modified: `back/app/Http/Controllers/AdminController.php`
- Modified: `back/app/Http/Controllers/AuthController.php`
- Modified: `back/app/Http/Middleware/IsAdmin.php`
- Modified: `back/database/seeders/RoleSeeder.php`

---

## [0.14.3] - 2026-05-08

### Added

- New dashboard design with Ionic components (ion-card, ion-list, ion-item)
- Gradient header with icon overlay for stat cards (users, sessions, clients)

### Changed

- Replaced all custom gradient CSS variables (`--gradient-primary`, `--gradient-success`, etc.) with Ionic's built-in color system (`var(--ion-color-primary)`, `var(--ion-color-secondary)`)
- Updated macchiato dark theme text colors for improved visibility
- Simplified theme system by removing redundant gradient custom properties
- Refactored stat card accents to use solid Ionic colors instead of gradient backgrounds

### Affected Files

- Modified: front/src/App.vue
- Modified: front/src/theme/files/catppuccin-latte-maroon.css
- Modified: front/src/theme/files/catppuccin-macchiato-maroon.css
- Modified: front/src/theme/files/light.css
- Modified: front/src/theme/files/dark.css
- Modified: front/src/views/HomePage.vue
- Modified: front/src/views/admin/DashboardPage.vue
- Modified: front/src/views/admin/DesignPage.vue
- Modified: front/src/views/admin/UserDetailPage.vue
- Modified: front/src/views/admin/UsersPage.vue

---

## [0.14.2] - 2026-05-07

### Changed

- Unified design across all 4 auth pages (LoginPage, RegisterPage, PublicLoginPage, PublicRegisterPage)
- Refactored LoginPage and RegisterPage to use ion-grid/ion-card layout with logo SVG, matching the public auth pages
- Applied consistent styling with card shadows, floating labels, and centered grid layout

### Affected Files

- Modified: front/src/views/LoginPage.vue
- Modified: front/src/views/RegisterPage.vue
- Modified: front/src/views/PublicLoginPage.vue
- Modified: front/src/views/PublicRegisterPage.vue

---

## [0.14.1] - 2026-05-07

### Changed

- Restored original design from commit fc48447d in PublicLoginPage, PublicRegisterPage, and LoginPage
- Replaced modern card-based layouts with classic ion-grid/ion-card structure for public auth pages
- Restored LoginPage to gradient background with "SSO Admin" header (removed logo image and modern styling)

### Affected Files

- Modified: front/src/views/PublicLoginPage.vue
- Modified: front/src/views/PublicRegisterPage.vue
- Modified: front/src/views/LoginPage.vue

---

## [0.14.0] - 2026-05-07

### Added

- Auto-authorization flow for authenticated users in OAuth2 authorization flow
  - PublicLoginPage and PublicRegisterPage now automatically redirect authenticated users to authorization without showing login form
  - Calls `authController.oauthAuthorize` directly when user has valid session in OAuth flow
- SSO client demo application migrated from monolithic HTML to Vite + TypeScript
  - New `sso-client/` directory with complete Vite project structure
  - Uses `@tu-org/sso-client` SDK via local link
  - Modular TypeScript code: main.ts (bootstrap), app.ts (logic), style.css (styling)
  - Runs on port 5174 with proper Vite configuration

### Changed

- Made `onMounted` async in PublicLoginPage and PublicRegisterPage to support auto-authorization flow

### Affected Files

- Modified: `front/src/views/PublicLoginPage.vue`, `front/src/views/PublicRegisterPage.vue`
- Added: `sso-client/.gitignore`, `sso-client/index.html`, `sso-client/package.json`, `sso-client/package-lock.json`
- Added: `sso-client/src/main.ts`, `sso-client/src/app.ts`, `sso-client/src/style.css`, `sso-client/src/vite-env.d.ts`
- Added: `sso-client/tsconfig.json`, `sso-client/vite.config.ts`

---

## [0.13.1] - 2026-05-06

### Added

- New frontend architecture with controller pattern and named parameters

### Changed

- All Vue components refactored to follow TemplateView guide structure
- Controllers implemented for auth, users, clients, sessions, and roles
- Named parameters pattern for all controller functions

### Affected Files

- Added: `front/src/controllers/authController.ts`, `front/src/controllers/usersController.ts`, `front/src/controllers/clientsController.ts`, `front/src/controllers/sessionsController.ts`, `front/src/controllers/rolesController.ts`
- Modified: All Vue components in `front/src/views/`
- Modified: `front/src/router/index.ts`

---

## [0.13.0] - 2026-05-06

### Added

- Pinia v3.0.4 state management library
- 5 Pinia stores for centralized frontend state management:
  - `useAuthStore` - authentication state and actions (token, user, login, logout)
  - `useUsersStore` - users list, detail, roles, CRUD operations
  - `useSessionsStore` - sessions list with active filter and revocation
  - `useClientsStore` - SSO clients CRUD with computed counts
  - `useRolesStore` - roles CRUD operations
- Dedicated TypeScript types file (`front/src/types/index.ts`) with all interfaces

### Changed

- Refactored all admin pages to use Pinia stores instead of local state + direct API calls:
  - UsersPage, SessionsPage, ClientsPage, RolesPage, DashboardPage, UserDetailPage
- Refactored auth pages to use useAuthStore:
  - LoginPage, PublicLoginPage, RegisterPage, PublicRegisterPage
- `services/auth.ts` now delegates to useAuthStore with backward-compatible exports
- `services/api.ts` now re-exports types from `@/types` for backward compatibility
- App.vue and router/index.ts now use stores directly for auth state

### Affected Files

- Added: `front/src/stores/auth.ts`, `front/src/stores/users.ts`, `front/src/stores/sessions.ts`, `front/src/stores/clients.ts`, `front/src/stores/roles.ts`, `front/src/types/index.ts`
- Modified: `front/package.json`, `front/src/main.ts`, `front/src/services/api.ts`, `front/src/services/auth.ts`, `front/src/App.vue`, `front/src/router/index.ts`
- Modified: `front/src/views/LoginPage.vue`, `front/src/views/PublicLoginPage.vue`, `front/src/views/PublicRegisterPage.vue`, `front/src/views/RegisterPage.vue`
- Modified: `front/src/views/admin/UsersPage.vue`, `front/src/views/admin/UserDetailPage.vue`, `front/src/views/admin/SessionsPage.vue`, `front/src/views/admin/ClientsPage.vue`, `front/src/views/admin/RolesPage.vue`, `front/src/views/admin/DashboardPage.vue`

---

## [0.12.0] - 2026-05-06

### Added

- Complete theme system with 5 themes: Auto, Light, Dark, Catppuccin Latte, Catppuccin Macchiato
- Auto theme mode that follows system preference with customizable light/dark sub-selectors
- Configuration menu item in sidebar with Personalization link
- Responsive ion-grid layouts in DashboardPage, UserDetailPage, and DesignPage
- Centered desktop layout with reduced max-width (600-900px) for better presentation

### Changed

- Complete UI redesign of all admin and auth pages
- Replaced all hardcoded colors with CSS custom properties (`--text-primary`, `--text-secondary`, `--text-tertiary`, `--background-contrast`, `--gradient-primary`, etc.)
- Updated all card components to use `var(--card-border-radius)`, `var(--card-shadow-light)`, `var(--card-shadow-heavy)`
- Applied `class="ion-no-border" mode="ios"` to all ion-header components
- Migrated from `dark.system.css` to `dark.class.css` in main.ts for manual theme control

### Fixed

- Fixed `.session-date` color in SessionsPage that was invisible in dark mode (was using `var(--ion-color-light)` which is dark, now uses `var(--text-tertiary)`)

### Affected Files

- Added: `front/src/services/theme.ts`, `front/src/components/ThemePopover.vue`, `front/src/views/admin/ConfigPage.vue`, `front/src/views/admin/DesignPage.vue`, `front/src/views/admin/AdminLayout.vue`
- Modified: `front/src/App.vue`, `front/src/main.ts`, `front/src/router/index.ts`, `front/src/theme/files/light.css`, `front/src/theme/files/dark.css`, `front/src/theme/files/catppuccin-latte-maroon.css`, `front/src/theme/files/catppuccin-macchiato-maroon.css`
- Modified: `front/src/views/HomePage.vue`, `front/src/views/LoginPage.vue`, `front/src/views/PublicLoginPage.vue`, `front/src/views/RegisterPage.vue`, `front/src/views/PublicRegisterPage.vue`
- Modified: `front/src/views/admin/DashboardPage.vue`, `front/src/views/admin/UsersPage.vue`, `front/src/views/admin/UserDetailPage.vue`, `front/src/views/admin/SessionsPage.vue`, `front/src/views/admin/RolesPage.vue`, `front/src/views/admin/ClientsPage.vue`

---

## [0.11.2] - 2026-05-06

### Fixed

- Fixed `Call to undefined method Lcobucci\JWT\Token\Plain::isValid()` in `AuthController` by replacing deprecated `isValid()` calls with `validator()->validate()` using explicit constraints (`SignedWith`, `IssuedBy`, `LooseValidAt`), compatible with lcobucci/jwt 5.x
- Applied fix to both `me()` and `checkSession()` methods

### Affected Files

- Modified: `back/app/Http/Controllers/AuthController.php`

---

## [0.11.1] - 2026-05-05

### Fixed

- Fixed JWK Thumbprint computation to use RFC 7638 canonical JSON key order (`e`, `kty`, `n` alphabetically) instead of non-standard order (`kty`, `n`, `e`) in `AuthController`, `OAuth2Controller`, and `VerifyDpopProof` middleware
- Added `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` flags to PHP `json_encode` calls for consistent canonical JSON output across the ecosystem
- Fixed JWK Thumbprint computation in frontend `dpopService.ts` to use RFC 7638 canonical key order (`e`, `kty`, `n`)

### Changed

- JWK Thumbprint computation is now RFC 7638 compliant across all 4 implementations (3 backend PHP + 1 frontend TypeScript), ensuring interoperability with the `tu-org/sso-server` package

### Affected Files

- Modified: `back/app/Http/Controllers/AuthController.php`
- Modified: `back/app/Http/Controllers/OAuth2Controller.php`
- Modified: `back/app/Http/Middleware/VerifyDpopProof.php`
- Modified: `front/src/services/dpopService.ts`

---

## [0.11.0] - 2026-05-04

### Added

- PKCE code_verifier support in OAuth2 authorization endpoint for enhanced security
- code_verifier field to OAuthAuthCode model and database table
- Frontend support for passing code_challenge, code_challenge_method, and code_verifier through login/register OAuth flow

### Changed

- Made code_verifier optional in token endpoint to support both PKCE and non-PKCE authorization flows

---

## [0.10.0] - 2026-05-04

### Added

- JWT key generation Artisan command (`jwt:generate-keys`) with configurable key size and force overwrite option
- Multi-theme support system with four theme variants: light, dark, catppuccin-latte-maroon, and catppuccin-macchiato-maroon
- Prettier configuration (`.prettierrc`) with Vue script/style indentation support
- Enhanced ESLint configuration with stricter TypeScript and Vue rules (`vue3-recommended`, `@vue/eslint-config-prettier`, explicit return types, no unused vars, attribute ordering)
- Lambda Services branding logo (`lambda_v1.svg`)
- New CSS utility classes for themed cards, borders, and transparent Ionic components
- Build-time ESLint validation (`eslint . --max-warnings 0`) in frontend build pipeline

### Changed

- Redesigned public authentication pages (login and register) with responsive card-based layout and new branding
- Replaced string-based router navigation with named route objects and query parameters
- Improved TypeScript type safety across frontend services (replaced `any` with `unknown`/`Error` types)
- Updated all Vue event handlers to use kebab-case naming (`@ion-change`, `@did-dismiss`, `@ion-input`)
- Standardized Vue attribute ordering across all templates per `vue/attributes-order` rule
- Applied consistent Prettier formatting (2-space indentation with Vue script/style blocks) across all frontend files
- Updated `DEPLOY.md` and `README.md` documentation

### Fixed

- Proper error type handling in public login and register page catch blocks
- Missing trailing commas and semicolons in TypeScript files
- Inconsistent code style in admin panel Vue components (Clients, Roles, Sessions, Users, UserDetail, Dashboard)

---

## [0.9.1] - 2026-05-04

### Added

- Comprehensive `README.md` at project root with full architecture, tech stack, installation, and authentication flows
- `DEPLOY.md` deployment guide with server configuration, SSL setup, and troubleshooting

### Changed

- Restructured project documentation from `back/` directory to repository root for better visibility
- Updated `back/.gitignore` to exclude `keys/` directory

### Removed

- Deprecated `back/DEPLOYMENT_CHECKLIST.md` (replaced by root `DEPLOY.md`)
- Deprecated `back/README.md` (replaced by root `README.md`)

### Security

- Removed committed RSA key files (`back/keys/private.pem` and `back/keys/public.pem`) from repository

---

## [0.9.0] - 2026-05-02

### Added

- PKCE (Proof Key for Code Exchange) support for OAuth2 authorization code flow
- `code_challenge` and `code_challenge_method` fields in OAuth authorization codes
- `code_verifier` validation in token endpoint with S256 and plain challenge methods
- Database migration to add PKCE fields to `oauth_auth_codes` table
- `computeCodeChallenge` private method for PKCE verification

---

## [0.8.0] - 2026-05-02

### Added

- OAuth2 Authorization Code flow implementation with OAuth2Controller
- OAuth2 `/oauth/authorize` endpoint for authorization code generation
- OAuth2 `/oauth/token` endpoint for exchanging codes for JWT tokens
- OAuthAuthCode model with expiration tracking and usage status
- Database migration for `oauth_auth_codes` table
- DPoP service (`dpopService.ts`) for key pair generation and proof creation
- Public authentication pages: PublicLoginPage and PublicRegisterPage
- Admin registration page (RegisterPage.vue)
- Session check endpoint (`/session/check`) for validating existing sessions

### Changed

- JwtAuth middleware now sets user resolver after successful JWT validation
- Updated router to include public auth routes (`/auth/login`, `/auth/register`, `/auth/callback`)
- Auth service now supports DPoP public key storage and retrieval
- API service now supports extra headers for DPoP proof headers
- Login and register pages now support OAuth flow with client_id redirect

### Fixed

- JwtAuth middleware now properly sets authenticated user in request

---

## [0.7.0] - 2026-05-01

### Added

- JWT authentication system with RSA key pair (RS256 algorithm)
- DPoP (Demonstrating Proof of Possession) support for enhanced token security
- JWKS endpoint (`/.well-known/jwks`) for public key distribution
- New `JwtAuth` middleware for token validation
- New `VerifyDpopProof` middleware for DPoP proof verification
- JWT configuration file with customizable settings (expiration, issuer, algorithm)
- RSA key pair files for token signing and verification

### Changed

- Replaced Laravel Sanctum with lcobucci/jwt library for authentication
- AuthController now generates and validates JWT tokens directly
- Updated routes to use `jwt.auth` middleware instead of `auth:sanctum`
- UserSession model now stores JWT token and DPoP public key

### Fixed

- Custom validation messages for `client_id` in register and login endpoints

---

## [0.6.2] - 2026-05-01

### Fixed

- Custom validation messages for `client_id` in register and login endpoints providing clearer error feedback

---

## [0.6.1] - 2026-05-01

### Added

- IonItemSliding component support in UserDetailPage for enhanced UI interactions

### Removed

- Root opencode.json configuration file (cleanup)

### Changed

- Package.json removed from root (workspace structure refactoring)

---

## [0.6.0] - 2026-05-01

### Added

- Role-Based Access Control (RBAC) system with three roles: admin, editor, viewer
- Role model and database table with slug-based system
- User-Role many-to-many relationship
- Middleware for role-based route protection

---

## [0.5.0] - 2026-03-29

### Added

- Username-based authentication: login now accepts either `email` or `user` (username) field
- Optional `client_id` parameter for login endpoint - sessions can be created without specifying a client

### Changed

- Login validation now allows either email or username
- Made `client_id` nullable in login endpoint
- Session registration now handles null client gracefully

---

## [0.4.0] - 2026-03-29

### Added

- New Ionic/Vue frontend application (`front/`)
- SSO client demo files for testing integrations (`sso-client/`)
- Opencode configuration file for AI-assisted development

### Restructuring

- Moved Laravel backend to dedicated `back/` directory
- Project now uses monorepo structure with separate frontend and backend

### Breaking Changes

- Project directory structure changed - backend is now in `back/` subdirectory

---

## [0.3.0] - 2026-03-29

### Added

- Admin panel with full user management (CRUD, search, pagination)
- Session management endpoints (view all sessions, per-user sessions, revoke sessions)
- SSO Clients management (CRUD for client applications)
- Admin role system with `is_admin` flag and `IsAdmin` middleware
- User session tracking per application (IP, user agent, last used)

### Changed

- Login and registration now require `client_id` parameter to track sessions per application

### Breaking Changes

- `/api/login` and `/api/register` endpoints now require `client_id` parameter - existing integrations must be updated

---

## [0.2.0] - 2026-03-27

### Added

- User 'user' field (username) to User model for username-based authentication
- Username generation in UserFactory using faker with unique constraint
- Test user seed data with username field in DatabaseSeeder

### Changed

- Updated DatabaseSeeder to include username for test user

---

## [0.1.1] - 2026-01-17

### Added

- Production environment template (`.env.example.production`) with complete configuration
- Comprehensive deployment checklist (`DEPLOYMENT_CHECKLIST.md`)

### Changed

- Updated README.md with English documentation
- Added production configuration section with domain examples

---

## [0.1.0] - 2026-01-16

### Added

- Initial SSO Server implementation
- User registration, login, logout, profile endpoints
- Laravel Sanctum integration for API token authentication
