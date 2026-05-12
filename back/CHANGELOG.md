# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-05-12

### Added
- `/auth/logout` page for OAuth redirect logout flow
- LogoutPage.vue component with auto-redirect based on redirect_uri query param
- Credentials support for cross-origin API requests (credentials: 'include')

### Fixed
- Remove duplicate CORS middleware, use Laravel CORS config only
- CORS now uses specific origin instead of wildcard (required for credentials)
- Logout endpoint accepts cookie fallback and properly deletes session
- Logout clears sso_token cookie on client side

### Changed
- Simplify role system: roles are now global-only, client-specific roles removed
- Removed sso_client_id pivot column from user_roles table
- Updated AGENTS.md with commit policy and composables support

### Removed
- Custom CORS middleware (Middleware/Cors.php)
- CORS middleware registration from bootstrap/app.php

## [0.2.0] - 2026-03-29

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

## [0.1.0] - 2026-03-27

### Added
- User 'user' field (username) to User model for username-based authentication
- Username generation in UserFactory using faker with unique constraint
- Test user seed data with username field in DatabaseSeeder

### Changed
- Updated DatabaseSeeder to include username for test user

## [0.0.2] - 2026-01-17

### Added
- Production environment template (`.env.example.production`) with complete configuration for database, sessions, Redis, mail, and Sanctum
- Comprehensive deployment checklist (`DEPLOYMENT_CHECKLIST.md`) covering:
  - DNS and domain configuration
  - SSL certificate setup with Let's Encrypt
  - Server configuration (PHP, MySQL, Nginx)
  - SSO Server deployment procedures
  - Backend deployment procedures
  - Frontend deployment options (Vercel/Netlify or custom server)
  - CORS and security configuration
  - Verification and testing procedures
  - Maintenance scripts (backups, logs, updates)
  - Troubleshooting guide

### Changed
- Updated README.md with English documentation
- Added production configuration section with domain examples
- Replaced localhost references with production domain placeholders
- Improved architecture diagram with production URLs
- Added deployment checklist reference in main documentation

## [0.0.1] - 2026-01-16

### Added
- Initial SSO Server implementation
- User registration endpoint
- User login endpoint
- User logout endpoint
- User profile endpoint (authenticated)
- Laravel Sanctum integration for API token authentication
