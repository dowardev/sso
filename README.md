<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Acerca de este proyecto

Servidor SSO (Single Sign-On) centralizado para autenticación de múltiples aplicaciones.

## Arquitectura

```
┌─────────────────────────────────────────────────────────────────┐
│                        FRONTEND (Ionic/Vue)                      │
│                   http://localhost:8003                          │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                     BACKEND PORTAFOLIO                           │
│                   http://localhost:8003/api                      │
│                              │                                   │
│                              ▼                                   │
│              Middleware SSOTokenAuth valida token                │
│                              │                                   │
│                              ▼                                   │
│              GET http://localhost:8002/api/me                    │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                          SSO SERVER                              │
│                   http://localhost:8002/api                      │
│                          Laravel + Sanctum                       │
└─────────────────────────────────────────────────────────────────┘
```

## Stack Tecnológico

- Laravel 12
- Laravel Sanctum (autenticación API)
- MySQL

## Endpoints API

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| POST | `/api/register` | Registrar nuevo usuario |
| POST | `/api/login` | Iniciar sesión |
| POST | `/api/logout` | Cerrar sesión (requiere token) |
| GET | `/api/me` | Obtener usuario autenticado (requiere token) |
| GET | `/api/user-auth` | Alias de `/me` para compatibilidad |

## Configuración

### Variables de entorno (.env)

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

# Sanctum - dominios permitidos para cookies (si se usan)
SANCTUM_STATEFUL_DOMAINS=localhost:8003,localhost:8002
```

### Instalación

```bash
# Instalar dependencias
composer install

# Configurar base de datos
cp .env.example .env
php artisan key:generate
# Editar .env con credenciales de BD

# Ejecutar migraciones
php artisan migrate

# (Opcional) Crear usuario de prueba
php artisan tinker --execute="\App\Models\User::create(['name' => 'Admin', 'user' => 'admin', 'email' => 'admin@admin.com', 'password' => bcrypt('123')]);"

# Iniciar servidor
php artisan serve --port=8002
```

### Puerto

Por defecto, el servidor SSO corre en `http://localhost:8002`

## Uso de la API

### Registro

```bash
curl -X POST http://localhost:8002/api/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Juan Pérez",
    "user": "juanperez",
    "email": "juan@ejemplo.com",
    "password": "contraseña123",
    "password_confirmation": "contraseña123"
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

Respuesta:
```json
{
  "user": {
    "id": 5,
    "name": "Admin",
    "user": "admin",
    "email": "admin@admin.com",
    "email_verified_at": null,
    "created_at": "2026-01-16T21:10:04.000000Z",
    "updated_at": "2026-01-16T21:10:04.000000Z"
  },
  "token": "1|abc123xyz..."
}
```

### Endpoints protegidos

Todos los endpoints protegidos requieren el header `Authorization: Bearer {token}`:

```bash
curl http://localhost:8002/api/me \
  -H "Authorization: Bearer 1|abc123xyz..."
```

## Integración con otros proyectos

### Frontend Ionic/Vue

Ver [README del Frontend](../frontend/README.md)

### Backend Portafolio

El backend del portafolio usa este SSO para validar tokens. Ver [README del Backend](../backend/README.md)

## Licencia

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
