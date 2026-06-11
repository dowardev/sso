# Changelog

## [0.19.1] - 2026-06-11

### Fixed
- Sidebar no longer appears on auth pages (login, register, setup) when authenticated
- Login route redirect now awaits completion, preventing a flash of the unauthenticated state

### Changed
- Sidebar visibility computed uses `route.meta.requiresAuth` instead of hardcoded path strings

---

## [0.19.0] - 2026-05-27

### Added
- **Delete Confirmation Dialog**: Added `ion-alert` confirmation before deleting an SSO client in admin clients page (`ClientsPage.vue`), preventing accidental deletions
- **Inline Delete Button**: Added trash icon button next to each client's status badge for quick access, alongside the existing swipe-to-delete option

### Changed
- **Refactored delete flow**: Split `deleteClient` into `confirmDelete` (sets alert state) and `deleteClient` (performs API call), using reactive `showDeleteAlert` and `clientToDelete` refs with alert button handlers

## [0.18.3] - 2026-05-26

### Fixed
- **Infinite 401 Loop on Logout**: Prevented recursion when `/logout` endpoint itself returns 401
  - Added `endpoint !== '/logout'` guard in `fetchApi` 401 interceptor (`services/api.ts`) to exclude the logout call from triggering another logout cycle
  - Reordered `logout()` in auth store to clear local auth data and DPoP keys before calling the remote `/logout` API, ensuring immediate state cleanup regardless of server response

## [0.18.2] - 2026-05-26

### Added
- **Password Visibility Toggle**: Added `<ion-input-password-toggle>` to password fields in admin login (`LoginPage.vue`) and application login (`PublicLoginPage.vue`), allowing users to show/hide their password

## [0.18.1] - 2026-05-26

### Fixed
- **Session Not Closing on Token Expiry**: JWT expiry detection across multiple layers
  - Added 401 status interceptor in `fetchApi` (`services/api.ts`) that auto-logs out and redirects to `/login` on expired token responses
  - Added `isTokenExpired()` helper in auth store that decodes JWT payload to verify `exp` claim against current time
  - Updated `isAuthenticated` computed to return `false` for expired tokens regardless of string existence
  - Updated `init()` to discard expired tokens restored from localStorage on page refresh
  - Extracted `clearAuthData()` helper in auth store to eliminate duplication in `logout()` and `init()`

### Added
- **Periodic Session Health Check**: App.vue now calls `/session/check` every 5 minutes via `checkSession()` in authController, auto-logging out if the server reports an unauthenticated session
- **package.json**: Fixed trailing comma JSON syntax error preventing `pnpm run build`

## [0.4.0] - 2026-05-06

### Added
- **Template Structure**: Enforced consistent Vue component template structure across all 13 components
  - Added `ion-header` to page components (LoginPage, RegisterPage, PublicLoginPage, PublicRegisterPage)
  - Added comment-delimited sections: `<!-- content -->`, `<!-- modals -->`, `<!-- popovers -->`, `<!-- alerts -->`
  - Components: App.vue, LoginPage.vue, RegisterPage.vue, PublicLoginPage.vue, PublicRegisterPage.vue, DashboardPage.vue, UsersPage.vue, SessionsPage.vue, ClientsPage.vue, RolesPage.vue, UserDetailPage.vue, DesignPage.vue

- **Script Section Ordering**: Implemented standardized script section ordering with delimiters
  - Sections: imports, controllers, components, interface and types imports, main, vars and const, watch, computed, onmounted, functions
  - All 13 Vue components now follow the same structure

### Changed
- **Named Parameters Pattern**: Applied to all functions in Vue components
  - Functions with 2+ parameters now use single params object pattern
  - Fixed template-to-script consistency - all function calls in templates now use named params
  - Example: `openEditModal(client)` → `openEditModal({ client })`

- **Code Cleanup**:
  - Removed dead code in RegisterPage.vue and PublicRegisterPage.vue (orphaned api.post calls, duplicate try-catch blocks, undefined references)
  - Fixed missing declarations in UserDetailPage.vue (added showRolesModal and savingRoles)
  - Moved function references (deleteRole, deleteUser) before their usage in alert buttons

### Fixed
- **Runtime Error**: Fixed `TypeError: can't access property "split", name is undefined` in App.vue getInitials function

### Notes
- Follows TemplateView.vue guide from guides/ directory
- All changes maintain backward compatibility
- ESLint warnings for formatting (prettier) remain - not affecting functionality

## [0.3.0] - 2026-05-06

### Added
- **Controllers**: Added 6 new controller modules for centralized business logic
  - `authController.ts` - Authentication and OAuth flow encapsulation
  - `usersController.ts` - User management operations
  - `sessionsController.ts` - Session management with helper functions
  - `clientsController.ts` - OAuth client management
  - `rolesController.ts` - Role management
  - `themeController.ts` - Theme system wrapper

- **Template Guide**: Added `guides/TemplateView.vue` as reference for Vue component structure

### Changed
- **Architecture**: Implemented Controller-Only Communication pattern across all Vue components
  - 13 components now use controllers instead of accessing stores/services directly
  - Components: App.vue, LoginPage.vue, RegisterPage.vue, PublicLoginPage.vue, PublicRegisterPage.vue, DashboardPage.vue, UsersPage.vue, SessionsPage.vue, ClientsPage.vue, RolesPage.vue, UserDetailPage.vue, DesignPage.vue, ThemePopover.vue

- **Named Parameters Pattern**: Refactored TypeScript functions to use single params object
  - `api.ts`: fetchApi, api.get, api.post, api.put, api.delete
  - `dpopService.ts`: createDpopProof
  - `theme.ts`: getStoredAutoTheme
  - `stores/roles.ts`: updateRole
  - `stores/users.ts`: updateUser, saveUserRoles
  - `stores/clients.ts`: updateClient

### Notes
- This version introduces architectural patterns from AGENTS.md
- All refactoring maintains backward compatibility
- ESLint errors remain for formatting (prettier, return types) - not affecting functionality