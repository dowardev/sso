# LBDS-249 — Epic close: decisión P2/P3 + doc de arquitectura

<!-- markdownlint-disable MD013 -->

## Goal

Cerrar el epic `LBDS-249 [EPIC] SSO Hardening: Crypto, Token Binding & Passkeys`
cumpliendo los dos criterios de cierre que quedaban pendientes:

- Decisión documentada para los sub-tickets P2/P3 (`LBDS-258`, `LBDS-257`, `LBDS-259`).
- Documentación de arquitectura actualizada en `docs/architecture/security.md`.

## Scope

Solo dos outputs, ambos `docs/` + bookkeeping interno:

1. `docs/architecture/security.md` (nuevo) — snapshot del estado de hardening del
   SSO tras el release del monorepo `v0.35.0` (tag `ed3ff5fd`,
   2026-09-27) más los bumps de registry inmediatamente posteriores
   (`dowardev/sso-server v0.1.2` en Packagist el mismo día,
   `@dowardev/sso-client v0.3.0` en npm el mismo día). Documenta también la
   decisión registrada para los P2/P3 — ver §8 del doc público.
2. `odd/tasks/lbds-249-epic-close-decision.md` (este archivo) — la decisión
   en formato interno antes de comitearla al repo público. Las tablas P0/P1
   quedan duplicadas acá a propósito (tracking local); las P2/P3 **no** se
   duplican — referencian `docs/architecture/security.md` §8 (single source
   of truth).

## Estado de los sub-tickets al cierre del epic

### ✅ P0 — Done (4/4)

| Ticket | Entregado en | Notas |
| --- | --- | --- |
| LBDS-253 — DPoP obligatorio en `/oauth/token` (refresh_token) | release `v0.35.0` + `dowardev/sso-server v0.1.2` | `back/app/Services/AuthService.php` + migration `2026_09_19_*` de `user_refresh_tokens.dpop_jkt`. Refresh tokens pre-existentes con `dpop_jkt = NULL` quedan invalidados → re-login forzado (corte duro documentado en `odd/tasks/lbds-253-dpop-refresh-token.md` F4). |
| LBDS-250 — Limpieza inmediata de fragmento `#profile=` | `@dowardev/sso-client v0.3.0` | `client/profileFragmentCleanup.ts` (nuevo) + `SSOClient.handleCallback`. Guarda: solo borra si el URL matchea `origin + pathname + search + hash`; preserva el `history.state` del router host. |
| LBDS-251 — Rate limit dedicado en `/oauth/token` (client_id + IP) | release `v0.35.0` | Sliding window per-`(client_id, IP)` + per-IP en paralelo en `App\Http\Middleware\ThrottleTokenEndpoint`. `authorization_code` excluido (PKCE + TTL corto ya cubren). `throttle:10,1` upstream sigue como defense-in-depth. 9 tests integración + 8 unit. |
| LBDS-252 — Cookies `SameSite=Strict` + `Secure` | release `v0.35.0` | `config/session.php` endurecido; helper privado `AuthController::refreshTokenCookie()` centraliza los 4 sitios de emisión; IIFE policy evita blank-env regressions. 6 regression tests en `RefreshTokenCookieAttributesTest`. |

### ✅ P1 — Done (3/3)

| Ticket | Entregado en | Notas |
| --- | --- | --- |
| LBDS-256 — Header `kid` en JWTs + resolución JWKS por `kid` | release `v0.35.0` | `JwtService` emite `kid`; `JwksFetcher` resuelve por `kid`. Rotación de claves ahora no invalida clientes cacheados. |
| LBDS-254 — `jti` único (UUID v4) en access tokens | release `v0.35.0` | `buildToken` + `generateClientToken` + `issueProfileToken` ahora usan `Str::uuid()` (122 bits entropía). Refresh rotate también emite `jti` fresco. 8 unit + 6 feature tests. Persistencia `jti ↔ session` queda como follow-up. |
| LBDS-255 — dpopNonces en Redis/DB compartida (multi-instancia) | `dowardev/sso-server v0.1.2` (Composer) y release `v0.35.0` | `ReplayProtection::checkAndStore()` usa `Cache::add()` (atómico: `SET NX EX` Redis, `INSERT IGNORE` DB, Memcached `add`). Warning log si `cache.default` es `file` o `array`. Cross-project: el fix vive en el package Composer (consumido por apps que embeban `sso-server`). |

### ⏳ P2 — Decisión: implementar más adelante (cuando llegue la necesidad concreta)

| Ticket | Decisión | Razón |
| --- | --- | --- |
| LBDS-258 — Migración DPoP RSA-2048 → ECDSA P-256 (ES256) | **Postergado.** Mantener RSA-2048 actual. | Rationale detallada: ver `docs/architecture/security.md` §8.1 (este doc mantiene solo el resumen interno). |
| LBDS-257 — Soporte Ed25519 (alg -8) en Passkeys | **Postergado.** Mantener ES256 + RS256 actual. | Rationale detallada: ver `docs/architecture/security.md` §8.2. |

### ⏳ P3 — Decisión: no implementar salvo requisito regulatorio

| Ticket | Decisión | Razón |
| --- | --- | --- |
| LBDS-259 — mTLS / Token Binding TLS | **No implementar.** DPoP ya cubre token binding RFC 8471 a nivel aplicación. | Rationale detallada: ver `docs/architecture/security.md` §8.3. |

## Follow-up surfacing (adjacent to the epic)

Referenciados desde `docs/architecture/security.md` pero no son sub-tickets formales del LBDS-249:

| Ticket | Status | Notas |
| --- | --- | --- |
| LBDS-261 — CORS rejection diagnostics + `redirect_uri` origin allowlist | Shipped at `v0.35.0` | Adjacent, mencionado en §2 + §6 + §9 del doc público. |
| LBDS-267 — Frontend single-channel error reporting | Shipped at `v0.35.0` | Adjacent, mencionado en §6 + §7 + §9 del doc público. |
| LBDS-278 — `Cookie::forget()` parity con emission | Backlog | Smell surfaced by the LBDS-252 pre-merge review (squashed commit `ea4f090`, merge `79fc346`), F4 menor. Mencionado en §2 del doc público. Sale en un round independiente, no reabre LBDS-249. |

## Acceptance

- [x] LBDS-249 — Decisión P2/P3 documentada. Single source of truth: `docs/architecture/security.md` §8.
- [x] LBDS-249 — Doc de arquitectura actualizado (`docs/architecture/security.md`).
- [ ] LBDS-249 — Merge del MR draft `dowardev/lbds-249-epic-sso-hardening-crypto-token-binding-passkeys` → `main`, vía flow de pre-merge-review + scope-ownership gate + changelog question.
- [ ] LBDS-249 — Estado del epic movido a **Done** en Linear (**post-merge**, ya sea por webhook de GitLab al mergear el MR o por acción manual del humano — el agente no lo mueve solo porque está gated por el Delivery gate del skill `linear-ticket-flow-pi`).

## Out of scope

- Implementación de LBDS-258 / 257 / 259 — explícitamente fuera del alcance de cierre del epic.
- Cambios funcionales al código `back/` / `front/` / `packages/` — el release `v0.35.0` del monorepo + los bumps de registry del mismo día (`sso-server v0.1.2`, `sso-client v0.3.0`) ya cubren los P0/P1.

## Riesgos / notas

- **Decisión política, no técnica.** El cierre del epic es principalmente una declaración de cuáles tickets quedan en Backlog. Si en el futuro se reabre LBDS-258 (DPoP ECDSA), se reabre con su propio ticket independiente, no "reviviendo" LBDS-249.
- **El doc `docs/architecture/security.md` queda vivo.** Si una de las decisiones cambia (por ejemplo, llega regulación y LBDS-259 pasa a implementarse), se actualiza el doc, no se reabre LBDS-249.
