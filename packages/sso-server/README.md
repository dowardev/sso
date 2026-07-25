# dowardev/sso-server

Server-side library for SSO authentication in Laravel with **DPoP** (Demonstrating Proof-of-Possession) verification and JWT validation via JWKS.

Designed to integrate with the central SSO server and expose authentication and role middleware (`SsoAuthMiddleware`, `SsoRoleMiddleware`), user resolvers, and an HTTP client (`SsoServerClient`) to consume the SSO server's `GET /api/auth/me` endpoint.

## Requirements

- PHP **8.2+**
- Laravel **11.x / 12.x / 13.x**
- `lcobucci/jwt ^5.x`
- Outbound connection to the SSO server (for `/.well-known/jwks`)

## Installation

```bash
composer require dowardev/sso-server
```

## Publishing and configuration

```bash
php artisan vendor:publish --provider="Dowardev\SsoServer\SsoServerServiceProvider"
```

The published config file defines the SSO server URL, JWKS endpoint, and role mappings. Middleware is registered via alias:

```php
// app/Http/Kernel.php (or bootstrap/app.php in Laravel 11+)
protected $routeMiddleware = [
    'sso.auth' => \Dowardev\SsoServer\Middleware\SsoAuthMiddleware::class,
    'sso.role' => \Dowardev\SsoServer\Middleware\SsoRoleMiddleware::class,
];
```

## Basic usage

```php
use Dowardev\SsoServer\Facades\SsoAuth;

Route::middleware('sso.auth')->get('/profile', function () {
    return response()->json(SsoAuth::user());
});

Route::middleware(['sso.auth', 'sso.role:admin'])->get('/admin', function () {
    // ...
});
```

## License and Commercial Model

This project is available under a **Dual Licensing** scheme:

- **AGPLv3 (Open Source):** Free for personal use, development, and projects that share their source code under AGPLv3. If you run or modify this service in the cloud (SaaS), you are required to release the source code of your modifications.
- **Commercial Private License:** If you wish to use this server in a closed enterprise environment, integrate it into proprietary products without releasing code, or require service-level agreements (SLA), you must acquire a Commercial License.

For commercial licensing inquiries: `contacto@dowardev.com`

The full text of the AGPLv3 license is in the [LICENSE](./LICENSE) file.

## Related Clients / SDKs

- [`@dowardev/sso-client`](https://www.npmjs.com/package/@dowardev/sso-client) — TypeScript SDK for the browser (MIT license, permissive).
