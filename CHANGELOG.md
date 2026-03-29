# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - 2026-03-29

### Added
- New Ionic/Vue frontend application (`front/`)
- SSO client demo files for testing integrations (`sso-client/`)
- Opencode configuration file for AI-assisted development

### Restructuring
- Moved Laravel backend to dedicated `back/` directory
- Project now uses monorepo structure with separate frontend and backend

### Breaking Changes
- Project directory structure changed - backend is now in `back/` subdirectory

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
- Production environment template (`.env.example.production`) with complete configuration
- Comprehensive deployment checklist (`DEPLOYMENT_CHECKLIST.md`)

### Changed
- Updated README.md with English documentation
- Added production configuration section with domain examples

## [0.0.1] - 2026-01-16

### Added
- Initial SSO Server implementation
- User registration, login, logout, profile endpoints
- Laravel Sanctum integration for API token authentication
