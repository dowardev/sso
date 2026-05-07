# Changelog

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