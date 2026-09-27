# Changelog

All notable changes to the frontend (`front/`, Ionic Vue 8) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
