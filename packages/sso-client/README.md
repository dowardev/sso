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
  ssoUrl: "https://sso.example.com/api/",
  ssoFrontendUrl: "https://sso.example.com",
  apiBaseUrl: "https://api.example.com/api/",
  clientId: "my-app",
});

// Start login (PKCE + DPoP, redirect flow)
await ssoClient.loginWithRedirect({
  redirectUri: window.location.origin + "/auth/callback",
});

// Handle the OAuth callback, then get the authenticated user
const { user } = await ssoClient.handleCallback(window.location.href);

// Navigation guard (Vue Router)
import { createAuthGuard } from "@dowardev/sso-client";
const router = createRouter({
  routes: [
    { path: "/private", component: PrivateView, meta: { requiresAuth: true } },
  ],
});
router.beforeEach(createAuthGuard({ ssoClient }));
```

## Native apps (Capacitor deep link)

```ts
// Open the authorize URL in the system browser and come back via deep link.
// The redirectUri must be whitelisted for this client on the SSO server.
await ssoClient.loginWithRedirect({
  redirectUri: "com.example.app://auth/callback",
  openUrl: ({ url }) => Browser.open({ url }),
});

// On `appUrlOpen`, complete the flow (the stored redirectUri is reused
// automatically; pass it explicitly to override):
await ssoClient.handleCallback(event.url);
```

```ts
// Native logout mirrors login: open externally, the deep-link return just
// brings the app back (local session is already cleared).
await ssoClient.logout({
  redirectUri: "com.example.app://auth/callback",
  openUrl: ({ url }) => Browser.open({ url }),
});
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
