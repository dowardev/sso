# Arquitectura de Autenticación y Cifrado

<!-- markdownlint-disable MD013 MD060 -->

Referencia completa del sistema de autenticación del SSO: flujos de
autenticación, esquema de cifrado zero-knowledge, jerarquía de claves, modelo
de datos y propiedades de seguridad.

> 🇬🇧 English version: [AUTHENTICATION.md](./AUTHENTICATION.md)

- **Ticket Linear:** LBDS-185
- **Núcleo criptográfico:** Web Crypto API dentro de un Web Worker dedicado (frontend)
- **Rol del backend:** almacena blobs opacos; nunca deriva claves, descifra, ni ve datos en claro

---

## Tabla de Contenidos

1. [Niveles de Cifrado](#1-niveles-de-cifrado)
2. [Jerarquía de Claves](#2-jerarquía-de-claves)
3. [Modelo de Datos](#3-modelo-de-datos)
4. [Primitivas Criptográficas](#4-primitivas-criptográficas)
5. [Flujos](#5-flujos)
   - 5.1 [Registro y Login Básicos](#51-registro-y-login-básicos)
   - 5.2 [Registro Avanzado](#52-registro-avanzado-zero-knowledge)
   - 5.3 [Login Avanzado](#53-login-avanzado-dos-pasos)
   - 5.4 [Passkeys (WebAuthn)](#54-passkeys-webauthn)
   - 5.5 [Recuperación vía Frase Semilla](#55-recuperación-vía-frase-semilla)
   - 5.6 [Cambio de Contraseña](#56-cambio-de-contraseña)
   - 5.7 [Migración Básico → Avanzado](#57-migración-básico--avanzado)
6. [Claims del JWT](#6-claims-del-jwt)
7. [Política de Invalidación de Sesiones](#7-política-de-invalidación-de-sesiones)
8. [Propiedades de Seguridad](#8-propiedades-de-seguridad)
9. [Limitaciones Conocidas](#9-limitaciones-conocidas)
10. [Mapa de Archivos](#10-mapa-de-archivos)

---

## 1. Niveles de Cifrado

Cada cuenta tiene un `encryption_level` que determina cómo se almacenan sus
datos de perfil y cómo funciona la autenticación.

| | **Básico** | **Avanzado** |
|---|---|---|
| Almacenamiento del perfil | Columnas en texto plano (`name`, `user`, `email`) | Un único blob cifrado (`encrypted_profile`) |
| Manejo de contraseña | El servidor hashea la password con Argon2id | El servidor solo ve una *prueba* de autenticación (HMAC), hasheada con Argon2id |
| Lo que sabe el servidor | Nombre, email, usuario | Nada del usuario, salvo un `login_id` opaco |
| Recuperación de cuenta | Vía email | Frase semilla (24 palabras BIP39), mostrada **una sola vez** al registrarse |
| Clave de búsqueda | Columnas `email` o `user` | `login_id` = HMAC(usuario) calculado en el cliente |

El nivel se elige al registrarse y puede subirse después
([§5.7](#57-migración-básico--avanzado)). No existe camino de bajada.

---

## 2. Jerarquía de Claves

Tres claves sostienen el esquema avanzado. Todas viven exclusivamente en la
memoria del cliente (por pestaña); ninguna se persiste en claro ni viaja al
servidor.

```text
┌──────────────────┐        ┌──────────────────────┐
│  Password del    │        │   Frase semilla       │
│  usuario         │        │   (24 palabras BIP39, │
│  (nunca se envía)│        │    nunca se envía)    │
└────────┬─────────┘        └──────────┬────────────┘
         │ PBKDF2-SHA256               │ PBKDF2-SHA256
         │ ≥1.000.000 iteraciones      │ ≥1M iteraciones
         │ + salt_a                    │ + salt_r
         ▼                             ▼
┌──────────────────┐        ┌──────────────────────┐
│      Key_A       │        │       Key_R          │
│  clave de uso    │        │  clave de recuperación│
│  diario          │        │                      │
│  (AES-KW, 256)   │        │  (AES-KW, 256)       │
└────────┬─────────┘        └──────────┬───────────┘
         │ AES-KW wrap                 │ AES-KW wrap
         ▼                             ▼
       ┌─────────────────────────────────┐
       │              DEK                │
       │  Data Encryption Key (aleatoria │
       │  de 256 bits, AES-GCM)          │
       └───────────────┬─────────────────┘
                       │ AES-256-GCM
                       ▼
             ┌───────────────────┐
             │ encrypted_profile │  {name, email, user}
             └───────────────────┘
```

### Key_A — uso diario

- Derivada de la password de login + `salt_a` (PBKDF2-SHA256, 1M iteraciones).
- Tiene dos usos:
  - **Desenvolver** el DEK en el login (AES-KW).
  - Calcular la **prueba de autenticación**: `auth_proof = HMAC(Key_A, "auth")`.
    El servidor guarda `Argon2id(auth_proof)` en la columna `password`, así que
    jamás conoce la contraseña real.
- Vive en la memoria del worker durante la sesión de la pestaña; se limpia en
  logout o al cerrar la pestaña.

### Key_R — recuperación

- Derivada de la frase semilla + `salt_r` (PBKDF2-SHA256, 1M iteraciones).
- Segunda envoltura del mismo DEK. Si se olvida la contraseña, la frase
  semilla reconstruye Key_R y desenvuelve el DEK ([§5.5](#55-recuperación-vía-frase-semilla)).
- Se verifica sin revelar la frase mediante
  `seed_verification = HMAC(Key_R, "verify")` almacenado en el servidor.

### DEK — clave de cifrado de datos

- Clave AES-GCM aleatoria de 256 bits generada en el registro/upgrade.
- Cifra el blob del perfil. Nunca se almacena directamente — solo sus dos
  formas envueltas (`wrapped_dek_a`, `wrapped_dek_r`).

> **Nota de implementación:** una clave de Web Crypto está atada a un
> algoritmo, así que Key_A / Key_R se derivan como claves AES-KW con
> `extractable: true` y sus bytes crudos se re-importan como claves HMAC al
> calcular las pruebas. El DEK también es extractable porque el wrapping
> AES-KW requiere acceso al material crudo de la clave.

---

## 3. Modelo de Datos

Columnas nuevas agregadas a `users` por la migración
`2026_08_23_202600_add_encryption_columns_to_users_table.php`:

| Columna | Tipo | Propósito |
|---|---|---|
| `login_id` | string, unique, nullable | HMAC del usuario. Única clave de búsqueda para usuarios avanzados. |
| `encryption_level` | enum `basic\|advanced`, default `basic` | Define el comportamiento en todo el sistema. |
| `salt_a` | string, nullable | Sal Base64 para derivar Key_A. Pública. |
| `salt_r` | string, nullable | Sal Base64 para derivar Key_R. Pública. |
| `wrapped_dek_a` | text, nullable | JSON `{wrapped, algorithm}` — DEK envuelto por Key_A. |
| `wrapped_dek_r` | text, nullable | JSON `{wrapped, algorithm}` — DEK envuelto por Key_R. |
| `encrypted_profile` | longText, nullable | JSON `{ciphertext, iv, authTag}` — blob AES-GCM. |
| `encrypted_seed_verification` | string, nullable | Base64 de `HMAC(Key_R, "verify")`. Comparado de forma timing-safe. |
| `passkey_public_key` | string, nullable | Clave pública COSE Base64 de la credencial passkey. |
| `passkey_credential_id` | string, nullable | ID de credencial WebAuthn en Base64. |

Columnas hechas nullable para soportar usuarios avanzados (que no tienen
perfil en texto plano): `name`, `user`, `email`, `password`.

Para usuarios avanzados la columna `password` guarda `Argon2id(auth_proof)` —
nunca la contraseña real.

Todas las columnas criptográficas están en `$hidden` del modelo para que
jamás se serialicen por accidente en respuestas de API.

---

## 4. Primitivas Criptográficas

| Primitiva | Algoritmo | Parámetros | Usada para |
|---|---|---|---|
| KDF | PBKDF2-HMAC-SHA256 | ≥1.000.000 iteraciones, sal de 32 bytes | Derivar Key_A / Key_R |
| Envoltura de clave | AES-KW | KEK de 256 bits | Envolver/desenvolver el DEK |
| AEAD | AES-256-GCM | IV aleatorio de 96 bits, tag de 128 bits | Cifrado del perfil |
| MAC | HMAC-SHA256 | contextos `"auth"`, `"verify"` | Prueba de auth, verificación de semilla |
| Hash de contraseña | Argon2id | defaults de Laravel | Hashear pruebas de auth en el servidor |
| RNG | Solo CSPRNG | `crypto.getRandomValues` / entropía BIP39 | Sales, IVs, semillas, challenges |

Todo corre en `front/src/workers/cryptoWorker.ts` para que el PBKDF2 pesado
no bloquee el hilo de UI y el material de claves quede fuera del scope de JS
del hilo principal.

Las frases semilla se generan con `@scure/bip39`
(`generateMnemonic(wordlist, 256)`), que usa el CSPRNG de la plataforma y
produce mnemónicos de 24 palabras con checksum.

---

## 5. Flujos

### 5.1 Registro y Login Básicos

Sin cambios respecto al comportamiento previo a ZKE:

1. `POST /register` — el servidor valida, `Hash::make(password)`, guarda el
   perfil en texto plano, envía email de verificación.
2. `POST /login` — `Hash::check(password)`, gate de email verificado (403
   `EMAIL_NOT_VERIFIED` si está pendiente), emite JWT + refresh token.

### 5.2 Registro Avanzado (zero-knowledge)

Todo lo anterior al paso 9 sucede en el navegador:

```text
1. Usuario elige "Avanzado", ingresa usuario / email / password (mín. 12 caracteres)
2. Generar DEK                                  (256 bits aleatorios)
3. Generar salt_a, salt_r                       (CSPRNG, 32B c/u, base64)
4. Key_A = PBKDF2(password + salt_a, 1M)
5. seed_phrase = BIP39(entropía de 256 bits)    → se muestra UNA VEZ al usuario
6. Key_R  = PBKDF2(seed_phrase + salt_r, 1M)
7. wrapped_dek_a = AES-KW(Key_A, DEK)
   wrapped_dek_r = AES-KW(Key_R, DEK)
8. encrypted_profile = AES-GCM(DEK, {name, email, user})
   seed_verification  = HMAC(Key_R, "verify")
   auth_proof         = HMAC(Key_A, "auth")
   login_id           = HMAC(usuario)            (pepper fijo v1)

9. POST /register/advanced
   { login_id, auth_proof, salt_a, salt_r,
     wrapped_dek_a, wrapped_dek_r,
     encrypted_profile, encrypted_seed_verification }

10. Servidor: Argon2id(auth_proof) → columna password, guarda blobs,
    pone en null los campos de texto plano, envía email de verificación.
```

La frase semilla **nunca sale del dispositivo**. El paso 10 no recibe
material que permita revelarla.

### 5.3 Login Avanzado (dos pasos)

```text
Paso 1  POST /login/advanced/first-step { login_id }
        ← { salt_a, salt_r, wrapped_dek_a, encrypted_profile }

Cliente: Key_A = PBKDF2(password + salt_a, 1M)
         DEK    = AES-KW-unwrap(Key_A, wrapped_dek_a)
         profile= AES-GCM-open(DEK, encrypted_profile)     → UI lista
         proof  = HMAC(Key_A, "auth")

Paso 2  POST /login/advanced/verify { login_id, auth_proof }
        Servidor: Hash::check(auth_proof, columna_password)
        ← { token, token_type, expires_in, user }         (+ cookie refresh)
```

Una contraseña incorrecta falla silenciosamente en el paso de unwrap
(verificación de integridad GCM/KW) antes de cualquier llamada de red con
credenciales.

### 5.4 Passkeys (WebAuthn)

Autenticadores de plataforma (Touch ID, Windows Hello, llaves de seguridad)
como factor adicional o principal. Usa `web-auth/webauthn-lib` v5 en el
servidor.

| Endpoint | Propósito |
|---|---|
| `POST /passkey/register/start` | Devuelve `PublicKeyCredentialCreationOptions` (challenge en sesión) |
| `POST /passkey/register/finish` | Verifica la attestation, guarda id de credencial + clave pública |
| `POST /passkey/authenticate/start` | Devuelve `PublicKeyCredentialRequestOptions` acotado a las credenciales del usuario |
| `POST /passkey/authenticate/finish` | Verifica la assertion, emite JWT igual que el login por contraseña |
| `DELETE /passkey` | Elimina la credencial (requiere JWT) |

Notas:

- Las opciones de registro prefieren attachments de **plataforma** y exigen
  user verification.
- El login por passkey respeta el mismo gate de email verificado para
  usuarios avanzados.
- El frontend encapsula las llamadas crudas a `navigator.credentials` en
  `front/src/services/passkeyService.ts` (conversión base64url ↔ ArrayBuffer,
  encoding tipado de request/response).
- `Permissions-Policy` incluye `publickey-credentials-create=(self)` y
  `publickey-credentials-get=(self)` para que los navegadores permitan los
  diálogos.

### 5.5 Recuperación vía Frase Semilla

Se usa cuando se pierde la contraseña. Dos endpoints, ambos con rate limiting.

```text
Paso 1  Cliente: obtener salt_r vía endpoint first-step
        Key_R = PBKDF2(seed_phrase + salt_r, 1M)
        candidato = HMAC(Key_R, "verify")

        POST /recovery/verify { login_id, seed_verification: candidato }
        Servidor: hash_equals(verification_almacenada, candidato)  (timing-safe)
        ← { salt_r, wrapped_dek_r, encrypted_profile }

Cliente: DEK = AES-KW-unwrap(Key_R, wrapped_dek_r)      ✓ identidad probada

El usuario ingresa NUEVA contraseña →

Paso 2  Cliente: nuevo_salt_a, nueva_Key_A = PBKDF2(nueva_password + nuevo_salt_a)
        nuevo_wrapped_dek_a = AES-KW(nueva_Key_A, DEK)
        re-cifrar perfil (mismo DEK)
        nuevo_auth_proof = HMAC(nueva_Key_A, "auth")

        POST /recovery/complete
        { login_id, auth_proof, salt_a,
          wrapped_dek_a, wrapped_dek_r, encrypted_profile }

Servidor: actualiza todos los blobs, Argon2id(nuevo_auth_proof),
        REVOCA todas las sesiones + refresh tokens.
```

Tras la recuperación el usuario hace login normal con la nueva contraseña.
La frase semilla y Key_R no rotan.

### 5.6 Cambio de Contraseña

Dos caminos distintos:

| | Básico | Avanzado |
|---|---|---|
| Endpoint | `PUT /user/password` | `POST /password/change-advanced` |
| Verifica | contraseña actual server-side | sesión activa (ya autenticado) |
| Rotación | nuevo hash Argon2id de la password | nuevo `salt_a` + nuevo `wrapped_dek_a` + nuevo auth_proof; **el DEK no cambia** |
| Sesiones | revocadas | revocadas |

La discrepancia de confirmación (`new_password_confirmation`) se valida en el
cliente en el camino avanzado antes de cualquier trabajo criptográfico.

### 5.7 Migración Básico → Avanzado

Migración unidireccional para cuentas existentes, desde la página de perfil:

```text
Lado cliente (toda la cripto):
1. Leer el perfil en texto plano de la sesión autenticada
2. Generar DEK, salt_a, salt_r, frase semilla, Key_A, Key_R
3. Envolver DEK con ambas claves, cifrar perfil,
   calcular seed verification + auth_proof + login_id

POST /user/upgrade   (requiere JWT)
{ current_password, auth_proof, login_id, salt_a, salt_r,
  wrapped_dek_a, wrapped_dek_r, encrypted_profile,
  encrypted_seed_verification }

Servidor:
- Verifica current_password contra el hash Argon2id existente
- Rechaza si ya es avanzado
- Escribe todos los blobs, pone en null los campos de texto plano
- Revoca todas las sesiones/tokens
```

La UI entonces muestra el modal de frase semilla (copiar al portapapeles +
"Ya la guardé" explícito) antes de continuar. Misma regla que en el registro:
**la frase semilla no va en el body del request.**

---

## 6. Claims del JWT

`JwtService::generateToken` es level-aware:

```jsonc
// usuario básico
{
  "iss": "https://sso...",
  "sub": "42",
  "user": { "id": 42, "name": "Ada", "email": "ada@x.com", "user": "ada" },
  // opcional: remember_me, cnf.jkt (DPoP)
}

// usuario avanzado
{
  "iss": "https://sso...",
  "sub": "42",
  "user": { "id": 42, "user": null },     // name/email omitidos
  "encryption_level": "advanced"
}
```

El binding DPoP (`cnf.jkt`) funciona igual para ambos niveles.

---

## 7. Política de Invalidación de Sesiones

Todas las sesiones y refresh tokens se revocan cuando:

| Evento | Dónde |
|---|---|
| Logout | `AuthController::logout` |
| Cambio de contraseña básico | `updateUserPassword` |
| Cambio de contraseña avanzado | `changePasswordAdvanced` |
| Recuperación de cuenta completada | `recoveryComplete` |
| Upgrade a avanzado | `upgradeToAdvanced` |
| Reuso de refresh token detectado (heurística de robo) | `refreshAccessToken` |

Los refresh tokens rotan en cada uso; el reuso de un token ya rotado revoca
toda la cadena.

---

## 8. Propiedades de Seguridad

Lo que el diseño garantiza:

1. **Perfil zero-knowledge.** Para usuarios avanzados el servidor solo
   almacena ciphertext y sales públicas; una filtración total de la base de
   datos expone ni el perfil, ni las contraseñas, ni la frase semilla.
2. **La contraseña nunca se transmite.** La autenticación usa
   HMAC(Key_A, "auth"), que es inútil offline sin la password con sal.
3. **Sin canal de exfiltración de la semilla.** La frase no aparece bajo
   ninguna circunstancia en los bodies de registro ni upgrade.
4. **Recuperación sin tutor.** El conocimiento de la frase semilla es
   suficiente por sí solo; soporte no puede recuperar cuentas.
5. **Comparación timing-safe de la semilla** (`hash_equals`) en
   `/recovery/verify`.
6. **Anti-enumeración:** un `login_id` desconocido devuelve 404
   indistinguible de otros fallos en el endpoint first-step.
7. **Claves fuera del storage persistente.** DEK/Key_A existen solo en la
   memoria del Web Worker durante la vida de la pestaña; logout y
   `clearCryptoState()` las limpian.

Advertencias del threat model (aceptadas para la Fase 1):

- Un XSS exitoso en el origen del SSO mientras la pestaña está desbloqueada
  puede alcanzar el worker vía la service API. CSP es Report-Only fuera de
  producción; forzarlo antes de exponer el modo avanzado públicamente.
- PBKDF2 con 1M iteraciones cuesta ~1–2 s en hardware medio; la UI muestra
  estado de progreso durante la derivación.
- `login_id` es determinístico por usuario (pepper local fijo). Previene la
  correlación casual pero no la confirmación dirigida si el atacante ya
  conoce el nombre de usuario.

---

## 9. Limitaciones Conocidas

Brechas documentadas a resolver en fases posteriores:

1. **Un passkey por usuario.** El esquema guarda un solo par de credenciales;
   múltiples dispositivos necesitan una tabla de credenciales propia.
2. **Sign-counter sin aplicar.** El contador guardado siempre es 0; la
   detección de autenticadores clonados está inactiva.
3. **Pepper de `login_id` estático.** Cambiar el username requiere un flujo
   de migración que aún no existe.
4. **Sin visibilidad admin de usuarios avanzados.** Los paneles admin muestran
   nulls para name/email por diseño; falta una UX admin para este caso.
5. **La recuperación no rota Key_R.** Si una frase semilla se filtra junto con
   una contraseña aún válida, no hay procedimiento de rotación todavía.

---

## 10. Mapa de Archivos

**Backend**

| Archivo | Rol |
|---|---|
| `back/database/migrations/2026_08_23_202600_add_encryption_columns_to_users_table.php` | Esquema |
| `back/app/Models/User.php` | Campos fillable/hidden criptográficos, accessor `is_advanced` |
| `back/app/Services/AuthService.php` | Lógica avanzada de register/login/recovery/cambio-password/upgrade |
| `back/app/Services/JwtService.php` | Generación de claims level-aware |
| `back/app/Services/PasskeyService.php` | Ceremonias WebAuthn (web-auth/webauthn-lib v5) |
| `back/app/Http/Controllers/AuthController.php` | Rutas `/login/*`, `/register/*`, `/recovery/*`, `/passkey/*`, `/user/*` |
| `back/config/passkey.php` | Configuración RP id/name/timeout |
| `back/tests/Feature/AdvancedEncryptionTest.php` | Cobertura feature de flujos advanced/recovery |

**Frontend**

| Archivo | Rol |
|---|---|
| `front/src/types/crypto.ts` | Tipos del dominio cripto (blobs, mensajes del worker, payloads de API) |
| `front/src/workers/cryptoWorker.ts` | Todas las primitivas: PBKDF2, AES-KW, AES-GCM, HMAC, BIP39 |
| `front/src/services/cryptoService.ts` | API Promise sobre el worker, estado de claves por pestaña |
| `front/src/services/passkeyService.ts` | Wrapper tipado de `navigator.credentials` |
| `front/src/stores/auth.ts` | Persistencia de sesión (`applyLoginSession`), estado de claves en memoria |
| `front/src/composables/authController.ts` | Orquestación de todos los flujos de §5 |
| `front/src/components/AuthLoginForm.vue` | Campo unificado de identificador (detección básico vs avanzado) |
| `front/src/views/RegisterPage.vue` | Selector de nivel de cifrado, modal de semilla |
| `front/src/components/user/ProfileForm.vue` | Tarjeta de upgrade + modal de semilla |
