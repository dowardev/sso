# SSO Server

Centralized authentication server providing JWT-based Single Sign-On (SSO) for multiple applications. Supports OAuth2 Authorization Code flow with PKCE, DPoP (Demonstrating Proof of Possession), JWKS public key discovery and RBAC (Role-Based Access Control).

## Demo

Access the production frontend at: [SSO Frontend](https://sso.example.com)

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    CLIENT APPLICATIONS                           │
│         Web App              │        Mobile App                 │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                      SSO FRONTEND                                │
│              localhost:5173 (Ionic/Vue 8)                     │
│                    ├─ Admin Panel                                │
│                    ├─ Login/Register                             │
│                    └─ OAuth Pages                               │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                       SSO BACKEND                                │
│              localhost:8001 (Laravel 12)                       │
│                    ├─ JWT Auth (RS256)                           │
│                    ├─ OAuth2 + PKCE                              │
│                    ├─ DPoP Proof Verification                    │
│                    ├─ JWKS Key Distribution                      │
│                    └─ Admin API (Users, Sessions, Clients,       │
│                       Roles)                                    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Tech Stack

### Backend

- Laravel 12
- lcobucci/jwt (RS256 signed JWT)
- lcobucci/clock (JWT validation clock)
- Laravel Sanctum (optional session cookies)

### Frontend

- Vue.js 3
- Ionic Vue 8
- TypeScript
- Vite
- Capacitor

---

## Prerequisites

- Node.js LTS
- pnpm
- PHP 8.2+
- Composer
- OpenSSL
- MySQL (development & production)

---

## Project Structure

```
sso/
├── back/                    # Laravel 12 backend
│   ├── app/                 # Controllers, Models, Services
│   ├── config/              # JWT configuration
│   ├── database/            # Migrations & Seeders
│   ├── keys/                # RSA key pair (private.pem, public.pem)
│   └── routes/             # API & Web routes
├── front/                   # Ionic/Vue 8 frontend
│   ├── src/                # Services, Views, Router
│   └── dist/               # Production build output
├── packages/                # Shared libraries
│   ├── sso-client/         # @dowardev/sso-client (OAuth2 PKCE + DPoP + axios)
│   └── sso-server/         # dowardev/sso-server (DPoP verification, Laravel middleware)
├── AGENTS.md               # Developer reference
├── DEPLOY.md               # Deployment guide
├── README.md               # This file
└── package.json            # Workspace orchestrator
```

---

## Installation

### Backend

```bash
cd back

# Install PHP dependencies
composer install

# Generate RSA keys for JWT (auto-creates back/keys/ if needed)
php artisan jwt:generate-keys

# Configure environment (copy full development template)
cp .env.development.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Seed database
php artisan db:seed

# Start server
php artisan serve --port=8001
```

> **Note:** After seeding, the first admin user is created via the setup wizard on first access (see [Initial Setup](#initial-setup-first-run)).

### Frontend

```bash
cd front

# Install dependencies
pnpm i

# Configure environment
cp .env.development.example .env.development

# Start development server
pnpm run dev
```

### Both (from root)

```bash
pnpm install
pnpm run dev       # Start both frontend and backend
```

---

## Initial Setup (First Run)

On first access, the SSO server runs a setup wizard:

1. **Status check** — Backend polls `GET /api/setup/status`
2. If no users exist → frontend redirects to `/setup`
3. **Form** — Name, username, email, password, confirm
4. **Submit** — `POST /api/setup/admin` creates the first admin
5. **Done** — `setup_completed` saved in localStorage, redirects to login

> Protected by `EnsureSetupNotCompleted` middleware — cannot run twice.

---

## Environment Configuration

### Backend (`.env`)

| Variable                 | Description            | Example                 |
| ------------------------ | ---------------------- | ----------------------- |
| `APP_URL`                | Application URL        | `http://localhost:8001` |
| `DB_CONNECTION`          | Database driver        | `mysql`                 |
| `CACHE_STORE`            | Cache driver           | `database`              |
| `JWT_ISSUER`             | JWT issuer             | `http://localhost:8001` |
| `JWT_TOKEN_EXPIRATION`   | Token lifetime (sec)   | `3600`                  |
| `JWT_REFRESH_EXPIRATION` | Refresh lifetime (sec) | `2592000`               |
| `JWT_REFRESH_INACTIVITY` | Max inactivity (sec)   | `604800`                |

> **Note:** For development, use the full template `back/.env.development.example`. For production, use `back/.env.production.example` (see [DEPLOY.md](./DEPLOY.md) for the complete variable reference).

### Frontend (`.env`)

Vite automatically loads the correct environment file based on the command:

| File                       | Command          | Purpose                         |
| -------------------------- | ---------------- | ------------------------------- |
| `.env.development`         | `pnpm run dev`   | Local development               |
| `.env.development.example` | —                | Template for local setup        |
| `.env.production`          | `pnpm run build` | **Actual** production variables |
| `.env.production.example`  | —                | Template for deployment         |

> **Note:** `.env.production` is gitignored. For production, copy `.env.production.example` and configure with your real URLs.

---

## Authentication Flows

### 1. Direct Login (API)

```bash
curl -X POST http://localhost:8001/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "password123",
    "client_id": "main-app"
  }'
```

**Response:**

```json
{
  "user": { "id": 1, "name": "John Doe", "email": "user@example.com" },
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

### 2. OAuth2 Authorization Code + PKCE

**Step 1 - Authorization Request (authenticated):**

```bash
curl -X POST http://localhost:8001/api/oauth/authorize \
  -H "Authorization: Bearer {user_jwt}" \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": "main-app",
    "redirect_uri": "https://miapp.com/callback",
    "code_challenge": "base64url_encoded_challenge",
    "code_challenge_method": "S256",
    "state": "random_state"
  }'
```

**Step 2 - Token Exchange (public):**

```bash
curl -X POST http://localhost:8001/api/oauth/token \
  -H "Content-Type: application/json" \
  -d '{
    "code": "authorization_code",
    "client_id": "main-app",
    "redirect_uri": "https://miapp.com/callback",
    "code_verifier": "original_verifier"
  }'
```

### 3. JWKS Public Key Discovery

The `/.well-known/jwks` endpoint exposes the server's RSA public key, allowing client applications to verify JWT tokens.

When a client app receives a JWT from SSO, it:

1. Queries `/.well-known/jwks`
2. Gets the RSA public key
3. Verifies the JWT signature

This is an OAuth2/OpenID Connect standard enabling trusted token verification across applications.

### 4. DPoP (Demonstrating Proof of Possession)

DPoP binds tokens to a specific RSA key pair for additional security:

1. **Frontend** generates RSA key pair in browser (stored in IndexedDB)
2. **Login/Register** includes `dpop_public_key` (JWK) in request
3. **Backend** embeds `cnf.jkt` (JWK thumbprint) claim in JWT
4. **Subsequent requests** include DPoP proof JWT signed with private key
5. **Backend** verifies DPoP proof matches `cnf.jkt` claim

---

## SSO Client Library

The `@dowardev/sso-client` package provides a typed OAuth2 client with PKCE and DPoP support. Source code is at `packages/sso-client/`.

```bash
# Build the library
pnpm run build:sso-client
```

To integrate into a TypeScript application:

```ts
import { SSOClient } from "@dowardev/sso-client";

const client = new SSOClient({
  clientId: "my-app",
  serverUrl: "https://sso.example.com",
  redirectUri: "https://myapp.com/callback",
});

await client.login({ email: "user@example.com", password: "..." });
```

---

## SSO Client Registration

Every application using this SSO must register as an **SSO Client** with a unique `client_id`.

### Create Client (Admin Only)

```bash
curl -X POST http://localhost:8001/api/admin/clients \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Application",
    "client_id": "main-app",
    "client_secret": "optional-secret",
    "redirect_url": "https://miapp.com/callback",
    "active": true
  }'
```

**Fields:**

- `name` — Display name for the application
- `client_id` — Unique identifier (required for all auth flows)
- `client_secret` — Optional secret for confidential clients
- `redirect_url` — Callback URI whitelist (validated during OAuth2)
- `active` — Enable/disable the client

---

## Admin Panel

The frontend admin panel (`/admin`) provides a web interface to manage:

- **Dashboard** — System status summary
- **Users** — CRUD operations, toggle admin status, view sessions
- **Sessions** — View and revoke active sessions globally or per user
- **SSO Clients** — Register and manage applications using the SSO
- **Roles** — Manage RBAC roles (admin, moderator, standard)
- **User Roles** — Assign roles to users with client scope
- **Config** — Runtime settings
- **Security** — CSP headers and allowlist
- **Design** — Theme and UI customization
- **Health** — System metrics (usage, errors, cache stats)

**Access:** Login with an admin account at `/login`, then navigate to `/admin`.

---

## Useful Commands

```bash
# Backend
php artisan serve --port=8001       # Start server
php artisan route:list               # List routes
php artisan db:seed                  # Seed database
php artisan migrate                  # Run migrations
php artisan config:clear             # Clear config cache

# Frontend
pnpm run dev                        # Development server
pnpm run build                      # Production build (lint + typecheck)
```

---

## Licencia y Modelo Comercial

Este monorepo contiene componentes con licencias distintas:

| Componente | Licencia | Descripción |
|------------|----------|-------------|
| **Servidor Core (`back/`)** | **AGPLv3** (Dual: AGPLv3 + Comercial) | Servidor SSO principal. Uso SaaS requiere liberar código o licencia comercial. |
| **SDKs / Clientes (`packages/`, `front/`, `sso-demo/`)** | **MIT** | Libre uso en cualquier proyecto (comercial u open source). |

### Dual Licensing (Servidor Core)

- **AGPLv3:** Libre uso si compartes tus modificaciones bajo AGPLv3. Aplicable si operas el servidor como servicio (SaaS).
- **Licencia Comercial:** Para uso cerrado/empresarial sin liberar código. Contacto: `contacto@dowardev.com`

Ver `LICENSE` (AGPLv3) y `LICENSE-COMMERCIAL.md` en la raíz.
Ver `back/LICENSE` para el servidor.
Ver `packages/*/LICENSE`, `front/LICENSE`, `sso-demo/LICENSE` para SDKs (MIT).
