# AGENTS.md - SSO Server

## Project Structure

Monorepo with workspaces:
- `back/` — Laravel 12 backend (PHP 8.2+)
- `front/` — Ionic Vue 8 frontend (Node.js, pnpm)
- `packages/sso-client/` — TS library `@dowardev/sso-client` (OAuth2 PKCE + DPoP + axios)
- `packages/sso-server/` — PHP library `dowardev/sso-server` (Laravel, DPoP verification)

## Developer Commands

```bash
# Run both services (concurrently)
pnpm run dev

# Run individually
pnpm run dev:front  # Vite dev server
pnpm run dev:back  # php artisan serve --port=8001

# Build frontend (includes lint + typecheck)
cd front && pnpm run build

# Build sso-client library
cd packages/sso-client && pnpm run build
```

## Setup Script

A single `./setup.sh` script at repo root handles the entire setup process:

```bash
# Interactive (recommended for first time)
./setup.sh

# Development mode (.env.development.example)
./setup.sh --dev

# Non-interactive (uses SSO_* env vars)
SSO_DB_DATABASE=myapp ./setup.sh --non-interactive

# Skip backups
./setup.sh --no-backup

# Skip frontend build
./setup.sh --skip-front-build
```

The script:
- Detects hosting type (VPS vs shared)
- Copies the correct `.env` template
- Generates APP_KEY, JWT keys
- Runs migrations and seeders
- Configures the frontend `.env`
- Validates everything with healthchecks

See `.opencode/plans/2026-07-24-setup-script-design.md` for the full design spec.

## Backend Setup

```bash
cd back
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:generate-keys  # Creates back/keys/private.pem & public.pem
php artisan migrate
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=SettingSeeder
php artisan serve --port=8001

> **First admin:** created via the frontend setup wizard at `/setup` on first access (blocked by `EnsureSetupNotCompleted` middleware once any user exists). `AdminUserSeeder` is intentionally omitted — use it only for manual dev seeding.

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
- `packages/sso-client/src/` — OAuth2 PKCE + DPoP client library source
- `packages/sso-server/src/` — DPoP verification, JWT validation, middleware
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

Vue components may contain any **local** logic (props, emits, computed, watch, onMounted, reactive variables, internal functions, etc.) that is internal to the component. However, any logic that is **shared** with or reused by other parts of the application — such as API calls, store access, or cross-component business logic — must go through **controllers or composables**, depending on what best fits the case. These are the bridge between Vue components and external application layers.

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

### Frontend (Vue Components): Template Structure

There are two distinct template patterns depending on the file type: **Pages** (route-level views) and **Components** (reusable UI blocks). Both must follow the numbered section structure in the script.

#### Pages (`src/views/`)

Pages are route-level views. Root element is `<ion-page>` with `<ion-header>` and `<ion-content>`.
Reference: `guides/TemplatePage.vue`

```vue
<template>
  <ion-page>
    <ion-header>...</ion-header>
    <ion-content>
      <ion-grid>
        <ion-row class="ion-justify-content-center">
          <ion-col size="4"><!-- content --></ion-col>
        </ion-row>
      </ion-grid>
    </ion-content>
    <!-- popovers -->
    <!-- alerts -->
    <!-- modals -->
  </ion-page>
</template>
```

#### Components (`src/views/**/components/` or `src/components/`)

Components are reusable blocks. NO `<ion-page>`, NO `<ion-header>`, NO `<ion-content>`.
Reference: `guides/TemplateComponent.vue`

```vue
<template>
  <!-- No ion-page — this is a reusable component -->
  <ion-list class="transparent">
    <ion-card>
      <ion-item lines="full" class="transparent">...</ion-item>
    </ion-card>
  </ion-list>
</template>
```

**Script (`<script lang="ts" setup>`) — same numbered section structure for both:**

```ts
<script setup lang="ts>
// 1. Imports (ionic/vue/ionicons)
//    - Vue: ref, computed, watch, onMounted, etc.
//    - Ionicons: addOutline, chevronBackOutline, etc.
//    - Utilities: date-fns, messages, db, etc.
//    - Ionic: IonButton, IonContent, IonDatetime, etc.
//    - Local packages: @/utils/..., @/store/..., etc.
import { ref, computed, onMounted } from 'vue'
import { addOutline } from 'ionicons/icons'
import { format } from 'date-fns'
import { IonButton, IonContent, IonDatetime } from '@ionic/vue'

// 2. Controllers / Composables
//    - Import and instantiate controllers/composables
import { AuthController } from '@/controllers/authController'
const authController = new AuthController()

// 3. Components
//    - Import child Vue components
import ChildComponent from '@/components/ChildComponent.vue'

// 4. Interface and types imports
//    - ONLY types/interfaces (import type)
import type { User, EventItem } from '@/stores/userStore'
import type { ViewCalendar } from '@/store/calendarStore'

// 5. Main (props, emits, etc.)
defineProps<{ items: Item[] }>()
defineEmits<{ update: [value: string] }>()

// 6. Vars and const
const isOpen = ref(false)
const loading = ref(false)

// 7. Watch
watch(() => props.items, (val) => { /* ... */ })

// 8. Computed
const filtered = computed(() => items.value.filter(...))

// 9. OnMounted
onMounted(() => { loadData() })

// 10. Functions (named parameters pattern)
function loadData(params: { force?: boolean }) { /* ... */ }
</script>
```

**Rules:**
- Each section **must** have its numbered comment (`// 1.`, `// 2.`, etc.)
- Section 1 groups **all** value imports (no types)
- Section 4 is **exclusively** `import type`
- ⚠️ **Ionic imports go in section 1**, never in section 4

### Frontend (Vue Components): Controller vs Composable Decision

Determines when to use a **controller** vs a **composable**:

| Scenario | Use | Example |
|-----------|------|---------|
| Pure business logic (CRUD, calculations, rules) | Controller | `accountsController.getAccounts()` |
| Reactive state + logic (ref, computed, watch) | Composable | `useAccountSelection({ items, getAccountId })` |
| Pinia store access + logic | Controller or Composable | `budgetDayStore.saveBudgetData()` in controller |
| Shared logic across multiple components | Composable | `useAttachments`, `useDatePicker` |
| Operations that do NOT need Vue state | Controller | `budgetEntriesController.getEntries()` |
| Operations that DO need reactive state | Composable | `useDeleteConfirm({ onConfirm })` |

**General rule:** If you need `ref`, `computed`, `watch`, or `onMounted` → **composable**.
If you only need pure functions or data access → **controller**.

---

### Frontend (Vue Components): Ionic Imports

All Vue components that use Ionic components in their template **must explicitly import** those components in **section 1** (`// 1. Imports (ionic/vue/ionicons)`) of the script.

```ts
// ✅ Correct — imports what the template uses in section 1
import { IonButton, IonContent, IonDatetime, IonPopover } from '@ionic/vue'

// ❌ Incorrect — uses IonDatetime in template but does not import it
// (TypeScript cannot infer types: $el is never, methods reset/confirm/cancel do not exist)

// ❌ Incorrect — importing Ionic in section 4 (types)
```

> Ionic Vue registers components globally via `app.use(IonicVue)`, but TypeScript needs the import in **section 1** to correctly infer types (e.g. `InstanceType<typeof IonDatetime>`, access to `$el`, methods like `reset()`, `confirm()`, `cancel()`).

### Frontend (Ionic): Ionic-First Layout

Prefer Ionic layout utilities over custom CSS:

- `ion-grid` / `ion-row` / `ion-col` for responsive layout (never manual flexbox for page layout)
- Utility classes: `ion-padding-*`, `ion-margin-*`, `ion-text-*`, `ion-justify-content-*`, `ion-align-items-*`
- `ion-list` with `ion-item` for forms instead of `<div>` with CSS
- `ion-card` for cards
- Modals: `ion-modal` with `ion-header` + `ion-content`
- Popovers: `ion-popover` with `ion-content`
- Do NOT use `<div>` with manual styles when an equivalent Ionic component exists

## Release Conventions

Tags use standard semver format `vX.Y.Z` in both the monorepo and the mirror
repo `gitlab.com/dowardev/sso-server`.

## Package Registries

| Package | Registry | Source | Status |
|---|---|---|---|
| `@dowardev/sso-client` | npm | `packages/sso-client/` (published from branch) | Published @0.0.1 |
| `dowardev/sso-server` | Packagist | mirror `gitlab.com/dowardev/sso-server` (built via `git subtree split --prefix=packages/sso-server`) | Manual publish (last: v0.1.0) |

### Publishing sso-client (npm)

```bash
pnpm run build:sso-client
cd packages/sso-client && npm publish --access public && cd ../..
git add packages/sso-client/package.json
git commit -m "release(sso-client): vX.Y.Z"
```

### Publishing sso-server (Packagist)

```bash
git subtree split --prefix=packages/sso-server -b sso-server-split
git push sso-server-mirror sso-server-split:main
git tag -a vX.Y.Z sso-server-split -m "Release vX.Y.Z"
git push sso-server-mirror vX.Y.Z
```

See `DEPLOY.md` for the full procedure with verification steps.

## Commit Policy

NEVER create git commits unless the user explicitly requests it. This overrides any skill or workflow that suggests committing as a final step.

## Ticket / Issue Policy

NEVER create or edit Linear tickets (or any external issue/ticket) without the user explicitly asking for it. Creating a ticket is a mutation an agent must never do on its own initiative — even when a finding, follow-up, or proposal seems obvious. If you think a ticket is warranted, propose it in the conversation and ask the user first; only create it after an explicit request. This also covers branch names derived from tickets: do not branch off a ticket that was not requested.

This rule exists because tickets (unlike code) are project-planning artifacts the user owns: content, priority, and even whether an item deserves a ticket are the user's decisions, not the agent's. The same applies to changelog/package edits that imply a release decision — propose, don't act.

## Pi lifecycle command guard (gentle-ai)

`git commit` / `git push` / `glab mr ...` desde el bash tool se bloquean si el
comando tiene `&&`, `;`, `|`, backtick o `$(` (reporte: "Compound or wrapped
lifecycle command ... must fail closed"). NO tiene que ver con RDD; RDD off no
bloquea. El guard es stateless: cada comando se evalúa de forma aislada, no queda
"trabado" tras un bloqueo.

Fix: un solo comando directo, sin `&&` ni `cd`, preferiblemente
`git -C <ruta-absoluta> <subcommand> -m "mensaje de una sola linea"`.
IMPORTANTE: el mensaje del `-m` debe ser UNA sola linea (sin saltos de linea);
un cuerpo multilinea introduce un `\n` sin comillar que dispara el mismo bloqueo.
Si un comando "simple" sigue bloqueando, no es estado persistido: tiene algun
control-char sin comillar (`;`, `|`, `&`, backtick, `$(` o `\n`) en la linea.

## Formato HTML / Vue / Ionic (legibilidad para review)

- Dejá UNA línea en blanco entre cada par de elementos hermanos, en TODOS los niveles de anidación (no solo primer nivel).
- No agregues línea en blanco justo después de la apertura ni justo antes del cierre, solo ENTRE hermanos.

Ejemplo correcto:

```vue
<ion-item>
  <ion-label>Título</ion-label>

  <ion-button>Guardar</ion-button>
</ion-item>
```

Ejemplo incorrecto (sin saltos):

```vue
<ion-item>
  <ion-label>Título</ion-label>
  <ion-button>Guardar</ion-button>
</ion-item>
```
