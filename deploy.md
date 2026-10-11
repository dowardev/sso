# SSO Server - Deployment Guide

<!-- markdownlint-disable MD013 MD060 -->

> This file is both the deployment guide and the deploy log. Newest deploy entries are appended to the **Deploy Log** section below. Everything else is the guide.

## Deploy Log

### v0.36.0 — 2026-10-11

- **Environment**: production
- **Source tag**: `v0.36.0`
- **Status**: shipped
- **Per-package versions**:
  - root: 0.34.4 → **0.36.0**
  - `front`: 0.24.4 → **0.26.0**
  - `back`: first versioned → **0.2.0** (`composer.json` has no `version` field; git tag is the source of truth)
  - `@dowardev/sso-client`: 0.3.0 → **0.4.0**
  - `dowardev/sso-server`: 0.1.2 → **0.2.0**
- **Migration notes**:
  - **TOTP enrollment is opt-in** — existing users are not affected until they enroll via Perfil → Seguridad. New TOTP endpoints require no migration; tables `totp_credentials` and `totp_backup_codes` are created by the new migrations.
  - **DPoP refresh binding is enforced** for new sessions; existing OAuth sessions must log in once to re-bind.
  - **`SESSION_SAME_SITE` template bumped from `lax` → `strict`** in `back/.env.production.example`. If your production env explicitly set `SESSION_SAME_SITE=lax`, update it to `strict` (or leave it; the runtime will treat blank/non-value as the safe default).
  - **`SESSION_SECURE_COOKIE=true`** is still required for the `Secure` flag to apply; verify the prod env is not blank.
  - **JWT `kid` header** is now stamped on every issued JWT. Consumers that don't read `kid` keep working; consumers that do must call `/.well-known/jwks` and resolve by `kid` (the package's `JwksFetcher::getPublicKeyPemByKid` does this for sso-server embeddings).
  - **DPoP allowlist now configurable at runtime** via `DPOP_ALLOWED_ALGS=ES256` (sso-server) / `DPOP_ALLOWED_ALGS=ES256` (Laravel). Default is still `['ES256','RS256']`. Sanity check the env value after the deploy — a typo is sanitised and logged as a `Log::warning` at boot.
  - **Migrations to run on the next `php artisan migrate`**: `2026_10_08_000000_create_totp_credentials_table`, `2026_10_08_000001_create_totp_backup_codes_table`, `2026_10_09_000000_add_composite_index_to_totp_backup_codes_table`, `2026_09_19_000000_add_dpop_jkt_to_user_refresh_tokens_table`.
- **Rollback notes**:
  - The release is a MINOR bump; the migrations are additive and forward-compatible (new tables, nullable column on `user_refresh_tokens`). Rolling back to v0.34.4 is safe data-wise: only the TOTP tables and the `dpop_jkt` column are dropped, no data is destroyed. The DPoP `dpopenforced` semantic (LBDS-253) reverts — already-bound refresh tokens will fail to refresh until re-bound.
  - The v0.35.0 retrospective sections in the changelogs are documentation-only; they describe features that were already in v0.35.0, so rolling back v0.36.0 does not change behavior of those features.
  - **TOTP encryption key dependency**: if you have rotated `APP_KEY` since v0.35.0, the encrypted TOTP secrets (post-2026-10-08) will not decrypt under the old key. The `back/CHANGELOG.md` entry on LBDS-8 calls this out as an operational risk; do not rotate `APP_KEY` without re-encrypting the `totp_credentials.secret` column first.

---

Centralized authentication server for distributed applications. Provides OAuth2 authorization code flow, JWT token issuance, and user management.

## Demo

Access the production application at: [SSO Server](https://sso.example.com)

---

## System Architecture

```text
┌─────────────────────────────────────────────────────────────────┐
│                        SSO FRONTEND                             │
│                   localhost:8001                                 │
│                              │                                  │
│   1. User enters credentials                                    │
│   2. Sends credentials to SSO Backend                           │
│   3. Receives session cookie                                     │
│   4. Redirects to client app with authorization code             │
│                              │                                  │
└──────────────────────────────┼──────────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                        SSO BACKEND                              │
│                   localhost:8001                                │
│                              │                                  │
│   1. Validates user credentials                                 │
│   2. Creates user session                                       │
│   3. Issues JWT tokens (RS256)                                  │
│   4. Provides JWKS at /.well-known/jwks                        │
│   5. Handles OAuth2 authorization code flow                     │
│                              │                                  │
└──────────────────────────────┬──────────────────────────────────┘
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                        DATABASE                                 │
│                     MySQL/SQLite                               │
│                              │                                  │
│   - Users table                                                │
│   - Clients table (OAuth2 clients)                              │
│   - Sessions table                                             │
│   - Personal Access Tokens                                      │
└─────────────────────────────────────────────────────────────────┘
```

---

## Tech Stack

### Backend

- Laravel 12
- Laravel Sanctum (OAuth2 tokens)
- lcobucci/jwt (JWT generation & validation)
- MySQL (production) / SQLite (development)

### Frontend

- Vue.js 3
- Ionic Vue 8
- Capacitor
- TypeScript
- Vite
- Pinia (state management)

### External

- OpenSSL (RSA key generation)

---

## Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL (production) / SQLite (development)
- OpenSSL (for RSA key generation)

---

## Project Structure

```text
sso/
├── back/           # Laravel API
├── front/          # Ionic/Vue 8 SPA
├── packages/       # Shared libraries (sso-client, sso-server)
├── DEPLOY.md       # This file
└── README.md       # Main documentation
```

---

## Installation

### 1. Backend

```bash
cd back

# Install dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Generate RSA keys for JWT (auto-creates keys/ if needed)
php artisan jwt:generate-keys

# Configure .env (see Deployment Configuration section)

# Run migrations
php artisan migrate

# Seed database (roles and settings only — first admin via setup wizard)
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=SettingSeeder

# Start server
php artisan serve --port=8001
```

### 2. Frontend

```bash
cd front

# Install dependencies
pnpm install

# Configure environment (development)
cp .env.development.example .env.development

# Start development server
pnpm run dev
```

---

## Deployment Configuration

### Backend (.env)

| Variable                   | Description                      | Example                           |
| -------------------------- | -------------------------------- | --------------------------------- |
| `APP_NAME`                 | Application name                 | `SSO Server`                      |
| `APP_ENV`                  | Environment                      | `production` _(default: `local`)_ |
| `APP_DEBUG`                | Debug mode                       | `false`                           |
| `APP_URL`                  | Backend URL                      | `https://sso.example.com`         |
| `FRONTEND_URL`             | Frontend/panel URL (for CORS)    | `https://panel.sso.example.com`   |
| `BROADCAST_CONNECTION`     | Broadcasting driver              | `log`                             |
| `VITE_APP_NAME`            | Vite app name (frontend)         | `"${APP_NAME}"`                   |
| `APP_LOCALE`               | Application locale               | `es`                              |
| `APP_FALLBACK_LOCALE`      | Fallback locale                  | `es`                              |
| `APP_FAKER_LOCALE`         | Faker locale                     | `es_ES`                           |
| `DB_CONNECTION`            | Database driver                  | `mysql`                           |
| `DB_HOST`                  | MySQL host                       | `[mysql_host]`                    |
| `DB_PORT`                  | MySQL port                       | `3306`                            |
| `DB_DATABASE`              | Database name                    | `[database_name]`                 |
| `DB_USERNAME`              | Database user                    | `[database_user]`                 |
| `DB_PASSWORD`              | Database password                | `[database_password]`             |
| `SESSION_DRIVER`           | Session driver                   | `database`                        |
| `SESSION_LIFETIME`         | Session lifetime (minutes)       | `120`                             |
| `SESSION_ENCRYPT`          | Encrypt session data             | `false`                           |
| `SESSION_SECURE_COOKIE`    | Secure cookie flag               | `true`                            |
| `SESSION_PATH`             | Session cookie path              | `/`                               |
| `SESSION_SAME_SITE`        | SameSite policy                  | `lax`                             |
| `CACHE_STORE`              | Cache driver                     | `database`                        |
| `CACHE_PREFIX`             | Cache key prefix                 | `sso_`                            |
| `JWT_PRIVATE_KEY_PATH`     | RSA private key path             | `keys/private.pem`                |
| `JWT_PUBLIC_KEY_PATH`      | RSA public key path              | `keys/public.pem`                 |
| `JWT_TOKEN_EXPIRATION`     | Token lifetime (seconds)         | `3600`                            |
| `JWT_REFRESH_EXPIRATION`   | Refresh token lifetime (seconds) | `2592000`                         |
| `JWT_REFRESH_INACTIVITY`   | Max inactivity (seconds)         | `604800`                          |
| `JWT_ISSUER`               | JWT issuer identifier            | `https://sso.example.com`         |
| `BCRYPT_ROUNDS`            | Bcrypt hashing rounds            | `12`                              |
| `QUEUE_CONNECTION`         | Queue driver                     | `database`                        |
| `FILESYSTEM_DISK`          | Filesystem disk                  | `local`                           |
| `LOG_CHANNEL`              | Log channel                      | `stack`                           |
| `LOG_STACK`                | Log stack driver                 | `single`                          |
| `LOG_DEPRECATIONS_CHANNEL` | Deprecations log channel         | `null`                            |
| `LOG_LEVEL`                | Minimum log level                | `error`                           |
| `MAIL_MAILER`              | Mail driver                      | `smtp`                            |
| `MAIL_HOST`                | SMTP host                        | `smtp.gmail.com`                  |
| `MAIL_PORT`                | SMTP port                        | `587`                             |
| `MAIL_USERNAME`            | SMTP username                    | `[your_email@example.com]`        |
| `MAIL_PASSWORD`            | SMTP password                    | `[your_password]`                 |
| `MAIL_ENCRYPTION`          | Encryption (tls/ssl)             | `tls`                             |
| `MAIL_FROM_ADDRESS`        | Sender email                     | `noreply@example.com`             |
| `MAIL_FROM_NAME`           | Sender name                      | `SSO Server`                      |

> **Note:** `SANCTUM_STATEFUL_DOMAINS` and `SESSION_DOMAIN` are **not** automatically derived. You must set `SANCTUM_STATEFUL_DOMAINS` to your frontend's host (comma-separated):

### Frontend (.env)

The frontend uses Vite's built-in environment file loading:

| File                       | Mode                      | Purpose                                                   |
| -------------------------- | ------------------------- | --------------------------------------------------------- |
| `.env.development`         | Development (`vite dev`)  | **Actual** local development variables — committed to git |
| `.env.development.example` | Development (`vite dev`)  | Template for local development setup                      |
| `.env.production`          | Production (`vite build`) | **Actual** production variables — **do NOT commit**       |
| `.env.production.example`  | Production (`vite build`) | Template for production deployment                        |

**Development:**

```bash
# Copy and customize environment
cp front/.env.development.example front/.env.development
# Edit .env.development with your local settings

pnpm run dev
```

**Production deployment:**

```bash
# 1. Copy the example file
cp front/.env.production.example front/.env.production

# 2. Edit with your real production URL
# VITE_API_URL=https://sso.example.com/api

# 3. Build
cd front && pnpm run build
```

> **Important:** `.env.production` is gitignored. Make sure to configure it on your server before running the build.

**Frontend variables:**

| Variable       | Description          | Example                       |
| -------------- | -------------------- | ----------------------------- |
| `VITE_API_URL` | Backend API base URL | `https://sso.example.com/api` |

---

## Web Server Configuration

The frontend is an SPA — configure the **frontend** web server (where the built `dist/` is served) to redirect all requests to `index.html`.

### Apache (.htaccess)

Place this in your frontend's public directory (where `index.html` is located):

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.html [L]
```

> **Backend:** The Laravel backend (served separately) handles routes, including the `/.well-known/jwks` endpoint, natively — no extra rewrite rules needed.
>
> If deploying backend and frontend on the same domain, configure the backend on a subpath (e.g., `/api`) or use separate subdomains.

---

## SSL Configuration

Enable HTTPS through your hosting panel (most providers offer free Let's Encrypt certificates) or configure your SSL certificate manually.

After enabling SSL, update your `.env` files to use `https://`:

- `APP_URL=https://sso.example.com`
- `VITE_API_URL=https://sso.example.com`

---

## Deployment Checklist

- [ ] Backend files uploaded to hosting
- [ ] RSA keys generated and uploaded (`back/keys/`)
- [ ] Frontend files compiled and uploaded to public directory
- [ ] Database configured and migrations executed
- [ ] Seeders executed
- [ ] Access setup wizard at `/setup` and create first admin
- [ ] Environment variables configured for production
- [ ] **Frontend:** `cp front/.env.production.example front/.env.production` and set `VITE_API_URL`
- [ ] Config cache cleared after .env changes (`php artisan config:clear`)
- [ ] APP_KEY generated (`php artisan key:generate`)
- [ ] SSL/HTTPS enabled
- [ ] Rewrite rules working (no 404 on refresh)
- [ ] JWKS endpoint accessible (`GET /.well-known/jwks`)
- [ ] Test direct login (`POST /api/login`)
- [ ] Test OAuth2 authorization code flow
- [ ] Access admin panel
- [ ] Cron configured for `schedule:run` (`* * * * * cd /path/to/back && php artisan schedule:run >> /dev/null 2>&1`)
- [ ] Review application logs for errors

> **Required:** The `schedule:run` cron purges expired JWT refresh tokens, DPoP nonces, and stale login attempts. Without it, the database grows unbounded. Add to crontab:
>
> ```bash
> * * * * * cd /path/to/back && php artisan schedule:run >> /dev/null 2>&1
> ```

---

## First Run / Setup Wizard

On first deployment, the SSO server guides you through creating the initial admin:

1. Open your browser at `https://your-domain.com`
2. The frontend checks `GET /api/setup/status`
3. If no users exist, it redirects to `/setup`
4. Fill in: name, username, email, password, confirm
5. Submit — creates first admin via `POST /api/setup/admin`
6. Redirects to login

> Protected by `EnsureSetupNotCompleted` middleware — the wizard cannot run again once users exist.

---

## Troubleshooting

### 404 on page refresh (SPA)

Ensure rewrite rules are correctly configured in your `.htaccess` or server configuration.

### Database connection error

Verify your database credentials in `back/.env`. Contact your hosting provider for the correct database host.

### Assets not loading

Verify frontend files are in the correct public directory and paths in `index.html` are correct.

### "Unable to create signed JWT" or token issues

Verify that:

1. RSA keys exist at `back/keys/private.pem` and `back/keys/public.pem`
2. File permissions allow the web server to read them
3. Paths in `JWT_PRIVATE_KEY_PATH` and `JWT_PUBLIC_KEY_PATH` are correct

### CORS errors in frontend

When frontend and backend are on different subdomains (e.g., `panel.sso.example.com` vs `sso.example.com`), the browser treats them as cross-origin and requires proper CORS headers.

The SSO server uses dynamic CORS via `HandleDynamicCors` middleware that reads allowed origins from:

1. **Registered SSO clients** — each client's `allowed_origins` field (array of origins in DB)
2. **`config('app.frontend_url')`** — configured via `FRONTEND_URL` env var (fallback for admin panel)

**Backend (.env) — REQUIRED when using separate subdomains:**

```env
APP_URL=https://sso.example.com       # Backend domain
FRONTEND_URL=https://panel.sso.example.com  # Frontend domain (panel)
```

This populates `app.frontend_url` which is included in the allowed origins list.

**For third-party client apps**, ensure the OAuth client's `allowed_origins` field in `SsoClient` DB entry includes their frontend origin.

**Frontend (.env.production):**

```env
VITE_API_URL=https://sso.example.com/api
```

After changing `.env`, run:

```bash
php artisan config:clear
```

The CORS origin list is cached with key `sso_cors_origins_{clientId}` and refreshes every 30 seconds.

### OAuth2 redirect_uri mismatch

When registering an SSO client, ensure the `redirect_url` exactly matches your application's callback URI (including protocol, domain, and port).

### Native/mobile clients: the WebView origin is `https://localhost`

A Capacitor app does not load its bundle from a server: the WebView serves the app from inside the installed package, using the `https` scheme on `localhost` (the Capacitor default). Every request it makes therefore identifies itself as `Origin: https://localhost` — in development and **in production alike**, because this depends on the WebView, not on where the backend lives.

Consequences when registering a native client:

* The client's `allowed_origins` **must** include `https://localhost`; otherwise the authorization-code exchange is rejected by CORS. The user-visible symptom is a generic "could not sign in" screen, and nothing appears in the server logs because the request never reaches the API.
* Production should contain **only** the origins it actually serves: the published web app origin plus `https://localhost` for the native build. Development origins (`http://localhost:5173`, `http://localhost`, anything plain HTTP) do not belong in a production client.
* Register the native app as its **own client**: `redirect_url` is validated by exact match and a client holds a single value, so a custom scheme such as `com.example.app://auth/callback` cannot share the row with the web callback URI.

Verify the registered values with:

```sql
SELECT client_id, redirect_url, allowed_origins FROM sso_clients;
```

> **The `client_id` is baked into the app at build time.** A build shipped with the *web* client id cannot complete a native login: the authorize request carries the custom scheme against a client whose `redirect_url` is the https callback, and it is rejected as a redirect_uri mismatch. The web and native builds of the same app therefore need different `VITE_SSO_CLIENT_ID` values — check it before producing a release build, because nothing in the UI surfaces the mistake.

> Trade-off, stated explicitly: allowlisting `https://localhost` means any local process on a user's machine could claim that origin. It is accepted because the Capacitor default also owns the app's offline storage (IndexedDB/localStorage), so changing the WebView hostname would relocate that storage and wipe local data on existing installs. The refresh cookie is `HttpOnly` + `SameSite=Lax`, so it is not sent on these cross-site requests.

When developing a native client against a **local HTTP backend**, Android additionally requires cleartext traffic. Keep it in a debug-only manifest overlay (`android/app/src/debug/AndroidManifest.xml` with `android:usesCleartextTraffic="true"`) so release builds keep the platform default.

### Passkey registration fails with `'rp.id' cannot be used with the current origin`

The WebAuthn `rp.id` must equal (or be a parent suffix of) the host of the page driving the ceremony — i.e. the **frontend** host, not the API host. On split-domain deploys the `APP_URL` fallback gets this wrong. Fix in `back/.env`:

```env
PASSKEY_RP_ID=<frontend-host>                        # e.g. panel.sso.example.com
PASSKEY_ALLOWED_ORIGINS=https://<frontend-host>      # must include the frontend origin
```

Then `php artisan config:clear && php artisan config:cache`, and confirm the live values with `php artisan tinker --execute="echo config('passkey.rp_id').PHP_EOL;"`. `setup.sh` derives both from `FRONTEND_URL` on fresh installs (existing values are kept). Warning: changing `rp.id` invalidates credentials registered under the old one — check `PasskeyCredential::count()` first.

---

## Domain Summary

| Service            | Development             | Production                      |
| ------------------ | ----------------------- | ------------------------------- |
| SSO Backend API    | `http://localhost:8001` | `https://sso.example.com`       |
| SSO Frontend       | `http://localhost:5173` | `https://panel.sso.example.com` |
| Portfolio Backend  | `http://localhost:8001` | `https://api.sso.example.com`   |
| Portfolio Frontend | `http://localhost:5174` | `https://example.com`           |

---

## CI/CD Validation Deploys (dryrun)

> **Note:** sso's release flow is more complex than other projects in the org
> (subtree split to mirror repo, npm publish for `@dowardev/sso-client`,
> Packagist publish for `dowardev/sso-server`). The full release procedure
> lives in `AGENTS.md` under "Release Conventions". This section only covers
> the `dryrun` tag suffix for pipeline validation.

To exercise the GitLab CI pipeline end-to-end against the real server without
polluting the release history, push a tag with the `-dryrun.N` suffix:

```bash
git tag -a v0.0.0-dryrun.1 -m "dryrun: validate CI changes against server"
git push origin v0.0.0-dryrun.1
# ... watch pipeline, verify behaviour ...
git tag -d v0.0.0-dryrun.1
git push origin :refs/tags/v0.0.0-dryrun.1
```

The pipeline accepts `vX.Y.Z`, `vX.Y.Z-alpha.N`, `vX.Y.Z-beta.N`,
`vX.Y.Z-rc.N`, and `vX.Y.Z-dryrun.N`. Anything else is ignored.

⚠️ **Important:** `dryrun` tags trigger the full pipeline including deploy,
but they never publish `@dowardev/sso-client` — npm publishing is manual
from your local terminal (see "Publishing sso-client to npm" below).

---

## Useful Commands

```bash
# Backend (from back/)
php artisan serve --port=8001  # Start server on port 8001
php artisan route:list          # List registered routes
php artisan config:clear       # Clear configuration cache
php artisan migrate            # Run migrations
php artisan db:seed            # Seed database

# Frontend (from front/)
pnpm run dev                   # Development server (port 5173)
pnpm run build                 # Production build (lint + typecheck)

# Root monorepo
pnpm run dev                   # Start frontend + backend concurrently
```

## License

This project uses **dual licensing**:
- **Server** (`back/`, `dowardev/sso-server`): AGPL-3.0-only
- **SDKs and client packages** (`packages/sso-client/`, `front/`): MIT

See `LICENSE-COMMERCIAL.md` for commercial licensing options.

---

## Publishing sso-client to npm

The `@dowardev/sso-client` package lives in `packages/sso-client/` of this monorepo.
Unlike sso-server, npm accepts the package directly from the subdirectory — no
mirror repo is required.

### One-time setup

- Create an npm account and organization `dowardev` at npmjs.com.
- Run `npm login` once locally (auth persisted in ~/.npmrc).

### Publishing a new release

```bash
# 1. Bump version in packages/sso-client/package.json
#    (e.g. "version": "0.0.2")

# 2. Build the library (Vite produces dist/)
pnpm run build:sso-client

# 3. Publish (scoped packages require --access public the first time)
cd packages/sso-client
npm publish --access public
cd ../..

# 4. Commit the version bump and push
git add packages/sso-client/package.json
git commit -m "release(sso-client): vX.Y.Z"
git push origin <branch>
```

### Notes

- Publish directly from the working branch — no merge to `main` required.
- No tag needed for sso-client (npm is the registry of record, not git tags).
- Files included in the published package are controlled by the `files` field
  in `packages/sso-client/package.json` (currently only `dist`).

---

## Publishing sso-server to Packagist (manual subtree split)

The `dowardev/sso-server` Composer package lives in `packages/sso-server/` of this
monorepo. Packagist requires `composer.json` at the repository root, so we maintain a
mirror repo at `gitlab.com/dowardev/sso-server` via `git subtree split`.

### One-time setup

1. Create a blank repo `gitlab.com/dowardev/sso-server` (public, no README/LICENSE).
2. From the monorepo root:
   ```bash
   git subtree split --prefix=packages/sso-server -b sso-server-split
   git checkout sso-server-split
   composer install
   vendor/bin/phpunit     # verify 31 tests pass
   git checkout <original-branch>
   ```
3. Add the mirror remote and push:
   ```bash
   git remote add sso-server-mirror git@gitlab.com:dowardev/sso-server.git
   git push sso-server-mirror sso-server-split:main
   ```
4. Tag the first release and push:
   ```bash
   git tag -a v0.1.0 sso-server-split -m "Initial release"
   git push sso-server-mirror v0.1.0
   ```
5. Register at `packagist.org/packages/submit` with URL
   `https://gitlab.com/dowardev/sso-server.git` (the mirror, NOT the monorepo).

### Updating for a new release

```bash
cd /home/dowardev/Documentos/programacion/sso
git subtree split --prefix=packages/sso-server -b sso-server-split
git push sso-server-mirror sso-server-split:main
git tag -a vX.Y.Z sso-server-split -m "Release vX.Y.Z"
git push sso-server-mirror vX.Y.Z
```

Packagist updates automatically when the new tag is pushed.

### Notes
- The mirror repo should NOT have CI/CD pipelines configured; updates are manual.
- Tags use standard semver `vX.Y.Z`. The `glab-head` remote mirrors the monorepo to
  GitLab but has no CI triggers for the mirror repo.
- The monorepo itself (`gitlab.com/dowardev/sso`) does not need tags for sso-server
  releases — all release metadata lives in the mirror.
