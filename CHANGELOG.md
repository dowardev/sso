# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-05-06

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

## [0.1.0] - 2026-05-06

### Added
- Complete theme system with 5 themes: Auto, Light, Dark, Catppuccin Latte, Catppuccin Macchiato
- Auto theme mode that follows system preference with customizable light/dark sub-selectors
- Configuration menu item in sidebar with Personalización link
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
