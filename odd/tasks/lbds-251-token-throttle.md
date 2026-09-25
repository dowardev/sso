# LBDS-251 — Rate limit dedicado en /oauth/token (client_id + IP)

**Linear**: LBDS-251 · **Priority**: P0 (Urgent) · **Parent EPIC**: LBDS-249 (SSO Hardening)
**Branch**: `dowardev/lbds-251-p0-rate-limit-dedicado-en-oauthtoken-client_id-ip`
**MR**: https://gitlab.com/dowardev/sso/-/merge_requests/64 (draft)

## Objetivo

Proteger `/oauth/token` con rate limit dedicado por `(client_id, IP)` además del throttle
genérico ya existente. Cubre anti-fuerza bruta en `refresh_token` / `password` /
`client_credentials` y anti-enumeración de `client_id`.

## Acceptance criteria

- [x] Branch + MR draft creados (LBDS-251)
- [ ] Middleware `ThrottleTokenEndpoint` con sliding window (no fixed window)
- [ ] Límite 10 req/min por `(client_id, IP)`, burst 20
- [ ] Headers `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` en respuesta
- [ ] Excluye `grant_type=authorization_code` (ya protegido por PKCE + rate limit de auth code)
- [ ] `client_id` inválido → agrupa por IP (anti-enumeración)
- [ ] 11 req en 1 min desde mismo bucket → `429`
- [ ] Tests de carga pasando
- [ ] Sin regresiones en tests existentes

## Diseño

### Sliding window counter (2 buckets)

Algoritmo sliding window approximation con dos ventanas:

```
estimated = current_count + floor(previous_count * weight)
weight = (window_size - elapsed_in_current) / window_size
```

- **Window actual** (key = `bucket:N`): contador con TTL = 2 * window_size
- **Window anterior** (key = `bucket:N-1`): contador que sobrevive una ventana
- **Límite**: 10 req/min por bucket (steady state)
- **Burst**: 20 req instantáneos permitidos en la ventana actual (anti-DDoS)

### Bucket keys (doble bucket paralelo)

Dos buckets corren en paralelo — el más restrictivo gana:

1. **Bucket primario**: `(client_id, IP)` → anti-fuerza bruta dirigida
   - Key: `oauth_token_throttle:c:{sanitized_client_id}:{ip}`
   - `client_id` se sanea con `preg_replace('/[^a-zA-Z0-9_\-]/', '_')` para evitar
     injection en cache key; si está vacío o tiene > 128 chars → bucket = `c:unknown:{ip}`

2. **Bucket secundario**: `(IP)` → anti-enumeración / anti-DDoS
   - Key: `oauth_token_throttle:ip:{ip}`

Si un atacante prueba `client_id` aleatorios desde la misma IP, cada uno cae en un
bucket `(client_id, IP)` único (no satura el primario), pero todos comparten el bucket
`(IP)` (que sí satura en 10 requests). Trade-off: dos clientes legítimos detrás del
mismo NAT corporativo comparten el bucket IP — aceptable según el objetivo del ticket.

### Exclusión de authorization_code

`grant_type=authorization_code` pasa sin throttle. Ya está protegido por:
- PKCE (`code_verifier` validación)
- Rate limit del auth code (rotación + TTL corto)
- Single-use (`used_at` en `oauth_auth_codes`)

### Headers de respuesta

- `X-RateLimit-Limit: 10` — siempre presente
- `X-RateLimit-Remaining: <0-10>` — slots restantes
- `Retry-After: <seconds>` — solo en 429

### Storage

Usa `Cache::increment()` (Laravel facade) — funciona con cualquier cache store:
- Producción default: `database` (configurable a `redis`)
- Tests: `array` (in-memory, per-test)
- Sin dependencia hard de Redis en runtime, pero el ticket queda cumplido cuando
  `CACHE_STORE=redis` en producción (operador decide el store).

## Tasks

- [ ] **T1** — Crear `back/app/Http/Middleware/ThrottleTokenEndpoint.php` (sliding window)
- [ ] **T2** — Wire en `back/bootstrap/app.php` (alias `throttle.token`) + `back/routes/api.php`
- [ ] **T3** — Crear `back/tests/Feature/ThrottleTokenEndpointTest.php` (acceptance criteria)
- [ ] **T4** — Run `composer test` + verificar `composer lint`/`pint` (si aplica)
- [ ] **T5** — Work-unit commit + push a branch (NO merge)
- [ ] **T6** — Pre-merge review + triage gate + changelog question

## Decisiones

- **D1**: Sliding window 2-bucket (current + previous weighted) por bucket. Más simple,
  atómico via `Cache::increment`, y suficiente para el criterio (10/min, burst 20).
- **D2**: Doble bucket paralelo `(client_id, IP)` + `(IP)` — el más restrictivo gana.
  Cubre anti-fuerza bruta dirigida Y anti-enumeración con un único middleware.
- **D3**: `grant_type=authorization_code` se excluye DENTRO del middleware (no por route),
  para mantener una sola ruta y registrar el bypass en logs si hace falta después.
- **D4**: Mantener el `throttle:10,1` genérico existente — son capas independientes
  (genérico por IP en producción, dedicado con doble bucket). Defense in depth.
- **D5**: Parámetros del middleware: `throttle.token:{limit},{window},{burst}` con defaults
  10/60/20. Tests pueden overridear via `throttle.token:5,60,5` si necesitan.
- **D6**: Trade-off documentado: dos clientes legítimos detrás del mismo NAT corporativo
  comparten el bucket `(IP)` y pueden bloquearse mutuamente. Aceptable para el caso de
  uso principal (anti-enumeración de client_id válidos). Operadores pueden mitigarlo
  con un balanceador que asigne IPs distintas a clientes distintos.

## Riesgos / Edge cases

- **Cache store "array" en testing**: cada test corre con cache limpio. Para tests del rate
  limit, todos los requests van al mismo test, así que el bucket persiste en la misma ejecución.
  ✓
- **Clock skew entre windows**: al rotar de ventana, la primera request puede ver
  `current=1, previous=10`. Weight ≈ 0 → estimated=1. OK.
- **Cache::increment race**: en driver `database` o `file`, no es atómico entre procesos.
  `redis` sí es atómico. Documentar: en producción con mucha concurrencia, usar Redis.
- **`client_id` muy largo (DoS)**: cap a 128 chars → bucket "unknown".

## Files affected

| File | Action |
|---|---|
| `back/app/Http/Middleware/ThrottleTokenEndpoint.php` | CREATE |
| `back/bootstrap/app.php` | MODIFY (alias `throttle.token`) |
| `back/routes/api.php` | MODIFY (route `/oauth/token`) |
| `back/tests/Feature/ThrottleTokenEndpointTest.php` | CREATE |

## Verification

```bash
cd /home/dowardev/Documentos/programacion/sso/back
php artisan test --filter=ThrottleTokenEndpointTest
php artisan test  # full suite (no regressions)
```

## Status

- **In progress** — T1 next.