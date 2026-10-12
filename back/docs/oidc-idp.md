# OIDC IdP integration

**Ticket:** LBDS-303.
**Status:** Discovery + scope plumbing + userinfo + id_token + PKCE + claims
mapping + RP-initiated logout all shipped on `dowardev/lbds-303-...`. The
SSO is now a standards-compliant OpenID Connect identity provider; any RP
that supports "Sign in with OIDC" can integrate with it the same way it
would integrate with Auth0, Keycloak, or Okta.

This document is the integration guide for the RP side. It covers
client registration, the authorization code flow with PKCE, the
endpoints the SSO now exposes, the claim shape of the id_token and
userinfo response, RP-initiated logout, and the per-client
`claim_overrides` config that lets an RP ask for extra claims
specific to its product.

If you are looking for internal architecture notes (why the OIDC
controller is split from `OAuth2Controller`, how the id_token's
`aud` differs from the access token's `sub`, etc.), those live in
the commit messages of the `dowardev/lbds-303-...` branch.

---

## 1. Discovery

The single entry point. Every other URL in this document is reachable
from the response of this one, and the OIDC client libraries that
already exist for every language (oidc-client-js, openid-client on
Node, python-openid-client, etc.) handle the rest automatically.

```
GET https://sso.example.com/api/.well-known/openid-configuration
```

Response (truncated):

```json
{
  "issuer": "https://sso.example.com",
  "authorization_endpoint": "https://sso.example.com/api/oauth/authorize",
  "token_endpoint": "https://sso.example.com/api/oauth/token",
  "userinfo_endpoint": "https://sso.example.com/api/oauth/userinfo",
  "jwks_uri": "https://sso.example.com/api/.well-known/jwks",
  "end_session_endpoint": "https://sso.example.com/api/oauth/logout",
  "scopes_supported": ["openid", "profile", "email", "offline_access"],
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code", "refresh_token", "client_credentials"],
  "token_endpoint_auth_methods_supported": ["client_secret_basic", "client_secret_post", "none"],
  "id_token_signing_alg_values_supported": ["RS256"],
  "subject_types_supported": ["public"],
  "claims_supported": [
    "sub", "iss", "aud", "exp", "iat", "auth_time", "nonce",
    "acr", "amr", "name", "email", "email_verified",
    "preferred_username", "picture", "client_id"
  ],
  "code_challenge_methods_supported": ["S256"]
}
```

`issuer` matches the value the SSO stamps into every `iss` claim; an
OIDC client MUST abort if the two ever disagree. The OIDC client
library you use will already enforce this — do not skip the
discovery fetch.

---

## 2. Client registration

Out of band, through the SSO admin UI at `/admin/clients`. The
admin creates an `SsoClient` row with the following fields relevant
to OIDC integration:

| Field | What to set | Notes |
|---|---|---|
| `name` | Display name | Shown on the consent screen. |
| `client_id` | OIDC client_id | Public, used in every request. |
| `client_secret` | OIDC client_secret | Leave empty for public clients (SPAs, native). |
| `redirect_url` | The exact callback URL | Must match the `redirect_uri` on every request, character for character. |
| `allowed_origins` | CORS allowlist for the token exchange | One entry per browser-origin that will call the token endpoint directly. |
| `allowed_scopes` | Subset of `[openid, profile, email, offline_access]` | Defaults to the full set for pre-LBDS-303 clients. |
| `grant_types` | `[authorization_code]` for OIDC | Add `refresh_token` if you also want a refresh-token grant (most RPs do). |
| `claim_overrides` | Optional JSON | See §7. |

The SSO does NOT implement [Dynamic Client Registration][dcr] (yet).
Clients are pre-registered by an admin.

[dcr]: https://openid.net/specs/openid-connect-registration-1_0.html

---

## 3. Authorization code flow with PKCE

PKCE is **required** on every authorization code request — even for
confidential clients. A leaked `client_secret` alone is no longer
enough to mint a code; the attacker also needs the `code_verifier`
your backend keeps on its own. See RFC 7636 for the protocol
details; the short version:

1. **Generate** a `code_verifier`: 43–128 chars of `[A-Za-z0-9-._~]`.
2. **Derive** `code_challenge = BASE64URL(SHA256(code_verifier))`.
3. **Send the user** to the authorization endpoint with the
   challenge.
4. **Exchange** the returned `code` for tokens, sending the
   `code_verifier` as proof you own the challenge.

### 3.1 Step-by-step

```
# 1. Generate verifier + challenge (do this server-side, not in the browser)
verifier  = <random 64 chars>
challenge = base64url(sha256(verifier))

# 2. Redirect the user to the SSO
GET https://sso.example.com/api/oauth/authorize
    ?client_id=<your_client_id>
    &redirect_uri=https://yourapp.example.com/auth/callback
    &response_type=code
    &scope=openid profile email
    &state=<csrf-token>
    &nonce=<random>
    &code_challenge=<challenge>
    &code_challenge_method=S256
```

The user lands on the SSO, signs in, and either grants or denies
the requested scopes. On grant, the SSO redirects back:

```
GET https://yourapp.example.com/auth/callback
    ?code=<one-time code>
    &state=<csrf-token>
```

You MUST verify that `state` matches the value you sent in step 2.
That is the only thing standing between an attacker and a forced
login on a victim's account (the login-CSRF attack).

### 3.2 Exchange

```
POST https://sso.example.com/api/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&client_id=<your_client_id>
&client_secret=<your_client_secret>          # omit for public clients
&code=<code from step 3.1>
&redirect_uri=https://yourapp.example.com/auth/callback
&code_verifier=<verifier from step 1>
```

Successful response (200):

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ...",
  "refresh_token": "long-random-string",
  "id_token": "eyJ...",
  "scope": "openid profile email",
  "user": { ... }
}
```

`access_token` is what the RP sends on every API call (as
`Authorization: Bearer <access_token>`). `id_token` is what the RP
verifies once at login (using the JWKS). `refresh_token` rotates
the access token when it expires; see §5.

`scope`, `id_token`, and `user` are part of the authorization-code
response only. The refresh-token response (§5) carries the new
`access_token` and a rotated `refresh_token`; the SSO does not
re-emit an id_token on rotation (OIDC §12.2 — id_token is for the
initial authentication, not for every token exchange).

If you sent a confidential client but forgot the secret, you get 403.
If the verifier does not match the challenge, you get 400. If the
code has been used already, you get 400 (single-use codes).

### 3.3 Verify the id_token

```js
const jose = require('jose')

const jwks = jose.createRemoteJWKSet(
  new URL('https://sso.example.com/api/.well-known/jwks')
)
const { payload } = await jose.jwtVerify(id_token, jwks, {
  issuer: 'https://sso.example.com',         // MUST match discovery.issuer
  audience: '<your_client_id>',              // aud claim is the RP
})

// payload.sub  -> user id (stringified)
// payload.nonce -> MUST match the nonce you sent in step 3.1
// payload.auth_time -> seconds since epoch
```

If `nonce` does not match what you sent, reject. That is the
replay-protection half of the OIDC contract; the signature is the
authentication half.

---

## 4. Calling the userinfo endpoint

Two options to learn who the user is after the id_token has been
verified: trust the claims in the id_token itself, or call the
userinfo endpoint. The id_token carries the same claims when the
matching scope was granted (see §6), so for simple integrations
the id_token is enough.

For RPs that want fresh data (e.g. the user just changed their
email and the RP needs the new value before the id_token expires),
call userinfo:

```
GET https://sso.example.com/api/oauth/userinfo
Authorization: Bearer <access_token>
```

Response (200), when scopes `openid profile email` were granted:

```json
{
  "sub": "42",
  "name": "Ada Lovelace",
  "preferred_username": "ada",
  "updated_at": 1728643200,
  "email": "ada@example.com",
  "email_verified": true
}
```

`sub` is the user id (stringified, stable). `updated_at` is the
unix timestamp of the last user record update. Claims outside the
granted scopes are simply absent — the RP does not need to ask
"is `email` here?"; if the scope was not granted, the field is
not there.

Errors:

- `401 invalid_token` — bearer token missing or signature invalid.
- `403 invalid_token` — bearer token has no user (client_credentials
  grant) or did not resolve to a user.

---

## 5. Refresh tokens

When the access token is about to expire (or has already expired),
exchange the refresh token for a new one:

```
POST https://sso.example.com/api/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&client_id=<your_client_id>
&client_secret=<your_client_secret>
&refresh_token=<refresh_token>
```

The response is identical in shape to §3.2. The SSO rotates
refresh tokens on every use; the old one is immediately invalid.
Store the new refresh token after every successful exchange.

The response shape is narrower than §3.2: only the new
`access_token` and a rotated `refresh_token` come back. The SSO
does not re-emit an `id_token` on rotation — id_token is for the
initial authentication, not for every refresh. The rotated
`access_token` does carry the same `scope` and `client_id` claims
as the original (inherited from the refresh token row), so
/oauth/userinfo scope filtering and per-client claim_overrides
keep working unchanged after the first rotation.

Refresh tokens have two expirations: an absolute one (30 days
by default) and an inactivity one (7 days). The inactivity window
resets every time the token is used. After either window closes,
the user must sign in again.

DPoP-bound refresh tokens exist for the higher-security clients
(see `back/docs/DPOP_CUTOVER_RUNBOOK.md` for the runtime config
flag). If you opt in, the refresh-token request also requires a
DPoP proof in the `DPoP` header.

---

## 6. id_token claims

| Claim | Source | Always present? |
|---|---|---|
| `iss` | `config('jwt.issuer')` | yes |
| `sub` | user id (stringified) | yes |
| `aud` | your client_id (single-element list per RFC 7519) | yes |
| `exp` | `now + jwt.id_token_expiration` (600s default) | yes |
| `iat` | `now` | yes |
| `nonce` | echoed from `/oauth/authorize` if you sent one | optional |
| `auth_time` | `now` (proxy; precise per-session tracking is a follow-up) | optional |
| `acr` | `"0"` placeholder | yes |
| `amr` | `["pwd"]` placeholder | yes |
| `name` | user record | when `profile` scope granted |
| `preferred_username` | user record | when `profile` scope granted |
| `updated_at` | user record (unix seconds) | when `profile` scope granted |
| `email` | user record | when `email` scope granted, basic encryption only |
| `email_verified` | derived from `email_verified_at` | when `email` scope granted, basic encryption only |

Users on advanced encryption do not get `email` / `email_verified`
in either the id_token or userinfo response — the SSO cannot
decrypt their profile server-side (zero-knowledge). The
`encryption_level` is intentionally not exposed; an RP that needs
to tell basic from advanced users must do so out of band.

`acr` and `amr` are honest placeholders. They will become precise
once per-session auth-method tracking lands on the UserSession
row. Today, every SSO login is at least a password holder, so
`amr=["pwd"]` is the safe default; the spec makes both optional
and the ticket accepts these placeholders.

---

## 7. Per-client claim overrides

RPs sometimes need a claim the SSO does not provide out of the
box (e.g. a `tenant_id`, a `subscription_tier`, a renamed
`display_name`). Each `SsoClient` can carry a `claim_overrides`
JSON column that injects extras on top of the standard claim
set, in both the id_token and the userinfo response.

Shape:

```json
{
  "extra_claims": {
    "<claim_name>": "<value | user.<field>>"
  }
}
```

- A literal string is emitted as-is.
- A value starting with `user.` is resolved from the User model
  (`user.email` -> `$user->email`, `user.id` -> `$user->id`).
  Unknown `user.<x>` paths are silently skipped rather than
  emitted as raw strings.

The reserved OIDC claims cannot be shadowed (`iss`, `sub`,
`aud`, `exp`, `iat`, `nonce`, `auth_time`, `acr`, `amr`, `jti`).
An override that names one of those is silently dropped.

Set the column through the admin UI or directly in the
`sso_clients` row. Changes apply on the next token issuance;
existing access tokens keep their original claims until they
expire.

---

## 8. RP-initiated logout

```
GET https://sso.example.com/api/oauth/logout
    ?id_token_hint=<id_token>
    &post_logout_redirect_uri=https://yourapp.example.com/goodbye
    &state=<csrf-token>
```

The SSO:
1. Verifies `id_token_hint` (signature, issuer, expiry). A forged
   or expired hint is a 400 — no logout happens.
2. Revokes every refresh token and session row for the user in
   the database. This is the contract: after RP-initiated logout,
   the SSO stops minting new access tokens for that user
   immediately, not after the inactivity window.
3. If `post_logout_redirect_uri` matches the client's registered
   `redirect_url`, redirects the user there with `state` echoed
   in the query. Anything else is a 400 (open-redirect
   protection).
4. If no valid `post_logout_redirect_uri`, renders a small HTML
   confirmation page so a user that typed the URL directly
   still sees what happened.

`id_token_hint` is required. The SSO always issues an id_token
when the `openid` scope is granted, so a logout without one is a
misconfigured RP and failing closed is safer than guessing which
user to log out.

---

## 9. End-to-end curl run

A complete happy-path integration, copy-paste. Replace
`sso.example.com` with your SSO host and `yourapp.example.com`
with your callback.

```bash
set -euo pipefail

SSO=https://sso.example.com
APP=https://yourapp.example.com
CLIENT_ID=demo-client
CLIENT_SECRET=demo-secret         # omit for public clients
REDIRECT="${APP}/auth/callback"
SCOPE="openid profile email"

# 1. Discover
curl -sf "$SSO/api/.well-known/openid-configuration" | jq .

# 2. PKCE
VERIFIER=$(openssl rand -base64 64 | tr -d '=+/' | cut -c1-64)
CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -sha256 -binary \
            | openssl base64 -A | tr '+/' '-_' | tr -d '=')

# 3. Open the browser to this URL (cannot curl it; copy into a browser)
echo "Open: $SSO/api/oauth/authorize?client_id=$CLIENT_ID&redirect_uri=$(printf %s "$REDIRECT" | jq -sRr @uri)&response_type=code&scope=$(printf %s "$SCOPE" | jq -sRr @uri)&state=xyz&nonce=abc&code_challenge=$CHALLENGE&code_challenge_method=S256"

# 4. After the redirect, $URL will look like:
#      https://yourapp.example.com/auth/callback?code=...&state=xyz
read -r URL
CODE=$(printf '%s' "$URL" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
[ "$CODE" ] || { echo "no code in URL"; exit 1; }

# 5. Exchange the code
TOKENS=$(curl -sf -X POST "$SSO/api/oauth/token" \
  -d grant_type=authorization_code \
  -d client_id="$CLIENT_ID" \
  -d client_secret="$CLIENT_SECRET" \
  -d code="$CODE" \
  -d redirect_uri="$REDIRECT" \
  -d code_verifier="$VERIFIER")
echo "$TOKENS" | jq .

# 6. Decode the id_token (without verification — for inspection only)
echo "$TOKENS" | jq -r .id_token | cut -d. -f2 \
  | tr '_-' '/+' | base64 -d 2>/dev/null | jq .

# 7. userinfo
ACCESS=$(echo "$TOKENS" | jq -r .access_token)
curl -sf -H "Authorization: Bearer $ACCESS" "$SSO/api/oauth/userinfo" | jq .

# 8. Logout (uses the id_token, not the access token)
ID=$(echo "$TOKENS" | jq -r .id_token)
curl -sf -i "$SSO/api/oauth/logout?id_token_hint=$ID&post_logout_redirect_uri=$(printf %s "$REDIRECT" | jq -sRr @uri)&state=xyz"
# Expect: HTTP/1.1 302 + Location: https://yourapp.example.com/auth/callback?state=xyz
```

---

## 10. Operational notes

- **Clock skew**: id_token and access token validation tolerates a
  small clock skew via the `LooseValidAt` constraint. Servers more
  than a few seconds off will see `exp` failures; check NTP.
- **Signing key rotation**: keys live under `config('jwt.keys')`.
  The current `kid` is the only one that issues new tokens; older
  kids remain valid for verification until removed. See
  `docs/AUTHENTICATION.md` for the rotation recipe.
- **Scope leakage**: never log or persist the `access_token`. The
  `id_token` is OK to log (it is short-lived) but `nonce`,
  `auth_time`, and `sub` are usually enough for debugging.
- **Custom errors**: the SSO returns 4xx with a `message` field in
  Spanish. Map that to a localized string in your own UI; do not
  pattern-match the SSO's text.
- **Out of scope**: SAML, SCIM, inbound federation from
  external IdPs (Google / Azure / Okta as the IdP for this SSO).
  These are explicitly excluded from LBDS-303 and tracked
  separately.
