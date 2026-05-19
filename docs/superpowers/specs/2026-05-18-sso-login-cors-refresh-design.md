# Diseño: Corrección del Sistema de Login SSO

## Fecha
18 de mayo de 2026

## Contexto

El sistema SSO (backend: `auth.dowar.xyz`, frontend: `auth-pane.dowar.xyz`) tuvo problemas de login cuando se hizo deploy en producción. Para solucionarlo, se implementó un sistema de CORS dinámico con dos middlewares:

- `HandleDynamicCors`: Lee orígenes permitidos desde la BD de clientes activos + FRONTEND_URL
- `ValidateClientOrigin` (`client.origin`): Valida que el Origin de la petición coincida con el allowed_origins del cliente específico cuyo client_id se envía en el body

Este enfoque añadió complejidad innecesaria y rompió el login desde otros dominios (como el portafolio).

## Problema

1. **Login del admin no funcionaba en producción**: El panel admin (`auth-pane.dowar.xyz`) no podía hacer login porque su origen no estaba en la lista de orígenes permitidos.

2. **El fix rompió el login desde otros clientes**: El middleware `client.origin` exige que el origen coincida con el cliente específico, pero:
   - Cualquier origen puede enviar cualquier `client_id` en el body
   - La validación es redundante porque `HandleDynamicCors` ya valida que el origen esté en la lista de ALGÚN cliente activo

3. **Endpoint `/refresh-token` no existe**: La librería `sso-client` llama `POST /refresh-token` pero el backend nunca tuvo esta ruta. El refresh de tokens siempre falló con 404.

4. **Bug en `FRONTEND_URL`**: El `.env` tiene `FRONTEND_URL=localhost:8000` (sin protocolo), lo que causa que `normalizeOrigin()` asuma `https` por defecto, generando un origen inválido.

## Solución Propuesta (Enfoque B)

Simplificar la arquitectura eliminando la capa redundante de `ValidateClientOrigin`, manteniendo solo `HandleDynamicCors` como guardián de CORS.

### 1. Eliminar `ValidateClientOrigin`

- Quitar el grupo `client.origin` de todas las rutas en `routes/api.php`
- `/login`, `/register`, `/oauth/token` quedan como rutas públicas (sin middleware extra de validación por cliente)
- Eliminar el archivo `app/Http/Middleware/ValidateClientOrigin.php`
- Eliminar el alias `client.origin` del bootstrap

### 2. Corregir `HandleDynamicCors`

**Archivo**: `app/Http/Middleware/HandleDynamicCors.php`

**Cambio en `normalizeOrigin()`**:
```php
// Antes (línea 107):
$scheme = strtolower($parsed['scheme'] ?? 'https');

// Después:
$scheme = strtolower($parsed['scheme'] ?? 'http');
```

**Justificación**: Los entornos locales y la mayoría de los clientes usan `http` por defecto cuando no hay esquema explícito. Asumir `https` rompe el matching con orígenes válidos.

### 3. Corregir `.env`

**Archivo**: `.env`

**Cambio**:
```dotenv
# Antes:
FRONTEND_URL=localhost:8000

# Después:
FRONTEND_URL=http://localhost:8000
```

**Nota**: En producción, este valor será `https://auth-pane.dowar.xyz`.

### 4. Implementar `/refresh-token`

**Nueva ruta en `routes/api.php`**:
```php
Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
```

**Nuevo método en `AuthController.php`**:
```php
public function refreshToken(Request $request)
```

**Flujo**:
1. Recibe `{ token, client_id }` (el JWT actual o por expirar)
2. Valida la firma RS256 del JWT (la firma sigue siendo válida aunque el token haya expirado — lo que expira es el claim `exp`, no la firma cryptográfica)
3. Extrae el `sub` (user_id) del token
4. Verifica que el usuario sigue activo y existe
5. Genera un nuevo token con los mismos claims:
   - `sub`, `user`
   - `cnf.jkt` si existía (para mantener DPoP binding)
6. Actualiza el registro `UserSession` en la BD (el token viejo ya no será válido)
7. Retorna `{ token, token_type, expires_in, user }`

**Contrato de la respuesta** (debe coincidir con `sso-client`):
```typescript
interface AuthResponse {
  token: string;
  refreshToken?: string;
  user?: User;
}
```

### 5. Ajustar librería `sso-client` (si es necesario)

La librería ya envía la estructura correcta:
- `TokenRefreshManager.refreshToken()` envía `{ refreshToken, client_id }`
- Espera recibir `{ token, refreshToken, user }`

Verificar que el backend devuelva exactamente esto.

## Arquitectura Final

```
Solicitud HTTP
       │
       ▼
HandleDynamicCors (global)
  ├── Lee allowed_origins de TODOS los clientes activos (cache 60s)
  ├── Lee FRONTEND_URL + APP_URL
  ├── Valida Origin contra la lista mergeada
  └── Si no coincide: permite la petición pero sin headers CORS
       │
       ▼
Ruta específica (/login, /register, /oauth/token, etc.)
       │
       ▼
Controlador (AuthController, OAuth2Controller, etc.)
```

## Consideraciones de Seguridad

1. **El `client_secret` se mantiene**: Se usa en el intercambio OAuth2 (`/oauth/token`) para verificar que el backend del cliente es legítimo. No se elimina.

2. **La seguridad real viene de**:
   - `client_secret`: secreto entre SSO y el backend del cliente
   - PKCE: previene que un atacante intercepte el authorization code
   - DPoP (opcional): bindea el token a una clave pública RSA

3. **Validar `client_id` en `/oauth/token`**: Aunque eliminamos la validación por origen, el `client_id` sigue siendo obligatorio para crear el token correcto y guardar la sesión asociada al cliente.

## Pendiente (No incluido en este scope)

- Tests unitarios para los nuevos componentes
- Documentación de la API para `/refresh-token`
- UI en el admin para gestionar `allowed_origins` de cada cliente

## Referencias

- RFC 6749: OAuth 2.0 Authorization Framework
- RFC 7636: PKCE (Proof Key for Code Exchange)
- RFC 9449: DPoP (Demonstrating Proof-of-Possession)
- Middleware actual: `app/Http/Middleware/HandleDynamicCors.php`
- Controlador actual: `app/Http/Controllers/AuthController.php`
- Librería cliente: `sso-client/packages/sso-client/src/auth/TokenRefreshManager.ts`