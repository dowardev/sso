# Changelog

All notable changes to the frontend (`front/`, Ionic Vue 8) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Login page high CPU usage (LBDS-202)**: `AuthCard` background blobs are now static. The three infinite `blur(80px)` float animations (plus `will-change`) forced the compositor to re-rasterize large layers every frame while the login page sat idle, making the whole browser/PC feel sluggish. Same visual design, ~zero idle CPU cost; entry animation and `prefers-reduced-motion` handling unchanged.

    - **User-friendly bilingual error messages (LBDS-232)**: new `utils/userFriendlyErrors.ts` catalog maps known backend/validator messages to ES/EN copy with `navigator.language` detection (`fieldLabelsEn` included); `useValidation.ts` toasts and inline errors show friendly text only while `console.error` keeps the raw payload; `api.ts` preserves the `errors{}` shape on thrown errors (previously dropped, leaving the validation branch dead); unknown messages fall back to a generic friendly string; regression tests in `__tests__/useValidation.test.ts` (catalog, fallback, locale, 422-object, malformed shapes).
