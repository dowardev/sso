# SSO Server - Deployment Guide

Centralized authentication server for distributed applications. Provides OAuth2 authorization code flow, JWT token issuance, and user management.

## Demo

Access the production application at: [SSO Server](https://sso.tudominio.com)

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        SSO FRONTEND                             │
│                   [direccion]:[puerto]                                 │
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
│                   [direccion]:[puerto]                                │
│                              │                                  │
│   1. Validates user credentials                                 │
│   2. Creates user session                                       │
│   3. Issues JWT tokens (RS256)                                  │
│   4. Provides JWKS at /.well-known/jwks.json                    │
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
- Laravel 11
- Laravel Sanctum (OAuth2 tokens)
- lcobucci/jwt (JWT generation & validation)
- MySQL (production) / SQLite (development)

### Frontend
- Vue.js 3
- Vite
- Pinia (state management)
- Axios (HTTP client)

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

```
sso/
├── back/           # Laravel API
├── front/          # Vue.js SPA
├── sso-client/     # Client library for integration
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

# Seed default admin user
php artisan db:seed

# Start server
php artisan serve --port=8001
```

### 2. Frontend

```bash
cd front

# Install dependencies
npm install

# Configure environment
cp .env.example .env

# Start development server
npm run dev
```

---

## Deployment Configuration

### Backend (.env)

| Variable | Description | Example |
|----------|-------------|---------|
| `APP_NAME` | Application name | `SSO Server` |
| `APP_ENV` | Environment | `production` |
| `APP_DEBUG` | Debug mode | `false` |
| `APP_URL` | Backend URL | `https://sso.tudominio.com` |
| `DB_CONNECTION` | Database driver | `mysql` |
| `DB_HOST` | MySQL host | `[mysql_host]` |
| `DB_PORT` | MySQL port | `3306` |
| `DB_DATABASE` | Database name | `[database_name]` |
| `DB_USERNAME` | Database user | `[database_user]` |
| `DB_PASSWORD` | Database password | `[database_password]` |
| `JWT_PRIVATE_KEY_PATH` | RSA private key path | `keys/private.pem` |
| `JWT_PUBLIC_KEY_PATH` | RSA public key path | `keys/public.pem` |
| `JWT_ISSUER` | JWT issuer (your domain) | `https://sso.tudominio.com` |
| `JWT_TOKEN_EXPIRATION` | Token lifetime (seconds) | `3600` |
| `JWT_REFRESH_EXPIRATION` | Refresh token lifetime (seconds) | `86400` |
| `SANCTUM_STATEFUL_DOMAINS` | Allowed domains for cookies | `tudominio.com` |
| `SESSION_DOMAIN` | Session cookie domain | `.tudominio.com` |
| `MAIL_MAILER` | Mail driver | `smtp` |
| `MAIL_HOST` | SMTP host | `smtp.gmail.com` |
| `MAIL_PORT` | SMTP port | `587` |
| `MAIL_USERNAME` | SMTP username | `[your_email@example.com]` |
| `MAIL_PASSWORD` | SMTP password | `[your_password]` |
| `MAIL_ENCRYPTION` | Encryption (tls/ssl) | `tls` |
| `MAIL_FROM_ADDRESS` | Sender email | `noreply@tudominio.com` |
| `MAIL_FROM_NAME` | Sender name | `SSO Server` |

### Frontend (.env)

| Variable | Description | Example |
|----------|-------------|---------|
| `VITE_API_URL` | Backend API URL | `https://sso.tudominio.com` |

---

## Web Server Configuration

### Apache (.htaccess)

Place this in your public directory (where index.html is located):

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.html [L]
```

### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

> **Note:** If deploying backend and frontend on the same domain, configure the backend on a subpath (e.g., `/api`) or use separate subdomains.

---

## SSL Configuration

Enable HTTPS through your hosting panel (most providers offer free Let's Encrypt certificates) or configure your SSL certificate manually.

After enabling SSL, update your `.env` files to use `https://`:

- `APP_URL=https://sso.tudominio.com`
- `JWT_ISSUER=https://sso.tudominio.com`
- `VITE_API_URL=https://sso.tudominio.com`

---

## Deployment Checklist

- [ ] Backend files uploaded to hosting
- [ ] RSA keys generated and uploaded (`back/keys/`)
- [ ] Frontend files compiled and uploaded to public directory
- [ ] Database configured and migrations executed
- [ ] Seeders executed (admin user created)
- [ ] Environment variables configured for production
- [ ] SSL/HTTPS enabled
- [ ] Rewrite rules working (no 404 on refresh)
- [ ] JWKS endpoint accessible (`GET /.well-known/jwks`)
- [ ] Test direct login (`POST /api/login`)
- [ ] Test OAuth2 authorization code flow
- [ ] Access admin panel
- [ ] Review application logs for errors

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

Ensure CORS middleware is correctly configured in the backend. In development it allows `*`, but in production you should restrict it to your domain.

### OAuth2 redirect_uri mismatch

When registering an SSO client, ensure the `redirect_url` exactly matches your application's callback URI (including protocol, domain, and port).

---

## Domain Summary

| Service | Development | Production |
|---------|-------------|------------|
| SSO Backend API | `[direccion]:[puerto]` | `https://sso.tudominio.com` |
| SSO Frontend | `[direccion]:[puerto]` | `https://sso.tudominio.com` |
| Portfolio Backend | `[direccion]:[puerto]` | `https://api.tudominio.com` |
| Portfolio Frontend | `[direccion]:[puerto]` | `https://tudominio.com` |

---

## Useful Commands

```bash
# Backend
php artisan route:list          # List registered routes
php artisan config:clear       # Clear configuration cache
php artisan migrate            # Run migrations
php artisan db:seed            # Seed database

# Frontend
npm run build                  # Production build
npm run dev                    # Development server
```

---

## Default Credentials

After running seeders:
- **Admin:** `admin@sso.local` / `admin123`

> **Recommendation:** Change the admin password immediately after first access.

---

## License

This project is developed under the GPL 3 license.