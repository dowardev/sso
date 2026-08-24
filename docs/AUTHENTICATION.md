# Authentication & Encryption Architecture

<!-- markdownlint-disable MD013 MD060 -->

Complete reference for the SSO authentication system: authentication flows,
the zero-knowledge encryption scheme, key hierarchy, data model, and security
properties.

- **Linear ticket:** LBDS-185
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
  `publickey-credentials-get=(self)` so browsers allow the prompts.

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

1. **One passkey per user.** Schema stores a single credential pair; multiple
   devices need a proper credentials table.
2. **Sign-counter not enforced.** Stored counter is always 0; cloned-authenticator
   detection is inactive.
3. **`login_id` pepper is static.** Rotating usernames requires a migration
   flow that does not exist yet.
4. **No admin visibility for advanced users.** Admin panels show nulls for
   name/email by design; an admin UX for this case is pending.
5. **Recovery does not rotate Key_R.** If a seed phrase leaks alongside a
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
