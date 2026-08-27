# ZKE Manual Testing Checklist (v2)

Manual QA guide for the Zero-Knowledge Encryption flows after the v2 migration
(registration basic-only, advanced as a profile opt-in, DEK resident in the
isolated worker, PRF enroll/restore bridge, inactivity re-lock).

> Automated baseline is already green: 102 backend tests, 25 package crypto
> tests, front typecheck + build. This checklist covers the UX/flow details and
> the PRF gesture that unit tests cannot.

## Setup

```bash
cd back && php artisan migrate   # creates passkey_credentials table
pnpm run dev                    # front (:8101) + back (:8001)
```

## 1. Regression — Basic must be untouched

| Step | Expected |
|------|----------|
| Register a new user (basic) on the public page | Email verification sent; redirect to VerifyEmailPending |
| Verify email from the link | Login works; profile shows name/email/user |
| Logout / login again | Still works (no encryption involved) |
| Duplicate email / username | Rejected with clear error |

> 🔴 This is the highest-risk regression target: nothing in the basic flow
> touches the crypto layer. If this breaks, stop and report.

## 2. Upgrade to advanced (profile opt-in)

| Step | Expected |
|------|----------|
| Logged in basic user → Profile → "Actualizar a Cifrado Avanzado" | Current password asked, seed phrase modal shown once |
| Save the 24-word seed phrase | Account is now advanced (badge/notice in profile) |
| Logout → login with the **new password contract** | Advanced login flow activates |

## 3. Advanced login (password)

| Step | Expected |
|------|----------|
| Login advanced → password | Blobs fetched, `Key_A` derived, DEK unwrapped **in the worker** |
| Profile shows name/email/user decrypted | Decrypt happens via worker postMessage (no DEK in main thread) |
| Open DevTools → check `window`/global for DEK bytes | **No DEK / profile key material in the main heap** |

> You should NOT see the DEK as a CryptoKey or raw bytes anywhere reachable
> from the console. The worker holds it.

## 4. Passkey register / login (rebuilt ceremony)

| Step | Expected |
|------|----------|
| Register a passkey | Attestation accepted; stored in `passkey_credentials` |
| Register a **second** passkey | Both persist (multi-credential, no overwrite) |
| Login with passkey | Assertion verified; token issued; session works |
| Delete one passkey | The other remains |

## 5. Recovery (seed phrase)

| Step | Expected |
|------|----------|
| Recovery → wrong seed verification | 401 "Frase semilla incorrecta" |
| Recovery → correct seed | salt_r + wrapped_dek_r + encrypted_profile returned |
| Complete recovery with new password | DEK re-wrapped, profile re-encrypted, login with new password works |

## 6. Change password (advanced)

| Step | Expected |
|------|----------|
| Change advanced password | New `Key_A` wraps DEK; old password no longer logs in; new does |

## 7. Inactivity re-lock

| Step | Expected |
|------|----------|
| Use the app, then leave it idle ~5 min (or background 30 s) | Worker DEK dropped (`relock`); return triggers re-auth/passkey gesture |
| Keep interacting | No re-lock (session stays hot) |

> With the PRF bridge this re-unlock is a cheap passkey gesture; without a
> passkey it falls back to password. This is the intended trade-off.

## 8. PRF silent refresh (needs a real authenticator — not yet automated)

| Step | Expected |
|------|----------|
| Register advanced + enroll a PRF-capable passkey | `{wrappedDek, salt, credentialId}` persisted in IndexedDB (`zke_prf_keys`) |
| Logout / new session | `restoreDekSilently` does a passkey gesture → DEK unwrapped → profile decrypts without typing the password |
| Use a non-PRF device | IndexedDB stays empty; falls back to password (fail-secure) |

> This is the one step that **cannot** be verified by unit tests: it needs a
> real browser + a PRF-capable authenticator (platform passkey / security key).
> Chrome DevTools Protocol virtual authenticators are a workable automated
> option for a later E2E.

## Where to look if something fails

- **Backend logs** for ceremony errors (auth: `/api/passkey/*`, `/api/crypto/blobs`).
- **Console** should show no DEK leaking; worker errors surface as
  `Crypto operation failed`.
- **Network tab**: `/crypto/blobs`, `/user/upgrade`, `/recovery/*` payloads
  must NOT contain plaintext profile data.
- **IndexedDB**: `zke_prf_keys` only ever holds wrapped (encrypted) DEK material.