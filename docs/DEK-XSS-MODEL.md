# DEK Protection & XSS Model (Zero-Knowledge Encryption v2)

> Status: reflects the implemented v2 architecture in `@dowardev/sso-client` + SSO backend.

## The problem

An advanced (zero-knowledge) user's profile is encrypted with a DEK (AES-GCM).
To allow *silent* cross-session profile refresh without a password, the DEK must
be recoverable on the device. If the DEK (or a key that unwraps it) is persisted
in a form an XSS can operate on, then a successful XSS on the origin could
decrypt the profile while the session lives.

## The mitigation (layered — none is a silver bullet)

| Layer | What it does | What it does NOT do |
|-------|--------------|---------------------|
| **WebAuthn PRF** | Gating the *unlock*. Deriving `K_PRF = HKDF(prfOutput, salt, "SSO-DEK-WRAP")` requires a fresh `navigator.credentials.get()` with `userVerification:required` — a real biometric/PIN gesture a script cannot fake. An XSS that lands **before** unlock cannot unwrap the DEK. | It does not stop an XSS that lands **after** the session is unlocked (the DEK is already resident). |
| **Worker isolation** | The DEK lives **only** inside the crypto worker (`extractable:false`-import at set time, never exported to the main thread). Defends against key **exfiltration** to a third party. | It does not stop same-origin JS from *using* the worker to decrypt (an XSS can still request a decrypt). |
| **Re-lock on inactivity** (planned) | Bounds the *residual* window: drop the resident DEK on `visibilitychange`→hidden, idle timeout, and `beforeunload`; keep only the wrapped DEK. | Not yet wired into the worker — review task. Complementary, not sufficient alone. |
| **Fail-secure (no PRF)** | When PRF is unsupported, `persistPrfWrappedDek` stores **nothing**; the client falls back to password re-auth. Zero persisted surface. | Requires the interoperability trade-off (no silent refresh on those devices). |
| **CSP + code hygiene** | Reduces the *probability* that an XSS exists at all. | A CSP bypass resets the reasoning. |

## The honest residual risk

An XSS that is **active after the user has unlocked** and is **actively using**
the app can still decrypt and read the profile (it is code running as the user).
This is the physical limit of any client-side crypto: the browser must decrypt
the data to render it. This is the same limitation every serious product ships
with (Gmail, 1Password, Proton): they mitigate via process isolation, short
key lifetimes, and aggressive XSS prevention — not by making XSS impossible.

## What v2 concretely buys

1. Removes the **persisted** device-key surface (no static AES-KW key in
   IndexedDB that an XSS can operate on).
2. Keeps key **bytes out of the main-thread heap** (the DEK is worker-resident;
   only `K_PRF` ever transiently crosses the main thread during a PRF ceremony).
3. Turns DEK recovery into a **gesture-gated** operation instead of a silent
   cryptographic one.

## Consuming it

- Backend must emit the WebAuthn `prf` extension on passkey registration and
  support **multi-credential** storage (`passkey_credentials`).
- Client apps use `@dowardev/sso-client` → `ZkeClient` (`enrollPrf`,
  `restoreDekWithPrf`, `refreshProfileSilently`). The profile reaches OAuth
  apps via the `#profile=` fragment (never in server logs or the query).
- When PRF is unsupported, the client requests the password to re-derive the
  DEK — no silent refresh.