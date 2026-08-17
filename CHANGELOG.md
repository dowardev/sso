# Changelog

All notable changes to `dowardev/sso-server` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
