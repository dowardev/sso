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