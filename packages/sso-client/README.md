# @dowardev/sso-client

TypeScript SDK for SSO authentication in the browser (Vue, React, Vanilla TS, etc.) with **OAuth2 PKCE** + **DPoP** (Demonstrating Proof-of-Possession) support over axios.

Built to integrate with the central SSO server ([`dowardev/sso-server`](https://gitlab.com/dowardev/sso)) and expose a navigation guard and a singleton `SSOClient`.

## Requirements

- Modern browser with `localStorage` and **WebCrypto** support (`window.crypto.subtle`, required for DPoP).
- `axios ^1.6.0` (installed automatically as a dependency).
- Outbound connection to the central SSO server — the `clientId` must be registered on it beforehand; otherwise authentication will fail.

## Installation

```bash
pnpm add @dowardev/sso-client
# or
npm install @dowardev/sso-client
```

## Basic usage

```ts
import { SSOClient } from "@dowardev/sso-client";

const ssoClient = new SSOClient({
  // The client must be registered beforehand on the central SSO server;
  // otherwise authentication will fail.
  serverUrl: "https://sso.example.com",
  clientId: "my-app",
  redirectUri: window.location.origin + "/callback",
  scopes: ["openid", "profile"],
});

// Start login (PKCE + DPoP)
await ssoClient.login();

// Get authenticated user
const user = await ssoClient.getUser();

// Navigation guard (Vue Router)
import { createAuthGuard } from "@dowardev/sso-client";
const router = createRouter({
  routes: [
    { path: "/private", component: PrivateView, meta: { requiresAuth: true } },
  ],
});
router.beforeEach(createAuthGuard({ ssoClient }));
```

## API

- `SSOClient` — main class (login, logout, getUser, getAuthState, refresh).
- `createAuthGuard({ ssoClient, redirectUri })` — router guard.
- `TokenStorage` — token / refresh-token / PKCE verifier management in localStorage.
- Types: `SSOClientOptions`, `AuthState`, `User`.

## Demo

The `front/` workspace serves as the main reference app for SSO integration.

## License

**MIT** — permissive license. You can integrate this SDK into any application, commercial or open source, without copyleft inheritance.

See [LICENSE](./LICENSE) for the full text.
