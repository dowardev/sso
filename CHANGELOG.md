# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.19.0] - 2026-05-27

### Features

- Add delete confirmation dialog (`ion-alert`) before removing an SSO client in the admin panel
- Add inline delete button (trash icon) next to client status badge for quick access

### Refactor

- Split `deleteClient` into `confirmDelete` + `deleteClient` two-step flow with alert state management

### Affected Files

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
- Simplified `AuthController.me()` and `checkSession()`: removed `client_id` requirement and per-client role filtering
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

