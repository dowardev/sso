# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.0] - 2026-05-02

### Added
- PKCE (Proof Key for Code Exchange) support for OAuth2 authorization code flow
- `code_challenge` and `code_challenge_method` fields in OAuth authorization codes
- `code_verifier` validation in token endpoint with S256 and plain challenge methods
- Database migration to add PKCE fields to `oauth_auth_codes` table
- `computeCodeChallenge` private method for PKCE verification

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

## [0.6.1] - 2026-05-01

### Fixed
- Custom validation messages for `client_id` in register and login endpoints providing clearer error feedback

## [0.6.0] - 2026-05-01

### Added
- IonItemSliding component support in UserDetailPage for enhanced UI interactions

### Removed
- Root opencode.json configuration file (cleanup)

### Changed
- Package.json removed from root (workspace structure refactoring)

## [0.5.0] - 2026-05-01

### Added
- Role-based access control (RBAC) system with Role model, migrations, and seeder
- Role management CRUD endpoints (index, create, update, delete) with protected role guards
- User-role assignment with per-application (SSO client) scoping via pivot table
- Frontend roles management page (RolesPage.vue) with create/edit/delete modals
- Role assignment UI in user detail page with per-application scope selection
- Roles menu item in admin sidebar and route configuration
- TypeScript interfaces for Role and UserRole in API service

### Changed
- User profile endpoint now loads associated roles
- User model gains roles relationship and helper methods (hasRole, getEffectiveRole, isAdmin)
- Database seeder now includes default system roles (admin, moderator, standard)

## [0.4.0] - 2026-03-29

### Added
- Username-based authentication: login now accepts either `email` or `user` (username) field
- Optional `client_id` parameter for login endpoint - sessions can be created without specifying a client
- Complete frontend admin panel with:
  - Dashboard page with overview statistics
  - Users management (list, detail view, search)
  - Clients management (CRUD)
  - Sessions management (view and revoke)
- New API service and Auth service in frontend for centralized API calls

### Changed
- Login validation now allows either email or username
- Made `client_id` nullable in login endpoint
- Session registration now handles null client gracefully

### Breaking Changes
- None

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
