# LBDS-253 — DPoP obligatorio en /oauth/token (refresh_token)

Ticket: https://linear.app/dowardev/issue/LBDS-253
Branch: `dowardev/lbds-253-p0-dpop-obligatorio-en-oauthtoken-refresh_token`
MR draft: https://gitlab.com/dowardev/sso/-/merge_requests/61
Estado: In Progress (hook Linear, verificado)

## Problema

`POST /api/oauth/token` con `grant_type=refresh_token` canjea el refresh token sin
prueba de posesión DPoP. Un refresh token robado del `localStorage` se canjea con
solo conocer el secreto: no hay binding criptográfico al dispositivo.

Además, el acceso token emitido en el refresh **pierde** el binding DPoP:

- `back/app/Services/AuthService.php:349` usa `$existing->user_agent` como si fuera
  el JWK (`// placeholder — real DPoP logic`). `json_decode()` de un user-agent
  devuelve `null`, así que el token nuevo sale sin claim `cnf` → se degrada
  silenciosamente a Bearer.

## Decisiones de diseño (aprobadas por el usuario)

1. **Alcance:** DPoP obligatorio **solo** en `/oauth/token`. `/refresh-token` (flujo
   cookie del front web) no cambia de semántica.
2. **Fuente del binding:** persistir el thumbprint en `user_refresh_tokens.dpop_jkt`
   (columna nueva, nullable) y heredarlo en cada rotación.
3. **Tokens sin binding (pre-migración):** `401` en `/oauth/token`. Fuerza re-login
   una vez.
4. **Clientes:** `packages/sso-client`. El front queda fuera (ticket aparte).

### Por qué `/refresh-token` no cambia

`front/src/stores/auth.ts:183-197` **siempre** manda `dpop_public_key` en `/login`
→ el access token sale con `cnf.jkt`. `front/src/services/api.ts` (`getHeaders`)
**nunca** manda header `DPoP`. Hoy el front funciona porque la primera llamada a
una ruta `jwt.auth` da 401, `api.ts` llama a `/refresh-token`, y ese refresh
entrega un token **sin** `cnf` (por el bug del placeholder) → reintento como
Bearer → 200.

Preservar el binding en `/refresh-token` **rompería el front entero**
(401 → refresh → sigue con `cnf` → 401 → logout). Por eso:

- `/oauth/token` (estricto): el token rotado **conserva** `cnf.jkt`.
- `/refresh-token` (no estricto): comportamiento actual intacto.

Deuda derivada → ticket aparte: hacer `front/src/services/api.ts` DPoP-aware y
recién entonces preservar el binding también en `/refresh-token`.

## Diseño técnico

### Emisión (binding)
- Migración: `user_refresh_tokens.dpop_jkt` char(64) nullable, indexado.
- `AuthService::generateRefreshToken(..., ?string $dpopJkt = null)` persiste el jkt.
  El jkt se calcula del JWK recibido (`JwtService::generateJWKThumbprint`).
- Callers que ya reciben `dpop_public_key`: `generateTokens` (login/passkey) y
  `OAuth2Service::handleAuthorizationCode` (exchange OAuth).

### Emisión del access token con jkt directo
`JwtService` gana `generateTokenForJkt(User $user, ?string $dpopJkt, bool $rememberMe = false)`
para poder emitir `cnf.jkt` teniendo solo el thumbprint (el JWK no se persiste).
`token_type` = `DPoP` cuando hay jkt, `Bearer` si no. `generateToken()` mantiene
su firma y comportamiento actuales.

### Verificación (proof de token-endpoint)
`VerifyDpopProof` gana un método reutilizable para proof de request de token
(RFC 9449 §5: `htm`/`htu`/`iat`/`jti`, **sin** `ath` obligatorio):
- `typ` = `dpop+jwt`, `alg` = RS256, `jwk` presente y RSA completo.
- thumbprint del JWK del proof **igual** al `dpop_jkt` del refresh token.
- firma válida con la clave pública del proof.
- `htm` = método, `htu` = URL del endpoint, `iat` dentro de ±60s.
- `ath`: si viene, se valida contra el Bearer presentado; si falta, se acepta.
- replay: `jti` insertado en `dpop_nonces` (INSERT IGNORE).
- El `verify()` actual (token ya vinculado) **no** cambia: `ath` sigue obligatorio.

### Enrutamiento de la exigencia
- `OAuth2Controller::token` → `handleRefreshToken` → `refreshAccessToken(..., requireDpop: true)`.
- `AuthController::refreshToken` → `refreshAccessToken(..., requireDpop: false)`.
- Con `requireDpop: true`: sin binding → 401; proof faltante o inválido → 401.
- Token rotado (access + refresh) conserva el binding **solo** en esa ruta.

## Tareas

| # | Tarea | Estado |
|---|---|---|
| T1 | Migración `dpop_jkt` + modelo `UserRefreshToken` | done |
| T2 | `JwtService::generateTokenForJkt` (emitir cnf sin JWK) | done |
| T3 | Persistir y heredar `dpop_jkt` (AuthService + OAuth2Service) | done |
| T4 | `VerifyDpopProof::verifyTokenRequest` (proof de token-endpoint) | done |
| T5 | Exigir DPoP en la ruta refresh de `/oauth/token` | done |
| T6 | Tests de integración backend (401/200, binding preservado) | done |
| T7 | `sso-client`: proof DPoP incondicional en los dos paths de refresh | done |
| T8 | Verificación: suite backend + tests sso-client | done |
| T9 | Fix F1: enforcement por binding, no por endpoint | done |
| T10 | Fix F1b: `/login` deja de bindear | done |
| T11 | Fix F2: revertir claims extra en `generateToken` | done |
| T12 | Fix F3: reordenar enforcement después de `isValid()` | done |
| T13 | Fix F5: tests faltantes (firma, htm/htu, ath, contratos) | done |
| T14 | Re-review del delta (F1/F1b/F2/F3 PASS, ssa-client PASS) | done |
| T15 | Triage 2 del delta (D1-D4) + fixes | done |
| T16 | Re-verificación final | done |

## Triage 2 — del review del delta

| id | Severidad | Decisión del usuario | Estado |
|---|---|---|---|
| D1 test F3 no discriminante | baja | Reescribir test + sumar los faltantes | fixed (mutación verificada: sin el orden nuevo, falla) |
| D2 supuesto de htu detrás de proxy | media | Documentar + ticket propuesto (no creado) | documented |
| D3 dpop_public_key inválido | baja | Rechazar con 422 (no 500 ni par sin binding) | fixed |
| D4 docblock mal ubicado | estilo | Corregir orden | fixed |
| D5 corte duro pre-migración | media | accepted into base (decisión 3) | accepted |

## Supuesto de despliegue (D2)

`htu` se reconstruye en el backend a partir de `getSchemeAndHttpHost() + getBaseUrl() + getPathInfo()` (o `fullUrl()`), y el cliente lo arma desde su `ssoUrl`/`baseUrl` configurada. En producción, detrás de un reverse proxy, el matching solo funciona si los forwarded headers llegan correctamente al backend; no hay configuración de `TrustProxies` en el repo que fije ese supuesto. Un proxy que reescriba esquema/host/prefijo sin propagarlos rompería los refreshes con binding con un 401. Validación posible solo e2e en el entorno real. Propuesto como ticket: configurar `TrustProxies` + validar e2e htu/htu con la topología de producción.

## Triage del review (diff inicial)

| id | Severidad | Decisión del usuario | Estado |
|---|---|---|---|
| F1 bypass por `/refresh-token` | crítica | Condicional al binding + `/login` sin binding + proof incondicional en `sso-client` | fixed |
| F2 `generateToken` emitía claims extra | media | Revertir los 2 claims | fixed |
| F3 orden DPoP vs `isValid()` | baja | Reordenar: primero `isValid()` | fixed |
| F4 tokens pre-migración sin binding → 401 | alta | Ya decidido (decisión 3): corte duro, re-login | accepted into base |
| F5 tests faltantes | media | Agregar los tests faltantes | fixed |
| F6 `generateToken` nunca emitió `cnf` (pre-existente) | info | Follow-up: ticket de activación DPoP | deferred (propuesto) |

## Evidencia de verificación

- Baseline pre-cambio: `php artisan test --filter='SsoSessionSyncTest|RefreshTokenRotationTest'` → 16 passed (63 assertions).
- Suite backend final: `php artisan test` → **154 passed (435 assertions)**.
- `vendor/bin/pint --test --dirty` → passed. (`php-cs-fixer` no está instalado en el entorno; el repo trae `.php-cs-fixer.dist.php`.)
- Suite `sso-client`: `npx vitest run` → 53 passed (9 files); `pnpm run typecheck` → exit 0.
- Mutation check 1 (enforcement por endpoint deshabilitado, revisión inicial): 5 de 7 tests fallan.
- Mutation check 2 (`if ($existing->dpop_jkt)` deshabilitado): 9 de 16 tests fallan.
- F2 verificado empíricamente con `tinker`: `generateToken($user, $jwk, true)` para un usuario `advanced` emite exactamente `[exp, iat, iss, jti, sub, user]` — sin `cnf`, sin `encryption_level`, sin `remember_me` — y `token_type = DPoP`. Igual que `main`.
- Review fresco del diff inicial: 1 crítico (F1), 1 medio (F2), 1 bajo (F3), gaps de test (F5). Review nativo bloqueado por entorno (`package-local-binary-missing`, `lineage_created: false`).

## Riesgos residuales conocidos

- **Corte duro (F4)**: los refresh tokens OAuth emitidos antes del deploy tienen `dpop_jkt` NULL → 401 en `/oauth/token`. Los clientes OAuth deben re-loguearse una vez. Decidido explícitamente.
- **htu detrás de proxy**: el cliente arma `htu` como `${baseUrl}/refresh-token`; el backend acepta esa forma o `$request->fullUrl()`. Un proxy que reescriba el prefijo podría romper refreshes legítimos. No hay nada en el repo que fije esa suposición; solo se valida e2e.
- **`generateToken` no emite `cnf`** (pre-existente): el access token emitido en el exchange de código no queda realmente vinculado aunque `token_type` diga `DPoP`. Ticket de follow-up propuesto: activar la emisión de `cnf` + hacer `front/src/services/api.ts` DPoP-aware + preservar el binding en `/refresh-token` para el front.
- **sso-client con refresh vía `/refresh-token`**: un cliente OAuth (paquete) usa el endpoint cookie para refrescar. Funciona porque ahora manda proof, pero el endpoint correcto para ese cliente sería `/oauth/token`. Decisión pendiente, no en este MR.

## Criterios de aceptación (del ticket)

- [ ] Refresh sin DPoP → 401
- [ ] Refresh con DPoP inválido (jkt mismatch, replay, timestamp) → 401
- [ ] Refresh con DPoP válido → 200 + access nuevo + refresh rotado
- [ ] Tests de integración éxito/fallo

## Evidencia

- Baseline pre-cambio: `php artisan test --filter='SsoSessionSyncTest|RefreshTokenRotationTest'`
  → 16 passed (63 assertions).
