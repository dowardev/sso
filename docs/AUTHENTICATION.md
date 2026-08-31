# Authentication & Encryption Architecture

<!-- markdownlint-disable MD013 MD060 -->

Complete reference for the SSO authentication system: authentication flows,
the zero-knowledge encryption scheme, key hierarchy, data model, and security
properties.

- 🇪🇸 Spanish version: [AUTENTICACION.md](./AUTENTICACION.md)
- **Crypto core:** Web Crypto API inside a dedicated Web Worker (frontend)
- **Backend role:** stores opaque blobs; never derives, decrypts, or sees keys

---

## Table of Contents

1. [Encryption Levels](#1-encryption-levels)
2. [Key Hierarchy](#2-key-hierarchy)
3. [Data Model](#3-data-model)
4. [Cryptographic Primitives](#4-cryptographic-primitives)
5. [Flows](#5-flows)
   - 5.1 [Basic Registration & Login](#51-basic-registration--login)
   - 5.2 [Advanced Registration](#52-advanced-registration-zero-knowledge)
   - 5.3 [Advanced Login](#53-advanced-login-two-step)
   - 5.4 [Passkey Registration & Login](#54-passkeys-webauthn)
   - 5.5 [Recovery via Seed Phrase](#55-recovery-via-seed-phrase)
   - 5.6 [Password Change](#56-password-change)
   - 5.7 [Basic → Advanced Upgrade](#57-basic--advanced-upgrade)
   - 5.8 [OAuth Identity for Advanced Users](#58-oauth-identity-for-advanced-users)
6. [JWT Claims](#6-jwt-claims)
7. [Session Invalidation Policy](#7-session-invalidation-policy)
8. [Security Properties](#8-security-properties)
9. [Known Limitations](#9-known-limitations)
10. [File Map](#10-file-map)

---

## 1. Encryption Levels

Every user account has an `encryption_level` that determines how its profile
data is stored and how authentication works.

| | **Basic** | **Advanced** |
|---|---|---|
| Profile storage | Plaintext columns (`name`, `user`, `email`) | Single encrypted blob (`encrypted_profile`) |
| Password handling | Server hashes password with Argon2id | Server only ever sees an auth *proof* (HMAC), hashed with Argon2id |
| Server knowledge | Knows name, email, username | Knows nothing about the user except an opaque `login_id` |
| Account recovery | Email-based | Seed phrase (24 BIP39 words) shown **once** at registration |
| Lookup key | `email` or `user` column | `login_id` = HMAC(username) computed client-side |

The level is chosen at registration time and can be raised later
([§5.7](#57-basic--advanced-upgrade)). There is no downgrade path.

---

## 2. Key Hierarchy

Three keys power the advanced scheme. All of them live exclusively in client
memory (tab-scoped); none is ever persisted in plaintext or sent to the server.

```text
┌──────────────────┐        ┌──────────────────────┐
│  User password   │        │   Seed phrase         │
│  (never sent)    │        │   (24 BIP39 words,    │
└────────┬─────────┘        │    never sent)        │
         │ PBKDF2-SHA256    └──────────┬────────────┘
         │ ≥1,000,000 iters            │ PBKDF2-SHA256
         │ + salt_a                    │ ≥1M iters + salt_r
         ▼                             ▼
┌──────────────────┐        ┌──────────────────────┐
│      Key_A       │        │       Key_R          │
│  daily-use key   │        │  recovery key        │
│  (AES-KW, 256)   │        │  (AES-KW, 256)       │
└────────┬─────────┘        └──────────┬───────────┘
         │ AES-KW wrap                 │ AES-KW wrap
         ▼                             ▼
       ┌─────────────────────────────────┐
       │              DEK                │
       │  Data Encryption Key (random    │
       │  256-bit, AES-GCM)              │
       └───────────────┬─────────────────┘
                       │ AES-256-GCM
                       ▼
             ┌───────────────────┐
             │ encrypted_profile │  {name, email, user}
             └───────────────────┘
```

### Key_A — daily use

- Derived from the login password + `salt_a` (PBKDF2-SHA256, 1M iterations).
- Used for two purposes:
  - **Unwrap** the DEK at login (AES-KW).
  - Compute the **auth proof**: `auth_proof = HMAC(Key_A, "auth")`. The server
    stores `Argon2id(auth_proof)` in the `password` column, so the server never
    learns the password itself.
- Lives in worker memory for the tab session; cleared on logout/tab close.

### Key_R — recovery

- Derived from the seed phrase + `salt_r` (PBKDF2-SHA256, 1M iterations).
- Second wrapper of the same DEK. If the password is forgotten, the seed
  phrase reconstructs Key_R and unwraps the DEK ([§5.5](#55-recovery-via-seed-phrase)).
- Verified without revealing the seed through
  `seed_verification = HMAC(Key_R, "verify")` stored server-side.

### DEK — data encryption key

- Random 256-bit AES-GCM key generated at registration/upgrade.
- Encrypts the profile blob. Never stored directly — only its two wrapped
  forms (`wrapped_dek_a`, `wrapped_dek_r`).

> **Implementation note:** a Web Crypto key is algorithm-bound, so Key_A /
> Key_R are derived as AES-KW keys with `extractable: true` and their raw
> bytes re-imported as HMAC keys when computing proofs. The DEK is also
> extractable because AES-KW wrapping requires access to raw key material.

---

## 3. Data Model

New columns added to `users` by
`2026_08_23_202600_add_encryption_columns_to_users_table.php`:

| Column | Type | Purpose |
|---|---|---|
| `login_id` | string, unique, nullable | HMAC(username). Only lookup key for advanced users. |
| `encryption_level` | enum `basic\|advanced`, default `basic` | Selects behavior everywhere. |
| `salt_a` | string, nullable | Base64 salt for Key_A derivation. Public. |
| `salt_r` | string, nullable | Base64 salt for Key_R derivation. Public. |
| `wrapped_dek_a` | text, nullable | JSON `{wrapped, algorithm}` — DEK wrapped by Key_A. |
| `wrapped_dek_r` | text, nullable | JSON `{wrapped, algorithm}` — DEK wrapped by Key_R. |
| `encrypted_profile` | longText, nullable | JSON `{ciphertext, iv, authTag}` — AES-GCM blob. |
| `encrypted_seed_verification` | string, nullable | Base64 `HMAC(Key_R, "verify")`. Compared timing-safely. |
| `passkey_public_key` | string, nullable | Base64 COSE public key of the passkey credential. |
| `passkey_credential_id` | string, nullable | Base64 WebAuthn credential ID. |

Columns made nullable to support advanced users (who have no plaintext
profile): `name`, `user`, `email`, `password`.

For advanced users the `password` column stores `Argon2id(auth_proof)` —
never the actual password.

All crypto columns are in the model's `$hidden` so they never serialize into
API responses by accident.

---

## 4. Cryptographic Primitives

| Primitive | Algorithm | Parameters | Used for |
|---|---|---|---|
| KDF | PBKDF2-HMAC-SHA256 | ≥1,000,000 iterations, 32-byte salt | Deriving Key_A / Key_R |
| Key wrap | AES-KW | 256-bit KEK | Wrapping/unwrapping DEK |
| AEAD | AES-256-GCM | 96-bit random IV, 128-bit tag | Profile encryption |
| MAC | HMAC-SHA256 | context strings `"auth"`, `"verify"` | Auth proof, seed verification |
| Password hash | Argon2id | Laravel defaults | Hashing auth proofs server-side |
| RNG | CSPRNG only | `crypto.getRandomValues` / BIP39 entropy | Salts, IVs, seeds, challenges |

Everything runs in `front/src/workers/cryptoWorker.ts` so heavy PBKDF2 does
not block the UI thread and key material stays out of main-thread JS scope.

Seeds are generated with `@scure/bip39` (`generateMnemonic(wordlist, 256)`),
which uses the platform CSPRNG and produces checksummed 24-word mnemonics.

---

## 5. Flows

### 5.1 Basic Registration & Login

Unchanged from the pre-ZKE behavior:

1. `POST /register` — server validates, `Hash::make(password)`, stores
   plaintext profile, sends verification email.
2. `POST /login` — `Hash::check(password)`, email-verified gate (403
   `EMAIL_NOT_VERIFIED` if pending), issues JWT + refresh token.

### 5.2 Advanced Registration (zero-knowledge)

Everything before step 9 happens in the browser:

```text
1. User picks "Advanced", enters username / email / password (min 12 chars)
2. Generate DEK                                  (random 256-bit)
3. Generate salt_a, salt_r                       (CSPRNG, 32B each, base64)
4. Key_A = PBKDF2(password + salt_a, 1M)
5. seed_phrase = BIP39(256-bit entropy)          → shown ONCE to user
6. Key_R  = PBKDF2(seed_phrase + salt_r, 1M)
7. wrapped_dek_a = AES-KW(Key_A, DEK)
   wrapped_dek_r = AES-KW(Key_R, DEK)
8. encrypted_profile = AES-GCM(DEK, {name, email, user})
   seed_verification  = HMAC(Key_R, "verify")
   auth_proof         = HMAC(Key_A, "auth")
   login_id           = HMAC(username)           (static pepper v1)

9. POST /register/advanced
   { login_id, auth_proof, salt_a, salt_r,
     wrapped_dek_a, wrapped_dek_r,
     encrypted_profile, encrypted_seed_verification }

10. Server: Argon2id(auth_proof) → password column, stores blobs,
    nullifies plaintext fields, sends verification email.
```

The seed phrase **never leaves the device**. Step 10 receives no material
that could reveal it.

### 5.3 Advanced Login (two-step)

```text
Step 1  POST /login/advanced/first-step { login_id }
        ← { salt_a, salt_r, wrapped_dek_a, encrypted_profile }

Client: Key_A = PBKDF2(password + salt_a, 1M)
        DEK    = AES-KW-unwrap(Key_A, wrapped_dek_a)
        profile= AES-GCM-open(DEK, encrypted_profile)     → UI ready
        proof  = HMAC(Key_A, "auth")

Step 2  POST /login/advanced/verify { login_id, auth_proof }
        Server: Hash::check(auth_proof, password_column)
        ← { token, token_type, expires_in, user }         (+ refresh cookie)
```

Wrong password fails silently at the unwrap step (GCM/KW integrity check)
before any network call with credentials.

### 5.4 Passkeys (WebAuthn)

Platform authenticators (Touch ID, Windows Hello, security keys) as either
an additional or primary factor. Uses `web-auth/webauthn-lib` v5 server-side.

| Endpoint | Purpose |
|---|---|
| `POST /passkey/register/start` | Returns `PublicKeyCredentialCreationOptions` (challenge stored in session) |
| `POST /passkey/register/finish` | Verifies attestation, stores credential id + public key |
| `POST /passkey/authenticate/start` | Returns `PublicKeyCredentialRequestOptions` scoped to the user's credentials |
| `POST /passkey/authenticate/finish` | Verifies assertion, issues JWT exactly like password login |
| `DELETE /passkey` | Removes the credential (requires JWT) |

Notes:

- Registration options prefer **platform** attachments and require user
  verification.
- Passkey login respects the same email-verification gate for advanced users.
- The frontend wraps raw `navigator.credentials` calls in
  `front/src/services/passkeyService.ts` (base64url ↔ ArrayBuffer conversion,
  typed request/response encoding).
- `Permissions-Policy` includes `publickey-credentials-create=(self)` and
  `publickey-credentials-get=(self)`.

#### Enrollment & usage model

Passkeys are **opt-in and self-service** (managed by the authenticated user,
never admin-imposed). There is no admin route; `DELETE /passkey` is `jwt.auth`
and operates on the caller's own credential.

- **Basic users — passkey as an additional factor (2FA):** login is
  `username + password`, and the passkey challenge is requested *after* the
  password is verified (GitLab-style). The server already knows which user
  authenticated, so it can scope `authenticate/start` to that account.
- **Advanced (ZKE) users — passkey as primary factor:** the passkey identifies
  and authenticates the user via its `userHandle` (no username/password typed
  for auth). The **master password is still required client-side** to derive
  `Key_A` and decrypt the vault — unless PRF is used to derive the key
  material directly from the authenticator. Without PRF, advanced login is
  effectively `passkey + master password`.

#### Session-based authentication ceremony

The passkey authentication endpoints rely on a server-side session:
`/passkey/authenticate/start` stores the challenge in `$request->session()`
and `/passkey/authenticate/finish` reads it back. If the session is missing
between the two calls the finish returns `400 Sesión de autenticación
expirada`. The browser session cookie must therefore persist across the
`start` → `finish` pair (same-origin requests include it automatically).

**2FA token handling:** `/passkey/authenticate/finish` issues its own JWT.
For 2FA (password + passkey / master password + passkey) the frontend keeps
the session token issued by the primary login and uses the passkey as a
confirmation factor (the `finish` assertion is still verified server-side).
This preserves the ZKE advanced session’s DPoP binding. A user without a
passkey enrolled (the start step returns 400 "no tiene passkeys") skips the
step, so password-only login continues.

### 5.5 Recovery via Seed Phrase

Used when the password is lost. Two endpoints, both rate-limited.

```text
Step 1  Client: fetch salt_r via first-step endpoint
        Key_R = PBKDF2(seed_phrase + salt_r, 1M)
        candidate = HMAC(Key_R, "verify")

        POST /recovery/verify { login_id, seed_verification: candidate }
        Server: hash_equals(stored_verification, candidate)   (timing-safe)
        ← { salt_r, wrapped_dek_r, encrypted_profile }

Client: DEK = AES-KW-unwrap(Key_R, wrapped_dek_r)      ✓ identity proven

User enters NEW password →

Step 2  Client: new_salt_a, new_Key_A = PBKDF2(new_password + new_salt_a)
        new_wrapped_dek_a = AES-KW(new_Key_A, DEK)
        re-encrypt profile (same DEK)
        new_auth_proof = HMAC(new_Key_A, "auth")

        POST /recovery/complete
        { login_id, auth_proof, salt_a,
          wrapped_dek_a, wrapped_dek_r, encrypted_profile }

Server: updates all blobs, Argon2id(new_auth_proof),
        REVOKES every session + refresh token.
```

After recovery the user logs in normally with the new password. The seed
phrase and Key_R do not rotate.

### 5.6 Password Change

Two distinct paths:

| | Basic | Advanced |
|---|---|---|
| Endpoint | `PUT /user/password` | `POST /password/change-advanced` |
| Verifies | current password server-side | current session (already authenticated) |
| Rotation | new Argon2id password hash | new `salt_a` + new `wrapped_dek_a` + new auth_proof; **DEK unchanged** |
| Sessions | revoked | revoked |

Confirmation mismatch (`new_password_confirmation`) is validated client-side
in the advanced path before any crypto work.

### 5.7 Basic → Advanced Upgrade

One-way migration for existing accounts, from the profile page:

```text
Client-side (all crypto):
1. Read current plaintext profile from the authenticated session
2. Generate DEK, salt_a, salt_r, seed phrase, Key_A, Key_R
3. Wrap DEK with both keys, encrypt profile,
   compute seed verification + auth_proof + login_id

POST /user/upgrade   (requires JWT)
{ current_password, auth_proof, login_id, salt_a, salt_r,
  wrapped_dek_a, wrapped_dek_r, encrypted_profile,
  encrypted_seed_verification }

Server:
- Verifies current_password against existing Argon2id hash
- Refuses if already advanced
- Writes all blobs, nullifies plaintext fields
- Revokes all sessions/tokens
```

The UI then shows the seed-phrase modal (copy-to-clipboard + explicit "I saved
it") before continuing. Same rule as registration: **the seed phrase is not in
the request body.**

---

### 5.8 OAuth Identity for Advanced Users

Downstream OAuth apps (e.g. consumer applications) consume `@dowardev/sso-client` as pure
OAuth clients. They delegate login to the SSO frontend; the SDK must deliver a
**uniform `User` identity** (name/user/email populated) for both basic and
advanced users, with **zero ZKE code in the app**.

Problem: advanced users store `name/user/email = NULL` on the server. The
decrypted profile exists only client-side after the advanced unlock and is
delivered to the OAuth app as a URL `#profile=` fragment on the authorize
redirect. Without integrity, that fragment can be forged to impersonate identity.

Flow (after a successful advanced unlock on the SSO frontend):

1. **Backend** — `GET /api/user/profile-token` (jwt.auth) issues a short-lived
   (300s) **RS256 JWS** attesting `(sub = user id, profile_version)`. The backend
   never sees the plaintext profile; it only attests the subject it already
   authenticated. See `JwtService::issueProfileToken`,
   `AuthService::issueProfileToken`, `AuthController::profileToken`.
2. **SSO frontend** — `authController.buildAppRedirectUrl` fetches the token, then
   appends the signed envelope `{ profile, token }` as `#profile=` (standard
   base64). If the token is unavailable, the fragment is omitted and the advanced
   identity stays `null` (safe default).
3. **SDK** — `SSOClient.handleCallback` extracts the fragment, exchanges the
   OAuth code, then calls `verifyProfileFragment({ fragment, ssoUrl,
   expectedUserId: String(response.user.id) })`. It verifies the JWS against the
   SSO JWKS, checks `sub == expected user` and `exp`, and merges the profile into
   `getUser()`. The verified profile is persisted at rest in an AES-GCM
   non-extractable vault (IndexedDB) for offline reload and cleared on logout.

Security:
- Forged / unsigned / expired / subject-mismatched tokens are rejected; identity
  stays `null`.
- The backend attests only the subject it authenticated — it never handles the
  plaintext ZKE profile.
- The at-rest vault protects against off-origin IndexedDB dumps; it is not a
  defense against runtime XSS (XSS can still call decrypt with the in-memory key).

## 6. JWT Claims

`JwtService::generateToken` is level-aware:

```jsonc
// basic user
{
  "iss": "https://sso...",
  "sub": "42",
  "user": { "id": 42, "name": "Ada", "email": "ada@x.com", "user": "ada" },
  // optional: remember_me, cnf.jkt (DPoP)
}

// advanced user
{
  "iss": "https://sso...",
  "sub": "42",
  "user": { "id": 42, "user": null },     // name/email omitted
  "encryption_level": "advanced"
}
```

DPoP binding (`cnf.jkt`) works identically for both levels.

---

## 7. Session Invalidation Policy

All sessions and refresh tokens are revoked on:

| Event | Where |
|---|---|
| Logout | `AuthController::logout` |
| Basic password change | `updateUserPassword` |
| Advanced password change | `changePasswordAdvanced` |
| Account recovery completed | `recoveryComplete` |
| Upgrade to advanced | `upgradeToAdvanced` |
| Refresh-token reuse detected (theft heuristic) | `refreshAccessToken` |

Refresh tokens rotate on every use; reuse of an already-rotated token revokes
the whole chain.

---

## 8. Security Properties

What the design guarantees:

1. **Zero-knowledge profile.** For advanced users the server stores only
   ciphertext and public salts; a full database leak exposes neither profile
   data nor passwords nor the seed phrase.
2. **Password never transmitted.** Authentication uses HMAC(Key_A, "auth"),
   which is useless offline without the salted password.
3. **No seed exfiltration channel.** The seed exists in the registration /
   upgrade request bodies under no circumstance.
4. **Recovery without trustee.** Knowledge of the seed phrase alone is
   sufficient; support cannot recover accounts.
5. **Timing-safe seed comparison** (`hash_equals`) on `/recovery/verify`.
6. **Anti-enumeration:** unknown `login_id` returns 404 indistinguishable
   from other failures on the first-step endpoint.
7. **Keys out of persistent storage.** DEK/Key_A exist only in Web Worker
   memory for the tab lifetime; logout and `clearCryptoState()` wipe them.

Threat-model caveats (accepted for Phase 1):

- A successful XSS in the SSO origin while the tab is unlocked can reach the
  worker via the service API. CSP is Report-Only outside production; enforce
  it before exposing advanced mode publicly.
- PBKDF2 1M iterations costs ~1–2 s on mid-range hardware; the UI shows a
  progress state during derivation.
- `login_id` is deterministic per username (fixed local pepper). It prevents
  casual correlation but not targeted confirmation if the attacker knows the
  username.

---

## 9. Known Limitations

Documented gaps to address in later phases:

1. **Sign-counter not enforced.** Stored counter is always 0; cloned-authenticator
   detection is inactive.
2. **`login_id` pepper is static.** Rotating usernames requires a migration
   flow that does not exist yet.
3. **No admin visibility for advanced users.** Admin panels show nulls for
   name/email by design; an admin UX for this case is pending.
4. **Recovery does not rotate Key_R.** If a seed phrase leaks alongside a
   still-valid password, there is no rotation procedure yet.

---

## 10. File Map

**Backend**

| File | Role |
|---|---|
| `back/database/migrations/2026_08_23_202600_add_encryption_columns_to_users_table.php` | Schema |
| `back/app/Models/User.php` | Fillable/hidden crypto fields, `is_advanced` accessor |
| `back/app/Services/AuthService.php` | Advanced register/login/recovery/change-password/upgrade logic |
| `back/app/Services/JwtService.php` | Level-aware claim generation |
| `back/app/Services/PasskeyService.php` | WebAuthn ceremonies (web-auth/webauthn-lib v5) |
| `back/app/Http/Controllers/AuthController.php` | All `/login/*`, `/register/*`, `/recovery/*`, `/passkey/*`, `/user/*` routes |
| `back/config/passkey.php` | RP id/name/timeout configuration |
| `back/tests/Feature/AdvancedEncryptionTest.php` | Feature coverage for advanced/recovery flows |

**Frontend**

| File | Role |
|---|---|
| `front/src/types/crypto.ts` | Crypto domain types (blobs, worker messages, API payloads) |
| `front/src/workers/cryptoWorker.ts` | All primitives: PBKDF2, AES-KW, AES-GCM, HMAC, BIP39 |
| `front/src/services/cryptoService.ts` | Promise API over the worker, tab-scoped key state |
| `front/src/services/passkeyService.ts` | Typed `navigator.credentials` wrapper |
| `front/src/stores/auth.ts` | Session persistence (`applyLoginSession`), in-memory key state |
| `front/src/composables/authController.ts` | Orchestration of every flow in §5 |
| `front/src/components/AuthLoginForm.vue` | Unified identifier field (basic vs advanced detection) |
| `front/src/views/RegisterPage.vue` | Encryption-level selector, seed modal |
| `front/src/components/user/ProfileForm.vue` | Upgrade card + seed modal |
