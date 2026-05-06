# Changelog

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