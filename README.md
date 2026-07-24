# dowardev/sso-server

Librería del lado servidor para autenticación SSO en Laravel con verificación **DPoP** (Demonstrating Proof-of-Possession) y validación de JWT vía JWKS.

Diseñada para integrarse con el servidor central SSO y exponer middleware de autenticación y roles (`SsoAuthMiddleware`, `SsoRoleMiddleware`), resolutores de usuario y un cliente HTTP (`SsoServerClient`) para consumir el endpoint `GET /api/auth/me` del servidor SSO.

## Requisitos

- PHP **8.2+**
- Laravel **11.x / 12.x / 13.x**
- `lcobucci/jwt ^5.x`
- Conexión saliente al servidor SSO (para `/.well-known/jwks`)

## Instalación

```bash
composer require dowardev/sso-server
```

## Publicación y configuración

```bash
php artisan vendor:publish --provider="Dowardev\SsoServer\SsoServerServiceProvider"
```

El archivo de configuración publicado define la URL del servidor SSO, el JWKS endpoint y mapeos de roles. Los middleware se registran vía alias:

```php
// app/Http/Kernel.php (o bootstrap/app.php en Laravel 11+)
protected $routeMiddleware = [
    'sso.auth' => \Dowardev\SsoServer\Middleware\SsoAuthMiddleware::class,
    'sso.role' => \Dowardev\SsoServer\Middleware\SsoRoleMiddleware::class,
];
```

## Uso básico

```php
use Dowardev\SsoServer\Facades\SsoAuth;

Route::middleware('sso.auth')->get('/profile', function () {
    return response()->json(SsoAuth::user());
});

Route::middleware(['sso.auth', 'sso.role:admin'])->get('/admin', function () {
    // ...
});
```

## Licencia y Modelo Comercial

Este proyecto está disponible bajo un esquema de **Dual Licensing**:

- **AGPLv3 (Open Source):** Libre para uso personal, desarrollo y proyectos que compartan su código fuente bajo AGPLv3. Si ejecutas o modificas este servicio en la nube (SaaS), estás obligado a liberar el código fuente de tus modificaciones.
- **Licencia Comercial Privada:** Si deseas utilizar este servidor en un entorno empresarial cerrado, integrarlo en productos propietarios sin liberar código o requerir acuerdos de nivel de servicio (SLA), debes adquirir una Licencia Comercial.

Para consultas sobre licencias comerciales: `gonzalo@dowar.xyz`

El texto íntegro de la licencia AGPLv3 está en el archivo [LICENSE](./LICENSE).

## Clientes / SDKs relacionados

- [`@dowardev/sso-client`](https://www.npmjs.com/package/@dowardev/sso-client) — SDK TypeScript para el navegador (licencia MIT, permisiva).
