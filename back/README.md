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

### Autenticación

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/register` | Register new user (requiere `client_id`) |
| POST | `/api/login` | Login (requiere `client_id`) |
| POST | `/api/logout` | Logout (requires token) |
| GET | `/api/me` | Get authenticated user (requires token) |
| GET | `/api/user-auth` | Alias of `/me` for compatibility |

### Administración (requiere token de admin)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/admin/users` | Listar usuarios (paginado, búsqueda) |
| GET | `/api/admin/users/{id}` | Detalle de usuario + sus sesiones |
| PUT | `/api/admin/users/{id}` | Actualizar usuario |
| DELETE | `/api/admin/users/{id}` | Eliminar usuario |
| POST | `/api/admin/users/{id}/toggle` | Activar/desactivar admin |
| GET | `/api/admin/sessions` | Todas las sesiones activas |
| GET | `/api/admin/users/{id}/sessions` | Sesiones de un usuario |
| DELETE | `/api/admin/sessions/{id}` | Revocar sesión específica |
| DELETE | `/api/admin/users/{id}/sessions` | Revocar todas las sesiones |
| GET | `/api/admin/clients` | Listar clientes SSO |
| POST | `/api/admin/clients` | Crear cliente SSO |
| PUT | `/api/admin/clients/{id}` | Actualizar cliente SSO |
| DELETE | `/api/admin/clients/{id}` | Eliminar cliente SSO |

---

## SSO Clients (Aplicaciones Registradas)

Cada aplicación que use el SSO debe registrarse como un **Cliente SSO** con un `client_id` único.

### Crear un cliente SSO

```bash
curl -X POST http://localhost:8002/api/admin/clients \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Mi Aplicación",
    "client_id": "mi-app",
    "redirect_url": "https://miapp.com/callback",
    "active": true
  }'
```

### Login con client_id (REQUERIDO)

Todos los endpoints de login y registro ahora requieren `client_id`:

```bash
curl -X POST http://localhost:8002/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "123456",
    "client_id": "mi-app"
  }'
```

### Ver sesiones por aplicación

```bash
# Ver todas las sesiones activas de una aplicación
curl http://localhost:8002/api/admin/sessions?client_id=1&active=1 \
  -H "Authorization: Bearer {admin_token}"

# Ver sesiones de un usuario específico
curl http://localhost:8002/api/admin/users/5/sessions \
  -H "Authorization: Bearer {admin_token}"
```

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

# (Optional) Create test user (admin)
php artisan tinker --execute="\App\Models\User::create(['name' => 'Admin', 'user' => 'admin', 'email' => 'admin@admin.com', 'password' => bcrypt('123'), 'is_admin' => true]);"

# (Optional) Create test SSO client
php artisan tinker --execute="\App\Models\SsoClient::create(['name' => 'App Principal', 'client_id' => 'main-app', 'active' => true]);"

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
    "password_confirmation": "password123",
    "client_id": "main-app"
  }'
```

### Login

```bash
curl -X POST http://localhost:8002/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@admin.com",
    "password": "123",
    "client_id": "main-app"
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
