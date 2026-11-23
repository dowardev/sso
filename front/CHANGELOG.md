# Changelog

All notable changes to the frontend (`front/`, Ionic Vue 8) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v0.38.0] - 2026-11-23

### Added

- **The API error codes have a single source of truth in the frontend**: the catalogue maps every code the backend can answer with to its ES and EN copy, plus the `ApiErrorDetail` type for the `error_details` payload. A code that is not listed is treated as unmapped: the UI falls back to generic copy and logs the raw code, so the gap is detectable instead of silent.
    - `utils/apiErrorCodes.ts` (new) — the catalogue and the detail type.
    - `utils/__tests__/apiErrorCodes.test.ts` — covers the catalogue contract.
- **The activity log entries are written by the frontend**: the raw event of each activity entry is translated into a readable sentence, so the API no longer has to compose a sentence for the UI.
    - `utils/activityDescriptions.ts` (new), `utils/__tests__/activityDescriptions.test.ts` (new).

### Changed

- **The user-facing errors are resolved by code, not by the message text**: the technical failure reason returned by the API is mapped to the sentence the user reads, and the legacy prose path is kept only as a documented fallback for the codes the catalogue does not know.
    - `utils/userFriendlyErrors.ts`, `utils/fieldLabels.ts` — the resolution by code and the localized field labels.
    - `composables/useValidation.ts` — the validation messages are now the field-level ones the user sees (`composables/__tests__/useValidation.test.ts`).
    - `services/api.ts` — the API client reads the `code` and the `error_details` of the response.
    - `components/AuthLoginForm.vue`, `stores/auth.ts`, `controllers/authController.ts`, `services/totpService.ts` — the login flow consumes the codes instead of parsing the messages.

## [v0.37.3] - 2026-11-04

### Fixed

- **The email-verification pages now center the card like the rest of the auth flow**: `VerifyEmailPage` and `VerifyEmailPendingPage` wrapped `AuthCard` in an `ion-grid` / `ion-row` / `ion-col`; the column did not fill the viewport, so the card rendered inside a half-width column with its background clipped and the glass box off-center. Both pages now mount `AuthCard` directly inside `ion-content`, matching the login and register screens.
    - `views/VerifyEmailPage.vue`, `views/VerifyEmailPendingPage.vue` — the grid wrapper and its `IonGrid` / `IonRow` / `IonCol` imports are gone.
- **The OAuth consent screen renders its header through `AuthCard`, so the branding has a single owner**: the page overrode the whole `#header` slot and duplicated the logo markup and styles, which made the branding (custom logo) depend on a second, copy-pasted implementation.
    - `components/AuthCard.vue` — new `#subtitle` slot: a host can replace the static subtitle with rich content (e.g. the client app name) without replacing the whole header, so `AuthCard` keeps rendering the logo.
    - `views/OAuthAuthorizationPage.vue` — the `#header` override, the duplicated `useBranding` call and the duplicated logo CSS are gone; the client name is now passed through the `#subtitle` slot.
- **The frontend test suite now runs on the LTS node**: Node >= 24 exposes a global `localStorage` that is unavailable unless the runtime starts with `--localstorage-file`, which broke 20 tests in 4 files on the `lts` node. The suite installs a working storage in its setup file, so `pnpm run test:unit` passes on the `node = "lts"` toolchain with no extra flags.
    - `tests/unit/setup.ts` (new) — Vitest setup file: reuses the jsdom storage when it is usable and falls back to a minimal in-memory `Storage`.
    - `vite.config.ts` — `test.setupFiles` points at that setup file.

## [v0.37.2] - 2026-10-25

### Fixed

- **Step 2 of login now captures `passkey_token` from the backend when the account has both TOTP and passkeys, and offers both methods as equal choices**. The passkey ceremony is bound to the password step via the single-use pending token.
    - `components/AuthLoginForm.vue` — captures `passkey_token` from the `TOTP_REQUIRED` response, renders the passkey button when `pendingPasskeyToken` exists, and resets TOTP challenge state on user change.

## [v0.37.0] - 2026-10-25

### Changed

- **The login's second step now defaults to a passkey (LBDS-304)**: when an account has both a passkey and a code-based second factor, step 2 now offers the passkey ceremony as the primary action and demotes the TOTP challenge behind an "Usar otro método" escape, instead of splitting both methods across a segment control. Accounts with no passkey go straight to the TOTP challenge; accounts with a passkey and no TOTP are unaffected.
    - `components/AuthLoginForm.vue` — step-2 state reworked from a segment control (`Contraseña | Passkey`) to a single-view model with `twoFactorView: 'passkey' | 'totp' | null`; the TOTP challenge is rendered inline in the card instead of in a modal.
    - `components/AuthLoginForm.vue` — the secondary links ("¿Olvidaste tu contraseña?", "← Cambiar de usuario") moved out of the card body into a new `#below` slot on `AuthCard`, so they sit directly under the glass box instead of inside it.
    - `components/AuthCard.vue` — new `below` slot and `below` prop; when content is rendered below the card its bottom padding shrinks so no dead band appears, and `flex: 0 0 auto` + `min-height: min-content` stop `ion-card`'s paint containment (`contain: content`) from clipping the last line of the form.
    - `views/LoginPage.vue` — the step-2 action block, the 250 ms step-polling timer and the `defineExpose` / `step-change` plumbing are gone; the form owns its own step state.

## [0.26.0] - 2026-10-11

### Added

- **TOTP (RFC 6238) 2FA fallback UI (LBDS-8)**: settings surface in Perfil → Seguridad lets users enroll (QR + 6-digit confirmation), see remaining backup codes, regenerate (re-auth), and disable (re-auth). The login challenge modal opens after the password step when the user has TOTP enabled, accepts a TOTP code OR a backup code, and auto-focuses the active input. A new `TOTP_REQUIRED` error shape flows from `/api/login` and `/api/login/advanced/verify` through the Pinia store to the modal; the second step posts to `/api/totp/login` to complete the login. Advanced (ZKE) users get `auth_proof` derived client-side via `cryptoService.deriveKeyA` + `computeAuthProof` for disable and regenerate.
    - `services/totpService.ts` (new) — typed HTTP wrapper for `/api/totp/*`.
    - `composables/useTotpEnrollment.ts` (new) — enrollment state composable with `start`/`confirm`/`setBackupCodes`/`reset`. Guarded against re-entrant calls so a rapid double-click does not fire two enrollment requests.
    - `components/user/TotpSettings.vue` (new) — enrollment QR, backup-codes display + download + copy, disable/regenerate modals with re-auth (password for basic, derived `auth_proof` for advanced).
    - `components/AuthLoginForm.vue` — TOTP challenge modal with auto-focus on open and on segment switch, plus the catch-block that opens the modal when `/login` returns `code: 'TOTP_REQUIRED'`.
    - `controllers/authController.ts` + `stores/auth.ts` — `loginWithTotp()` and the typed `TOTP_REQUIRED` error throw path.
    - `views/user/ProfilePage.vue` — `<TotpSettings />` mounted in the Security tab.
    - `package.json` + `pnpm-lock.yaml` — new dep `qrcode` (and `@types/qrcode` in dev) for the enrollment QR.
- **TOTP tests** (11): `composables/__tests__/useTotpEnrollment.test.ts` covers start/confirm/reset, error paths, non-Error throws, the re-entrancy guard, and the `setBackupCodes` setter.
- **Documentation: hardening snapshot at `v0.35.0` (LBDS-249)**: new `docs/architecture/security.md` documents the security posture of the stack — authentication, transport hardening, token binding (DPoP), JWT hardening, rate limits, validation, observability — with explicit deferral decisions for LBDS-258 (DPoP ECDSA P-256), LBDS-257 (Ed25519 Passkeys), and LBDS-259 (mTLS, regulatory-only). Internal tracking notes for LBDS-249 are not part of this repository.

### Fixed

- **Passkey login copy mentions QR / phone and Firefox fallback (LBDS-225)**: `front/src/components/AuthLoginForm.vue` — the passkey hint used to say "Usá tu huella, rostro o llave de seguridad" (implying a local authenticator). The copy now also mentions that a browser without a local passkey can scan a QR with the phone in Chrome/Safari, and that Firefox users fall back to the platform passkey or password. Aligns the in-product copy with the new backend behavior that forwards the credential's stored transports (including `hybrid`) on `allowCredentials`.

## [0.25.0] - 2026-09-27

> **Note:** v0.25.0 corresponds to the v0.35.0 monorepo tag (2026-09-27). The monorepo tag was cut without bumping the frontend `package.json` or versioning this changelog; entries below were in `[Unreleased]` at the tag point. `front/package.json` is now bumped to `0.26.0` (see below) to align with the v0.36.0 monorepo tag.

### Added

- **Friendly copy for the CORS origin-mismatch rejection (LBDS-261)**: the friendly-error catalog now maps the backend's `redirect_uri`/`allowed_origins` mismatch to its own ES/EN message, so a misconfigured OAuth client shows "La aplicación no está bien configurada para iniciar sesión" instead of the generic "Ocurrió un error inesperado". The match requires both tokens (`allowed_origins` and `redirect_uri`) so it never swallows a plain field-validation error for the `allowed_origins` form field.

### Fixed

- **Duplicate API error notification (LBDS-267)**: form errors used to fire both a toast and an inline `error.value` block at the same time, so users saw the same message twice. `handleApiError` is now a pure translator — it returns the friendly copy and logs the raw error to the console — and the caller decides the channel: inline for form errors, an explicit `showError({ message: ... })` toast for actions without a form. All twenty-five call sites across the frontend were aligned to this contract. `console.error` keeps the raw backend detail for developers (LBDS-232 invariant preserved). No backend changes.

## [0.24.4] - 2026-09-10

### Fixed

- Logout page validates its return target before rendering or navigating: only a custom app scheme or an http(s) URL on the same origin is accepted, so an attacker-supplied `redirect_uri` can no longer turn the page into an open redirect or phishing hop on the SSO origin. A "Volver a la app" fallback button remains, because system browsers may block non-gesture cross-scheme navigation.

## [0.24.3] - 2026-09-08

### Fixed

- **Login page high CPU usage (LBDS-202)**: `AuthCard` background blobs are now static. The three infinite `blur(80px)` float animations (plus `will-change`) forced the compositor to re-rasterize large layers every frame while the login page sat idle, making the whole browser/PC feel sluggish. Same visual design, ~zero idle CPU cost; entry animation and `prefers-reduced-motion` handling unchanged.

    - **User-friendly bilingual error messages (LBDS-232)**: new `utils/userFriendlyErrors.ts` catalog maps known backend/validator messages to ES/EN copy with `navigator.language` detection (`fieldLabelsEn` included); `useValidation.ts` toasts and inline errors show friendly text only while `console.error` keeps the raw payload; `api.ts` preserves the `errors{}` shape on thrown errors (previously dropped, leaving the validation branch dead); unknown messages fall back to a generic friendly string; regression tests in `__tests__/useValidation.test.ts` (catalog, fallback, locale, 422-object, malformed shapes).
