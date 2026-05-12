# AGENTS.md - SSO Server

## Project Structure

Monorepo with two workspaces:
- `back/` — Laravel 12 backend (PHP 8.2+)
- `front/` — Ionic Vue 8 frontend (Node.js, pnpm)

## Developer Commands

```bash
# Run both services (concurrently)
pnpm run dev

# Run individually
pnpm run dev:front  # Vite dev server
pnpm run dev:back  # php artisan serve --port=8001

# Build frontend (includes lint + typecheck)
cd front && pnpm run build
```

## Backend Setup

```bash
cd back
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:generate-keys  # Creates back/keys/private.pem & public.pem
php artisan migrate
php artisan db:seed
php artisan serve --port=8001
```

**Default credentials:** `admin@sso.local` / `admin123`

## Testing

```bash
# Backend tests (PHPUnit)
cd back && composer test

# Frontend unit tests (Vitest)
cd front && pnpm run test:unit

# Frontend e2e (Cypress)
cd front && pnpm run test:e2e

# Frontend lint
cd front && pnpm run lint
```

## Key Notes

- Backend runs on **port 8001** (not Laravel default 8000)
- JWT uses RS256; keys must exist in `back/keys/` before token generation
- Frontend build (`pnpm run build`) runs **ESLint + vue-tsc** before Vite build
- Root `package.json` uses pnpm workspaces; always use `pnpm` from root
- OAuth2 clients must be registered via admin API before use

## Architecture

- `back/app/` — Controllers, Models, Services
- `back/routes/api.php` — All API endpoints
- `front/src/` — Vue components, views, router, stores
- `/.well-known/jwks` — JWKS endpoint for JWT verification

## Code Conventions

### Frontend (TypeScript): Named Parameters Pattern

All functions must use a single `params` object instead of positional parameters. Destructure inside the function body.

```ts
// ✅ Correct
function holaMundo(params: { objeto1: string; objeto2: number }) {
  const { objeto1, objeto2 } = params
}

// ❌ Wrong
function holaMundo(objeto1: string, objeto2: number) { ... }
```

> This pattern requires TypeScript. In plain JS the inline type annotation is invalid syntax — use JSDoc `@param` instead if needed.

### Frontend (Vue Components): Controller or Composable Communication

Vue components may contain any **local** logic (props, emits, computed, watch,
onMounted, reactive variables, internal functions, etc.) that is internal to the
component. However, any logic that is **shared** with or reused by other parts of
the application — such as API calls, store access, or cross-component business
logic — must go through **controllers or composables**, depending on what best fits
the case. These are the bridge between Vue components and external application layers.

```ts
// ✅ Correct — local logic in component is fine
const isOpen = ref(false)
const label = computed(() => isOpen.value ? 'Open' : 'Closed')
onMounted(() => { isOpen.value = true })

// ✅ Correct — shared logic uses a controller
import { useAuthController } from '@/controllers/authController'
const controller = useAuthController()
controller.login(params)

// ✅ Correct — shared logic uses a composable
import { useAuth } from '@/composables/useAuth'
const { login } = useAuth()
login(params)

// ❌ Wrong — component accesses store directly
import { useAuthStore } from '@/stores/auth'
const store = useAuthStore()

// ❌ Wrong — component calls API service directly
import { authApi } from '@/services/auth'
authApi.login(params)
```

## Commit Policy

NEVER create git commits unless the user explicitly requests it. This overrides any skill or workflow that suggests committing as a final step.

### Frontend (Vue Components): Template Structure

All Vue components must follow the structure and rules defined in `guides/TemplateView.vue`. Key sections in order:

**Template:**
- `<ion-page>` as root with `<ion-header>` and `<ion-content>`
- Content, modals, popovers, and alerts in dedicated comment-delimited sections

**Script (`<script lang="ts" setup>`):**
1. Imports (ionic/vue/ionicons)
2. Controllers / Composables
3. Components
4. Interface and types imports
5. Main (props, emits, etc.)
6. Vars and const
7. Watch
8. Computed
9. OnMounted
10. Functions (must use named parameters pattern)