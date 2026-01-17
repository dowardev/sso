# SSO Server

Centralized SSO (Single Sign-On) server for authentication of multiple applications.

---

> **⚠️ IMPORTANT NOTE - PRODUCTION ENVIRONMENT**
>
> Examples in this document use `localhost:8002` and development domains.
> **In production, replace all local values with your actual domains.**
>
> See the "[Production Configuration](#production-configuration)" section below.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        FRONTEND (Ionic/Vue)                      │
│                   https://miapp.com                              │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                     PORTAFOLIO BACKEND                           │
│                   https://api.miapp.com                          │
│                              │                                   │
│                              ▼                                   │
│              Middleware SSOTokenAuth validates token             │
│                              │                                   │
│                              ▼                                   │
│              GET https://sso.miapp.com/api/me                    │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                          SSO SERVER                              │
│                   https://sso.miapp.com                          │
│                          Laravel + Sanctum                       │
└─────────────────────────────────────────────────────────────────┘
```

## Tech Stack

- Laravel 12
- Laravel Sanctum (API authentication)
- MySQL

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/register` | Register new user |
| POST | `/api/login` | Login |
| POST | `/api/logout` | Logout (requires token) |
| GET | `/api/me` | Get authenticated user (requires token) |
| GET | `/api/user-auth` | Alias of `/me` for compatibility |

---

## Development Configuration

### Environment Variables (.env)

```env
APP_NAME=SSO Server
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8002

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sso_server
DB_USERNAME=root
DB_PASSWORD=

# Sanctum - allowed domains for cookies (if used)
SANCTUM_STATEFUL_DOMAINS=localhost:8003,localhost:8002
```

### Installation

```bash
# Install dependencies
composer install

# Configure database
cp .env.example .env
php artisan key:generate
# Edit .env with database credentials

# Run migrations
php artisan migrate

# (Optional) Create test user
php artisan tinker --execute="\App\Models\User::create(['name' => 'Admin', 'user' => 'admin', 'email' => 'admin@admin.com', 'password' => bcrypt('123')]);"

# Start server
php artisan serve --port=8002
```

---

## Production Configuration

> **IMPORTANT:** All `localhost:8002` values must be replaced with your real domain.

### Environment Variables (.env.production)

```env
APP_NAME=SSO Server
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sso.miapp.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sso_server
DB_USERNAME=sso_user
DB_PASSWORD=your_secure_password_here

# CRITICAL: Domains that can use session cookies
# Include your frontend and backend
SANCTUM_STATEFUL_DOMAINS=miapp.com,www.miapp.com,api.miapp.com

# (Optional) Token expiration in minutes
# SANCTUM_EXPIRATION=60
```

### Required Changes for Production

| Configuration | Development | Production |
|---------------|-------------|------------|
| `APP_URL` | `http://localhost:8002` | `https://sso.miapp.com` |
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:8003,localhost:8002` | `miapp.com,www.miapp.com,api.miapp.com` |
| Database | Localhost | Dedicated MySQL server |

```

---

## API Usage (Development)

### Registration

```bash
curl -X POST http://localhost:8002/api/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "user": "johndoe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
  }'
```

### Login

```bash
curl -X POST http://localhost:8002/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@admin.com",
    "password": "123"
  }'
```

Response:
```json
{
  "user": {
    "id": 5,
    "name": "Admin",
    "user": "admin",
    "email": "admin@admin.com"
  },
  "token": "1|abc123xyz..."
}
```

### Protected Endpoints

All protected endpoints require the `Authorization: Bearer {token}` header:

```bash
curl http://localhost:8002/api/me \
  -H "Authorization: Bearer 1|abc123xyz..."
```

---

## Integration with Other Projects

### Ionic/Vue Frontend

See [Frontend README](../frontend/README.md)

### Portafolio Backend

The portfolio backend uses this SSO for token validation. See [Backend README](../backend/README.md)

---

## Deployment Checklist

- [ ] Configure domain and DNS
- [ ] Obtain SSL certificate
- [ ] Configure MySQL database
- [ ] Set secure credentials in `.env`
- [ ] Configure `SANCTUM_STATEFUL_DOMAINS` with real domains
- [ ] Disable `APP_DEBUG`
- [ ] Configure job queue if needed
- [ ] Configure logs and monitoring
- [ ] Test complete flow: registration → login → access protected resources

For a more detailed checklist, see [DEPLOYMENT_CHECKLIST.md](./DEPLOYMENT_CHECKLIST.md).

---

## License

This project is developed under GPL 3 license.
