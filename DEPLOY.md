# SSO Server - Deployment Guide

<!-- markdownlint-disable MD013 MD060 -->

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

# Seed database
php artisan db:seed

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
| `APP_SETUP_TOKEN`          | Setup authentication token       | _(auto-generated)_                |
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

---

## Domain Summary

| Service            | Development             | Production                      |
| ------------------ | ----------------------- | ------------------------------- |
| SSO Backend API    | `http://localhost:8001` | `https://sso.example.com`       |
| SSO Frontend       | `http://localhost:5173` | `https://panel.sso.example.com` |
| Portfolio Backend  | `http://localhost:8001` | `https://api.sso.example.com`   |
| Portfolio Frontend | `http://localhost:5174` | `https://example.com`           |

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

This project is developed under the MIT license.
