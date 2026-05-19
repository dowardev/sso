# SSO Login CORS & Refresh Token Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Arreglar el sistema de login SSO para que funcione tanto desde el panel admin como desde clientes externos (portafolio), simplificando la arquitectura de CORS e implementando el endpoint de refresh de tokens que nunca existió.

**Architecture:** Eliminar el middleware redundante `ValidateClientOrigin`, mantener solo `HandleDynamicCors` para validación de orígenes, corregir bugs en la normalización de orígenes, e implementar `/refresh-token` para completar el ciclo de vida de los tokens.

**Tech Stack:** Laravel 12 (PHP 8.2+), JWT (lcobucci/jwt), DPoP

---

## Task 1: Eliminar ValidateClientOrigin de las rutas

**Files:**
- Modify: `back/routes/api.php`
- Delete: `back/app/Http/Middleware/ValidateClientOrigin.php`
- Modify: `back/bootstrap/app.php`

- [ ] **Step 1: Modificar routes/api.php — quitar grupo client.origin**

Abre el archivo `back/routes/api.php` y elimina el grupo `client.origin` que envuelve `/register` y `/oauth/token`. Las rutas deben quedar al nivel superior, sin middleware extra.

```php
// ANTES (líneas 11-14):
Route::middleware('client.origin')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/oauth/token', [OAuth2Controller::class, 'token']);
});

// DESPUÉS:
Route::post('/register', [AuthController::class, 'register']);
Route::post('/oauth/token', [OAuth2Controller::class, 'token']);
```

- [ ] **Step 2: Eliminar ValidateClientOrigin.php**

Elimina el archivo `back/app/Http/Middleware/ValidateClientOrigin.php` completamente. Ya no se usa.

- [ ] **Step 3: Modificar bootstrap/app.php — quitar alias client.origin**

Abre `back/bootstrap/app.php` y busca donde se registra el alias `client.origin`. Elimina esa línea.

```php
// Buscar y eliminar algo como:
$app->withMiddleware(function (Middleware $middleware) {
    // ...
    $middleware->alias([
        // ...
        'client.origin' => \App\Http\Middleware\ValidateClientOrigin::class,
        // ...
    ]);
});
```

Elimina la línea `'client.origin' => ...` del array de aliases.

- [ ] **Step 4: Commit**

```bash
git add back/routes/api.php back/app/Http/Middleware/ValidateClientOrigin.php back/bootstrap/app.php
git commit -m "refactor: remove ValidateClientOrigin middleware"
```

---

## Task 2: Corregir HandleDynamicCors — normalizeOrigin default http

**Files:**
- Modify: `back/app/Http/Middleware/HandleDynamicCors.php`

- [ ] **Step 1: Modificar normalizeOrigin()**

Abre `back/app/Http/Middleware/HandleDynamicCors.php`, busca el método `normalizeOrigin()` (línea 104-121). En la línea 107, cambia el default de `'https'` a `'http'`.

```php
// ANTES (línea 107):
$scheme = strtolower($parsed['scheme'] ?? 'https');

// DESPUÉS:
$scheme = strtolower($parsed['scheme'] ?? 'http');
```

- [ ] **Step 2: Commit**

```bash
git add back/app/Http/Middleware/HandleDynamicCors.php
git commit -m "fix: default to http in normalizeOrigin()"
```

---

## Task 3: Corregir .env — FRONTEND_URL con protocolo

**Files:**
- Modify: `back/.env`

- [ ] **Step 1: Modificar .env**

Abre `back/.env` y cambia la línea 6:

```dotenv
# ANTES:
FRONTEND_URL=localhost:8000

# DESPUÉS:
FRONTEND_URL=http://localhost:8000
```

**Nota:** En producción, este valor será `https://auth-pane.dowar.xyz`.

- [ ] **Step 2: Commit**

```bash
git add back/.env
git commit -m "fix: add http scheme to FRONTEND_URL"
```

---

## Task 4: Implementar endpoint /refresh-token

**Files:**
- Modify: `back/routes/api.php`
- Modify: `back/app/Http/Controllers/AuthController.php`

- [ ] **Step 1: Agregar ruta en api.php**

Abre `back/routes/api.php` y agrega la nueva ruta después de `/login`:

```php
// Agregar después de línea 9:
Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
```

- [ ] **Step 2: Implementar método refreshToken() en AuthController**

Abre `back/app/Http/Controllers/AuthController.php` y agrega el método `refreshToken` después del método `login` (después de línea 135). Copia este código completo:

```php
public function refreshToken(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'token' => 'required|string',
        'client_id' => 'nullable|string|exists:sso_clients,client_id',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Error de validación',
            'errors' => $validator->errors(),
        ], 422);
    }

    $tokenString = $request->input('token');

    try {
        $token = $this->jwtConfig->parser()->parse($tokenString);

        $constraints = [
            new SignedWith($this->jwtConfig->signer(), $this->jwtConfig->verificationKey()),
            new IssuedBy($this->issuer),
        ];

        if (!$this->jwtConfig->validator()->validate($token, ...$constraints)) {
            return response()->json([
                'message' => 'Token inválido',
            ], 401);
        }

        $userId = $token->claims()->get('sub');
        $user = User::with('roles')->find($userId);

        if (!$user) {
            return response()->json([
                'message' => 'Usuario no encontrado',
            ], 401);
        }

        $dpopPublicKey = null;
        $cnfClaim = $token->claims()->get('cnf');
        if ($cnfClaim && isset($cnfClaim['jkt'])) {
            $existingSession = UserSession::where('user_id', $userId)
                ->where('token', $tokenString)
                ->first();
            if ($existingSession && $existingSession->dpop_public_key) {
                $dpopPublicKey = $existingSession->dpop_public_key;
            }
        }

        $newTokenData = $this->generateToken($user, $dpopPublicKey);

        UserSession::where('user_id', $userId)
            ->where('token', $tokenString)
            ->update(['token' => $newTokenData['token'], 'last_used_at' => now()]);

        return response()->json([
            'token' => $newTokenData['token'],
            'token_type' => $newTokenData['token_type'],
            'expires_in' => $newTokenData['expires_in'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'user' => $user->user,
                'email' => $user->email,
                'roles' => $user->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                        'description' => $role->description,
                        'is_protected' => $role->is_protected,
                    ];
                }),
            ],
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Token inválido o expirado: ' . $e->getMessage(),
        ], 401);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add back/routes/api.php back/app/Http/Controllers/AuthController.php
git commit -m "feat: implement /refresh-token endpoint"
```

---

## Task 5: Verificar que login funcione

**Files:**
- Ninguno — verificación manual

- [ ] **Step 1: Verificar login desde admin (SSO Frontend)**

Inicia los servicios:
```bash
cd /home/dowardev/Documentos/programacion/sso
pnpm run dev:back
```

En otra terminal:
```bash
cd /home/dowardev/Documentos/programacion/sso
pnpm run dev:front
```

Navega a `http://localhost:8101/login` e intenta hacer login con credenciales `admin@sso.local` / `admin123`. Debería funcionar sin errores CORS.

- [ ] **Step 2: Verificar /oauth/token desde portafolio**

Verifica que `POST /api/oauth/token` funcione correctamente (o usa Postman/curl):

```bash
curl -X POST http://localhost:8001/api/oauth/token \
  -H "Content-Type: application/json" \
  -H "Origin: http://localhost:8100" \
  -d '{
    "code": "TEST_CODE",
    "client_id": "main-app",
    "redirect_uri": "http://localhost:8100/auth/callback",
    "code_verifier": "test"
  }'
```

Debería dar error por código inválido, pero no error CORS.

- [ ] **Step 3: Verificar /refresh-token**

Prueba el nuevo endpoint:

```bash
curl -X POST http://localhost:8001/api/refresh-token \
  -H "Content-Type: application/json" \
  -H "Origin: http://localhost:8100" \
  -d '{
    "token": "eyJ...",
    "client_id": "main-app"
  }'
```

Debería retornar un nuevo token si el token proporcionado es válido.

- [ ] **Step 4: Commit de verificación**

```bash
git add -A
git commit -m "chore: verify login works from admin and clients"
```

---

## Resumen de archivos modificados

| Archivo | Acción |
|---------|--------|
| `back/routes/api.php` | Eliminado grupo `client.origin`, agregada ruta `/refresh-token` |
| `back/app/Http/Middleware/ValidateClientOrigin.php` | Eliminado |
| `back/bootstrap/app.php` | Eliminado alias `client.origin` |
| `back/app/Http/Middleware/HandleDynamicCors.php` | Corregido default scheme de `https` a `http` |
| `back/.env` | Corregido `FRONTEND_URL=localhost:8000` → `http://localhost:8000` |
| `back/app/Http/Controllers/AuthController.php` | Agregado método `refreshToken()` |