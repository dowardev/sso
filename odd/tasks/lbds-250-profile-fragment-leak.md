# LBDS-250 — Limpieza inmediata de fragmento `#profile=`

Ticket: https://linear.app/dowardev/issue/LBDS-250
Branch: `dowardev/lbds-250-p0-limpieza-inmediata-de-fragmento-profile`
MR draft: https://gitlab.com/dowardev/sso/-/merge_requests/62
Estado: In Progress (hook Linear, verificado 2026-09-20T19:38:09Z)

## Problema

El perfil descifrado (ZKE) se entrega a la app OAuth como fragmento URL `#profile=<base64>`.
El consumidor (`SSOClient.handleCallback`) lee el fragmento y **nunca** lo borra: no existe un
solo `history.replaceState` ni una lectura de `document.referrer` en todo el repo (verificado por
grep sobre `front/src`, `packages/sso-client/src`, `packages/sso-server/src`). Consecuencias:

* El perfil queda accesible en `window.location.href` de la pestaña del callback.
* Persiste en el historial del navegador (la entrada se creó al navegar con el fragmento).
* Se filtra por `document.referrer` al navegar desde el documento del callback.

Viola el modelo de amenaza ZKE: el servidor nunca ve el plaintext, pero el navegador lo retiene.

## Mapa del ciclo de vida (evidencia)

### Productores

Hay **dos productores** del mismo canal `#profile=`:

1. **SDK** — `packages/sso-client/src/crypto/profileCipher.ts`
   * `encodeProfileFragment` (l.67-69): base64 estándar (unicode-safe vía UTF-8 → binary → `btoa`)
     de `JSON.stringify(profile)`. Payload = `UserProfile` crudo, **sin firmar**.
   * `buildProfileRedirectUrl` (l.76-85): `url += '#profile=' + encodeProfileFragment(...)`.
   * `packages/sso-client/src/crypto/ZkeClient.ts:163-169` (`buildAppRedirectUrl`) delega acá.

2. **Front del SSO (producción)** — `front/src/controllers/authController.ts`
   * `jsonToBase64` (l.97-104) duplica el helper del SDK.
   * `buildAppRedirectUrl` (l.112-126): payload = `{ profile, token }`, donde `token` es un JWS
     RS256 emitido por `GET /api/user/profile-token` (`JwtService::issueProfileToken`,
     `back/app/Services/JwtService.php:177`). El perfil no está firmado: el `token` que lo envuelve
     atestigua `(sub, profile_version, iat, exp)`.
   * Sitios de append: `authController.ts:979` (`approveOAuthAuthorization`) y `:1029`
     (`redirectOAuthCode`), ambos vía `window.location.href = await buildAppRedirectUrl(...)`.

### Consumidor

`packages/sso-client/src/client/SSOClient.ts` → `handleCallback(url)` (l.210-305):

* Recibe la URL como **string**; parsea en l.212-216.
* **Lee el fragmento** en l.219-222 (`urlObj.hash.startsWith('#profile=')` + `.slice(...)`).
  Solo matchea si `#profile=` es el primer segmento completo del hash.
* Consulta `code`/`state` en l.224-225; resuelve PKCE en l.227-235.
* Exchange async `internalPost('oauth/token', data)` en l.266-273.
* **Consumo real** en l.281-292: `verifyProfileFragment` (`profileSignature.ts`) verifica el JWS
  RS256 contra `${ssoUrl}/.well-known/jwks` y valida `sub`/`exp`; el resultado se persiste con
  `persistProfileAtRest` (`profileVault.ts:81`) en **IndexedDB** (`sso_client_vault` / `kv` /
  `profile`) con clave AES-GCM no extraíble, y queda en memoria en `this.activeProfile`.
  Si la verificación falla → `activeProfile = null` (identidad nula).
* **No hay limpieza**: ningún `replaceState` / `pushState` / `location.hash =`. Los únicos usos de
  `window.location` en el archivo son ajenos: l.16 (`origin`), l.207 y l.363 (navegación de salida).

### Quién puede borrarlo

El fragmento vive en el **origen de la app OAuth externa** (el `redirect_uri`), no en el origen del
SSO. El `front/` es productor y **nunca** lee un hash entrante (verificado: ninguna view, component,
composable ni service lee `location.hash`). Por lo tanto **solo el SDK** — corriendo en la app
anfitriona — puede alcanzar la ubicación viva. El fix va en `SSOClient.handleCallback`.

### Restricción de empaquetado

`front/` consume el SDK por **alias a source**, no por el `dist` publicado:

* `front/vite.config.ts:14` → `'@dowardev/sso-client': path.resolve(__dirname, '../packages/sso-client/src/index.ts')`
* `front/tsconfig.json:17` → `"@dowardev/sso-client": ["../packages/sso-client/src/index.ts"]`

`front/package.json` **no** declara la dependencia. Conclusión: el fix es efectivo in-repo al
instante y es verificable sin publicar. Las **apps externas** que instalan el paquete desde el
registro (`@dowardev/sso-client` 0.2.3, `files: ["dist"]`) solo quedan protegidas tras publicar una
versión nueva. `front/` es el único consumidor in-repo (blast radius acotado).

### Terreno de tests

* `packages/sso-client`: **sin config de vitest y sin `test` block en `vite.config.ts`** → corre en
  el entorno **`node`** por default. `SSOClient.test.ts` lo asume explícitamente y hace polyfills
  manuales: `vi.stubGlobal('window', { location: { origin, href: '' } })` (l.~35) — **sin `history`
  ni `document`**. `fake-indexeddb/auto` está disponible.
* `front/`: `test.environment: 'jsdom'` en `front/vite.config.ts` (`jsdom@22.1.0` es devDep de
  `front`, y ya está en el store de pnpm).
* `front/tests/e2e/specs/`: Cypress real, pero corre sobre `front/` = **productor**, no sobre el
  origen del consumidor externo → **no puede** asertar la limpieza.

## Decisiones de diseño (aprobadas por el usuario)

1. **Contrato del fix — default-on + guarda de coincidencia.** El SDK borra con
   `history.replaceState` preservando `pathname + search`, pero **solo si la URL recibida coincide
   con el documento vivo** (`origin` + `pathname` + `search` + `hash`). Si la app pasa una URL
   guardada, o de un iframe/popup, no se toca ninguna ubicación. Protege a toda app existente sin
   cambio de integración: cualquier app que dependa de que el fragmento persista depende del bug.
2. **Sin API pública nueva.** La decisión fue la opción 1, no la 3: no se exporta un helper de
   limpieza en `index.ts`. La superficie pública del paquete no cambia.
3. **Evidencia de test — suite del SDK con entorno DOM (jsdom).** Se reescribe el criterio
   "Tests E2E verificando limpieza" del ticket: el E2E real no aplica (Cypress corre en el
   productor), así que la evidencia es un test en jsdom que asertta el hash limpio, `pathname` +
   `search` intactos y la guarda negativa. Se documenta por qué el E2E no aplica.
4. **Follow-up arquitectónico — proponer ticket, no crearlo.** La alternativa `postMessage` con
   `targetOrigin` estricto / ECDH efímero se documenta y se propone como ticket aparte. No se crea
   nada en Linear sin pedido explícito.
5. **Release — decisión aparte, sin bump en este MR.** No se toca `version` (0.2.3) ni se publica.
   Se reporta que las apps externas necesitan release y la decisión queda para el usuario.

### Punto de borrado elegido

Inmediatamente después de extraer `frag` a una variable local (l.222), **antes** del exchange async.
El string ya está en memoria; la URL no se vuelve a leer. Esto maximiza la ventana de mitigación:
el fragmento no espera a que termine el POST ni a la verificación del JWS. `replaceState` preserva
`pathname + search`, así que `code` y `state` no se pierden y el flujo no se rompe aunque el
exchange falle (la app re-lee el fragmento solo para el perfil, no para el código).

## Tareas

| # | Tarea | Estado |
|---|---|---|
| T1 | Configurar entorno DOM (jsdom) para la suite del SDK | done |
| T2 | Helper `eraseProfileFragmentIfCurrentDocument` con guarda de coincidencia | done |
| T3 | Cablear la limpieza en `SSOClient.handleCallback` | done |
| T4 | Tests jsdom: limpieza real, `search` intacto, guarda negativa, no-op | done (9 tests) |
| T5 | Test de cableado en `SSOClient.test.ts` (el fragmento se consume y se limpia) | done (1 test) |
| T6 | Verificación: `vitest run` + `typecheck` + mutation check | done |
| T7 | Commit de work-unit + evidencia en este documento | done — `c4cbe77` |

## Criterios de aceptación (del ticket)

- [ ] Fragmento `#profile=` **no permanece** accesible en `window.location.href` tras inicialización SDK
- [ ] Historial del navegador no muestra el fragmento
- [ ] `document.referrer` no filtra el perfil al navegar
- [ ] ~~Tests E2E verificando limpieza~~ → **reescrito**: test en jsdom (ver decisión 3). Cypress
      corre sobre `front/` (productor), no sobre el origen del consumidor externo.

## Archivos del cambio

| Archivo | Cambio |
|---|---|
| `packages/sso-client/src/client/profileFragmentCleanup.ts` | **nuevo** — helper con la guarda de coincidencia |
| `packages/sso-client/src/client/profileFragmentCleanup.test.ts` | **nuevo** — 9 tests, `// @vitest-environment jsdom` |
| `packages/sso-client/src/client/SSOClient.ts` | import + call de limpieza tras extraer `frag` (l.222-229) |
| `packages/sso-client/src/client/SSOClient.test.ts` | stub `window` extendido + 1 test de cableado |
| `packages/sso-client/package.json` | devDependency `jsdom ^22.1.0` |
| `pnpm-lock.yaml` | +3 líneas (importer de `packages/sso-client`) |

## Ubicación de la limpieza (verificada)

El call queda en `SSOClient.ts:229`, **antes** del `internalPost("oauth/token", ...)` de l.266. La
ventana de fuga es entonces la mínima posible: el fragmento no espera al POST ni a la verificación
del JWS. `replaceState` preserva `pathname + search`, así que `code` y `state` sobreviven y el flujo
no se rompe aunque el exchange falle.

## Evidencia de verificación

**Commit del work-unit:** `c4cbe77` — `fix(sso-client): erase the #profile= fragment from the live document after consuming it` (7 files, +491/-2), en `dowardev/lbds-250-p0-limpieza-inmediata-de-fragmento-profile`. El commit se creó con autorización explícita del usuario, porque `AGENTS.md` prohíbe commitear sin pedido.

Toda la verificación se hizo **en máquina quieta**, después de descubrir que las primeras corridas
estaban contaminadas (ver *F1*). Todo mutante temporal fue restaurado y verificado byte a byte con
`diff` contra copia de respaldo.

### Suite y typecheck

| Medición | Comando | Resultado |
|---|---|---|
| Baseline (sin el cambio, `git stash push -u`) | `npx vitest run` ×3 | **53 passed, 0 failed**, 3/3 corridas |
| Con el cambio | `npx vitest run` ×5 | **63 passed, 0 failed**, 5/5 corridas |
| Con el cambio (cierre, tras restaurar mutante) | `npx vitest run` | **63 passed, 0 failed** (10 files) |
| Typecheck | `pnpm run typecheck` | exit **0** |

### Mutation checks (los tests son discriminantes)

| Mutante | Qué se rompió | Tests que fallan | Restaurado |
|---|---|---|---|
| **A** — comentar el call en `SSOClient.ts` | el cableado | 1 (`SSOClient.test.ts`: `expected spy to be called 1 times, but got 0 times`) | sí, `diff` limpio |
| **B** — `return false` antes del `replaceState` | la limpieza real | 2 (positivo + idempotencia) | sí, `diff` limpio |
| **C** — neutralizar las 4 condiciones de la guarda (`if (false)`) | la guarda, que es la decisión de diseño central | **5** (idempotencia, origin, pathname, search, hash-sin-fragmento) | sí, `diff` reporta `IDENTICAL TO ORIGINAL` |

El mutante **C** lo corrí yo, no el writer: el writer había admitido explícitamente que no lo había
ejecutado, y era el hueco de evidencia más importante porque la guarda es justamente la decisión 1
aprobada por el usuario.

### Aislamiento de entorno

- El docblock `// @vitest-environment jsdom` aparece **solo** en
  `packages/sso-client/src/client/profileFragmentCleanup.test.ts` (`grep -rln`). El resto del paquete
  sigue en el entorno `node`, que es lo que necesitan los tests de crypto (`crypto.subtle`) y el
  stub manual de `window` de `SSOClient.test.ts`.
- **Ningún** archivo de config de vitest/vite fue modificado o creado (verificado con `git status`).

### F1 — Flake pre-existente de `ZkeClient` (analizado, NO causado por este cambio)

El reporte del writer decía "baseline 52 passed, 1 failed". Lo investigué en lugar de aceptarlo:

- El fallo es `src/crypto/ZkeClient.test.ts > unlockWithPassword...` con `Test timed out in 5000ms`.
- En **aislamiento** con el cambio aplicado: `npx vitest run src/crypto/ZkeClient.test.ts` → **3/3 pasan**.
- **Independencia estructural probada**: `src/crypto/ZkeClient.ts` y su test no importan `SSOClient`
  en ningún punto de su cadena de imports (verificado con `grep -n "^import"`; sus imports son
  `workerClient`, `profileCipher`, `profileFreshness`, `core`, `types`).
- **Causa real**: contención de CPU. Las 2 corridas que fallaron tuvieron `Duration 33s` y `47s` con
  `environment 5.9s/8.5s`, contra 7-10s de las corridas limpias — se solaparon con las suites que
  corría el subagente verificador en paralelo. Con la máquina quieta: **5/5 pasan**.
- **Veredicto**: flake de timing pre-existente (timeout de 5s en un test con PBKDF2 + worker),
  inducido por carga y no por este cambio. *Riesgo residual honesto*: agregar un 10º archivo cambia
  el scheduling del suite y podría volver el timeout marginalmente más probable bajo carga extrema;
  no pude excluir eso al 100%, pero 5/5 en quieto + independencia estructural lo hacen improbable.
  No se toca el test ajeno (sería scope creep): queda como observación.

### Verificación no delegada

Los dos subagentes que usé fallaron por el runtime y no por la tarea
(`gentle-ai-explore`: *process cleanup unconfirmed* — igual devolvió el mapa completo;
`gentle-ai-verify`: *assistant reported an error* en dos intentos). La verificación de T6 la ejecuté
directamente en la sesión padre, con los comandos y resultados de arriba. Además de icónico, esto
tenía un motivo de seguridad concreto: el verificador tenía permitido mutar archivos y
`profileFragmentCleanup.ts` es **untracked**, así que no había forma de diffearlo contra un baseline
para detectar un mutante olvidado. Verifiqué la restauración copiando los archivos a respaldo antes
cualquier mutación y comparando con `diff` al final.

## Review fresco y triage

### Review nativo (RDD) — BLOQUEADO por el entorno

RDD está `on` (`global: on`). El usuario **no** dejó el target explícitamente sin revisar, así que
correspondía inspeccionar. `gentle_review` START falla y **no se creó lineage ni hubo mutación**
(`candidates: []` y `counts.lineages: 0` en las dos reconciliaciones). Cuatro llamadas:

| Llamada | Resultado |
|---|---|
| `inspect` (×2) | `action: start`, target `sha256:38de0e16…`, `candidates: []` |
| `start` con `{"mode":"ordinary","baseRef":"e13b48b…","committedOnly":true}` | `error_code: schema-incompatible`, `mutation_outcome: unknown` |
| `start` con `{"mode":"ordinary"}` | `schema-incompatible` |
| `start` sin `input` | `Review controller start requires input` |
| `start` con `{"baseRef":…,"committedOnly":true}` (sin `mode`) | `Judgment Day graph-v1 START requires lineageId` |

La ruta que el propio `inspect` ofrece falla **con esos mismos parámetros**, así que es una
incompatibilidad de versión entre la CLI nativa y la fachada de Pi, no un problema de `input`.
Mismo precedente ya registrado para LBDS-253. Corté el sondeo en vez de seguir permutando formas:
el contrato prohíbe inventar valores nativos. Para desbloquear hay que arreglar el entorno
(`gentle-ai` sync/versión) y reintentar `inspect` → `start` desde cero.

### Fresh review (obligatorio al quedar el candidato unreviewed)

Precedencia del skill de pre-merge review: si el candidato queda unreviewed, el fresh review es
obligatorio. Se corrió con un agente en contexto fresco (`gentle-ai-verify`, 30 turnos / 47 tool
calls), que leyó el source vendored de `vue-router@4.6.4` y `@ionic/vue-router@8.8.13`.
Resultado: **7 hallazgos introducidos y 1 pre-existente**.

### Triage — decisiones del usuario

| id | ubicación | severidad | Decisión | Estado |
|---|---|---|---|---|
| **F1** `history.state` borrado → rompe el bookkeeping de vue-router/Ionic y el delta de `popstate` da `NaN` | `profileFragmentCleanup.ts:60-65` | media | **Fix ahora** | fixed |
| **F2** `replaceState` sin try/catch → `SecurityError` escapa de `handleCallback` antes del canje de token | `profileFragmentCleanup.ts:60-65` | media | **Fix ahora** | fixed |
| **F3** guard fail-open, silencioso para el llamador | `profileFragmentCleanup.ts:46-53` + `SSOClient.ts:229` | baja-media | Aceptar en base (es el diseño aprobado, decisión 1) | accepted |
| **F4** perfil `null` si la página crashea entre la captura y el POST | `SSOClient.ts:220-230` | baja | Aceptar en base | accepted |
| **F6** docblock con afirmación de seguridad falsa sobre `document.referrer` | `profileFragmentCleanup.ts:9-10` | baja | **Fix ahora** | fixed |
| **F7** stub de `window` compartido entre `describe`s con el reset en uno solo | `SSOClient.test.ts:169-181` | baja | Aceptar en base | accepted |
| **F8** test de cableado con `replaceState` mockeado (cobertura partida) | `SSOClient.test.ts:242-266` | baja | Aceptar en base | accepted |
| F5 matcher solo reconoce `#profile=` como primer segmento del hash | `SSOClient.ts:220-223` | baja | **Pre-existente** — fuera del gate (ya documentado, riesgo 3) | n/a |

### Verificación del fix round (F1, F2, F6)

| Medición | Resultado |
|---|---|
| Suite completa con los fixes | 65 tests, **64 passed** — el único fallo es el flake de `ZkeClient` |
| Tests targeted (`profileFragmentCleanup.test.ts` + `SSOClient.test.ts`) | **32 passed, 0 failed** |
| `pnpm run typecheck` | exit **0** |
| Mutante A — volver el `state` a `{}` | **2 fallos**: el test jsdom de preservación de `history.state` y la aserción de cableado |
| Mutante B — `catch` sin `return false` | **1 fallo**: `returns false and never throws when replaceState throws` |
| Restauración tras cada mutante | `diff` → `IDENTICAL TO PRE-MUTATION` |

**Nota de ejecución (fallback reportado).** El writer delegado aplicó F1 y F6 correctamente y
escribió los dos tests nuevos, pero **no aplicó F2**: dejó un comentario que decía "Swallow it"
sin el `try`/`catch` — un comentario que describía un comportamiento inexistente. Además el runtime
**perdió la tarea** (`no task muaiub9v-5-pp5g`) antes de verificar. Cerré F2 en la sesión padre, que
es el fallback que corresponde cuando el runtime de subagentes no sostiene la tarea.

El primer intento de mutante B apuntó al **`catch` equivocado** (el del parseo de URL) porque usé un
`perl` sin `/g`, que reemplaza la primera ocurrencia. Lo detecté justamente porque el test que falló
no era el esperado; lo rehice anclado al `return true`. De paso confirmó que el `catch` del parseo
también es load-bearing. Ese error quedó visible en vez de tapado.

### Flake de `ZkeClient` — causa confirmada

`load average: 24.32`. Con esa carga, `npx vitest run src/crypto/ZkeClient.test.ts` **en aislamiento**
falla 1 de 3 corridas — y en aislamiento mi archivo nuevo no participa en absoluto. Queda cerrada la
pregunta abierta en F1-original: es artefacto de carga de la máquina, no del diff ni del archivo
jsdom agregado.

### Re-review acotado del delta (post-fix)

Regla aplicada: si el diff cambió, el triage anterior queda stale → re-review **acotado al delta**
(`git diff 5cfa9cc..HEAD`, un commit), no un pase completo. Resultado: **sin defectos, regresiones ni
cambios de comportamiento nuevos**.

Verificado limpio por el revisor: F1 implementado y seguro en los bordes (`??` y no `||`, así que
estados falsy válidos como `0` o `""` se preservan; `history.state` siempre es clonable en un browser
real, y pasar `null` cuando no hay estado es estrictamente más correcto que `{}`); F2 bien ubicado
(el `try` envuelve sólo la invocación, después de la guarda y del parseo; el call site descarta el
valor y no tiene efecto sobre `false`); F6 implementado; los tres tests nuevos discriminantes.

**OBS-1 (informativo, resuelto en la misma ronda).** La misma afirmación falsa sobre `referrer`
sobrevivía en el comentario del call site (`SSOClient.ts:226`, introducido por `c4cbe77`): F6 había
cubierto sólo el docblock del helper. No se abrió una ronda de triage nueva porque es **el mismo F6
incompleto**, y la decisión "fix F6" del usuario ya lo cubre; se completó en ambos lugares.

## Riesgos residuales y follow-ups (propuestos, NO creados)

1. **Publicación a npm.** El paquete publicado es 0.2.3 y `files: ["dist"]`. `front/` está protegido
   ya (consume el source por alias), pero las **apps OAuth externas** solo quedan protegidas tras
   publicar una versión nueva. Decisión de release del usuario, fuera de este MR (decisión 5).
2. **Follow-up arquitectónico (decisión 4).** Reemplazar el canal `#profile=` por `window.postMessage`
   con `targetOrigin` estricto (flujos popup/iframe) o por un intercambio ECDH efímero eliminaría la
   clase entera de fuga en vez de mitigarla. Propuesto como ticket aparte; no creado.
3. **Matcher del fragmento (pre-existente, fuera de alcance).** `SSOClient.ts:219-221` solo reconoce
   `#profile=` cuando es el **primer segmento completo** del hash
   (`urlObj.hash.startsWith("#profile=")`). Si el callback trae otro parámetro antes en el hash, el
   fragmento ni se lee ni se borra. No lo introduce este cambio y arreglarlo cambia el contrato de
   lectura; queda documentado.
4. **`document.referrer` NO es un vector de fuga del fragmento** (corregido tras el review, hallazgo
   F6). Los fragmentos de URL se excluyen del header `Referer` por especificación, así que el
   fragmento nunca viaja ahí. La premisa del ticket LBDS-250 que lo afirma es **incorrecta**, y el
   tercer criterio de aceptación no describe una amenaza existente. Los dos vectores reales son
   (1) el fragmento visible y copiable en `window.location` del documento del callback, y (2) la
   entrada del historial que la navegación de redirect creó con el fragmento. El fix mitiga ambos;
   el criterio de referrer se cumple de forma trivial, no "por construcción del fix".

## Criterios de aceptación — estado

- [x] Fragmento `#profile=` no permanece accesible en `window.location.href` tras consumirlo
      (test positivo en jsdom + test de cableado; mutantes B y C lo hacen fallar)
- [x] Historial del navegador no muestra el fragmento (`replaceState` reescribe la entrada existente,
      no crea una nueva) — por construcción, ver riesgo 4
- [x] `document.referrer` no filtra el perfil al navegar — **trivial y no por el fix**: los
      fragmentos se excluyen del header `Referer` por spec. La premisa del ticket es incorrecta
      (ver riesgo 4 / hallazgo F6)
- [~] ~~Tests E2E verificando limpieza~~ → reescrito por decisión 3: test en jsdom. Cypress corre
      sobre `front/` (productor), no sobre el origen del consumidor externo.
- [x] Sin API pública nueva (decisión 2): `src/index.ts` no se tocó
