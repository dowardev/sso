// SPDX-License-Identifier: MIT
import "fake-indexeddb/auto";
import { IDBFactory } from "fake-indexeddb";
import { describe, it, expect, vi, beforeAll, beforeEach } from "vitest";
import { SSOClient } from "./SSOClient";
import { loadProfileAtRest } from "../crypto/profileVault";
import { TokenStorage } from "../storage/TokenStorage";
import { bytesToBase64Url } from "../crypto/core";
import type { UserProfile } from "../types/crypto";

// --- Minimal browser-global polyfills (vitest defaults to the node env) ------
class MemoryStorage {
  private m = new Map<string, string>();
  get length(): number {
    return this.m.size;
  }
  getItem(k: string): string | null {
    return this.m.has(k) ? (this.m.get(k) as string) : null;
  }
  setItem(k: string, v: string): void {
    this.m.set(k, String(v));
  }
  removeItem(k: string): void {
    this.m.delete(k);
  }
  clear(): void {
    this.m.clear();
  }
  key(i: number): string | null {
    return Array.from(this.m.keys())[i] ?? null;
  }
}

vi.stubGlobal("localStorage", new MemoryStorage());
vi.stubGlobal("sessionStorage", new MemoryStorage());
vi.stubGlobal("window", {
  location: { origin: "https://app.example.com", href: "" },
});

// --- axios mock (hoisted so the factory can reference the spy) -----------------
const { requestMock, axiosInstanceMock } = vi.hoisted(() => {
  const requestMock = vi.fn();
  const axiosInstanceMock = {
    interceptors: {
      request: { use: vi.fn() },
      response: { use: vi.fn() },
    },
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    request: requestMock,
  };
  return { requestMock, axiosInstanceMock };
});
vi.mock("axios", () => ({
  default: {
    create: vi.fn(() => axiosInstanceMock),
    request: requestMock,
  },
}));

// --- RSA key + JWS helpers (WebCrypto, mirrors the SSO server signing) --------
interface TestKeys {
  privateKey: CryptoKey;
  publicJwk: JsonWebKey;
}

async function makeRsaKey(): Promise<TestKeys> {
  const kp = await crypto.subtle.generateKey(
    {
      name: "RSASSA-PKCS1-v1_5",
      modulusLength: 2048,
      publicExponent: new Uint8Array([1, 0, 1]),
      hash: "SHA-256",
    },
    true,
    ["sign", "verify"]
  );
  const jwk = await crypto.subtle.exportKey("jwk", kp.publicKey);
  const publicJwk = {
    ...jwk,
    kid: "test-key-1",
    alg: "RS256",
    use: "sig",
  } as JsonWebKey;
  return { privateKey: kp.privateKey, publicJwk };
}

async function signJws(params: {
  payload: Record<string, unknown>;
  privateKey: CryptoKey;
}): Promise<string> {
  const header = { alg: "RS256", kid: "test-key-1" };
  const headerB64 = bytesToBase64Url(
    new TextEncoder().encode(JSON.stringify(header))
  );
  const payloadB64 = bytesToBase64Url(
    new TextEncoder().encode(JSON.stringify(params.payload))
  );
  const signingInput = new TextEncoder().encode(`${headerB64}.${payloadB64}`);
  const sig = await crypto.subtle.sign(
    "RSASSA-PKCS1-v1_5",
    params.privateKey,
    signingInput
  );
  const sigB64 = bytesToBase64Url(new Uint8Array(sig));
  return `${headerB64}.${payloadB64}.${sigB64}`;
}

function buildFragment(params: { profile: UserProfile; token: string }): string {
  return btoa(JSON.stringify({ profile: params.profile, token: params.token }));
}

function makeClient(): SSOClient {
  return new SSOClient({
    ssoUrl: "https://sso.example.com/api/",
    ssoFrontendUrl: "https://sso.example.com",
    apiBaseUrl: "https://api.example.com/api/",
    clientId: "test-client",
  });
}

const SSO_USER_NULLS = { id: "1", name: null, user: null, email: null };
const TOKEN_CLAIMS = (sub: string) => ({
  sub,
  profile_version: 1,
  iat: Math.floor(Date.now() / 1000),
  exp: Math.floor(Date.now() / 1000) + 300,
});

let keys: TestKeys;

beforeAll(async () => {
  keys = await makeRsaKey();
  // JWKS endpoint returns only the real public key; forged signatures fail.
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ keys: [keys.publicJwk] }),
    }))
  );
});

beforeEach(() => {
  // Fresh IndexedDB per test so no open connections from a previous test
  // block a later deleteDatabase (which would otherwise hang).
  vi.stubGlobal("indexedDB", new IDBFactory());
  requestMock.mockImplementation(async (cfg: any) => {
    const url = String(cfg?.url ?? "");
    if (url.includes("oauth/token")) {
      return { data: { token: "tok", user: SSO_USER_NULLS } };
    }
    if (url.includes("user-auth")) {
      return { data: SSO_USER_NULLS };
    }
    return { data: null };
  });
});

describe("SSOClient advanced (ZKE) profile handling", () => {
  beforeEach(() => {
    // The real token endpoint rejects verifier-less exchanges (invalid_grant),
    // so seed the PKCE verifier the same way loginWithRedirect would.
    localStorage.setItem(
      "@dowardev/sso-client:pkce:xyz",
      "test-verifier-for-xyz-state",
    );
  });
  it("merges a valid signed #profile= envelope and recovers it from the vault on reload", async () => {
    const profile: UserProfile = {
      name: "Ada Lovelace",
      user: "ada",
      email: "ada@example.com",
    };
    const token = await signJws({ payload: TOKEN_CLAIMS("1"), privateKey: keys.privateKey });
    const callbackUrl = `https://app.example.com/auth/callback?code=abc&state=xyz#profile=${buildFragment({ profile, token })}`;

    const client = makeClient();
    const { user } = await client.handleCallback(callbackUrl);

    expect(user.name).toBe("Ada Lovelace");
    expect(user.user).toBe("ada");
    expect(user.email).toBe("ada@example.com");

    // Reload simulation: brand-new client instance with no in-memory state.
    const reloaded = makeClient();
    await reloaded.init();
    const recovered = await reloaded.getUser();

    expect(recovered?.name).toBe("Ada Lovelace");
    expect(recovered?.user).toBe("ada");
    expect(recovered?.email).toBe("ada@example.com");
  });

  it("does NOT merge a fragment whose token is forged/unsigned (advanced identity stays null)", async () => {
    const profile: UserProfile = {
      name: "Mallory",
      user: "mallory",
      email: "mallory@example.com",
    };
    const forged = await makeRsaKey();
    const token = await signJws({ payload: TOKEN_CLAIMS("1"), privateKey: forged.privateKey });
    const callbackUrl = `https://app.example.com/auth/callback?code=abc&state=xyz#profile=${buildFragment({ profile, token })}`;

    const client = makeClient();
    const { user } = await client.handleCallback(callbackUrl);

    expect(user.name).toBeNull();
    expect(user.user).toBeNull();
    expect(user.email).toBeNull();
  });

  it("does NOT merge a fragment whose token subject does not match the authenticated user", async () => {
    const profile: UserProfile = {
      name: "Ada Lovelace",
      user: "ada",
      email: "ada@example.com",
    };
    const token = await signJws({ payload: TOKEN_CLAIMS("999"), privateKey: keys.privateKey });
    const callbackUrl = `https://app.example.com/auth/callback?code=abc&state=xyz#profile=${buildFragment({ profile, token })}`;

    const client = makeClient();
    const { user } = await client.handleCallback(callbackUrl);

    expect(user.name).toBeNull();
    expect(user.user).toBeNull();
    expect(user.email).toBeNull();
  });

  it("clears the persisted vault on logout", async () => {
    const profile: UserProfile = {
      name: "Ada Lovelace",
      user: "ada",
      email: "ada@example.com",
    };
    const token = await signJws({ payload: TOKEN_CLAIMS("1"), privateKey: keys.privateKey });
    const callbackUrl = `https://app.example.com/auth/callback?code=abc&state=xyz#profile=${buildFragment({ profile, token })}`;

    const client = makeClient();
    await client.handleCallback(callbackUrl);

    expect(await loadProfileAtRest()).not.toBeNull();

    await client.logout();

    expect(await loadProfileAtRest()).toBeNull();
  });
});

describe("SSOClient native redirect URI persistence", () => {
  const NATIVE_URI = "com.example.app://auth/callback";

  function postedTokenData(): Record<string, unknown> {
    const calls = requestMock.mock.calls.filter((c: any) =>
      String(c?.[0]?.url ?? "").includes("oauth/token"),
    );
    const last = calls[calls.length - 1];
    if (!last) {
      throw new Error("oauth/token was not requested");
    }
    return (last[0] as { data: Record<string, unknown> }).data;
  }

  it("reuses the stored redirect URI when handleCallback gets no explicit option", async () => {
    const client = makeClient();
    let opened = "";
    await client.loginWithRedirect({
      redirectUri: NATIVE_URI,
      openUrl: async (params: { url: string }) => {
        opened = params.url;
      },
    });
    const state = new URL(opened).searchParams.get("state");
    expect(state).toBeTruthy();

    await client.handleCallback(`${NATIVE_URI}?code=abc&state=${state}`);

    expect(postedTokenData().redirect_uri).toBe(NATIVE_URI);
  });

  it("prefers the explicit redirectUri option over the stored one", async () => {
    const client = makeClient();
    let opened = "";
    await client.loginWithRedirect({
      redirectUri: NATIVE_URI,
      openUrl: async (params: { url: string }) => {
        opened = params.url;
      },
    });
    const state = new URL(opened).searchParams.get("state");

    const override = "com.example.app://auth/other";
    await client.handleCallback(`${override}?code=abc&state=${state}`, {
      redirectUri: override,
    });

    expect(postedTokenData().redirect_uri).toBe(override);
  });
});

describe("randomUuid fallback", () => {
  it("generates a v4 UUID via getRandomValues when randomUUID is missing", async () => {
    const { randomUuid } = await import("../utils/randomUuid");
    const cryptoRef = globalThis.crypto as unknown as Record<string, unknown>;
    const real = cryptoRef["randomUUID"];
    try {
      cryptoRef["randomUUID"] = undefined;
      expect(randomUuid()).toMatch(
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
      );
    } finally {
      cryptoRef["randomUUID"] = real;
    }
  });

  it("throws a clear error when WebCrypto is unavailable", async () => {
    const { randomUuid } = await import("../utils/randomUuid");
    const realCrypto = globalThis.crypto;
    try {
      vi.stubGlobal("crypto", undefined);
      expect(() => randomUuid()).toThrow("WebCrypto is not available");
    } finally {
      vi.stubGlobal("crypto", realCrypto);
    }
  });
});

describe("native callback failure modes", () => {
  it("throws a restart-login error when the state has no stored verifier", async () => {
    const client = makeClient();
    await expect(
      client.handleCallback("com.example.app://auth/callback?code=abc&state=unknown-state"),
    ).rejects.toThrow("Missing PKCE verifier");
  });

  it("throws a clear error for a malformed callback URL", async () => {
    const client = makeClient();
    await expect(client.handleCallback(":::not a url")).rejects.toThrow(
      "Malformed callback URL",
    );
  });

  it("cleans up the stored PKCE keys when openUrl rejects", async () => {
    const client = makeClient();
    sessionStorage.clear();
    await expect(
      client.loginWithRedirect({
        redirectUri: "com.example.app://auth/callback",
        openUrl: async () => {
          throw new Error("user cancelled");
        },
      }),
    ).rejects.toThrow("user cancelled");
    expect(sessionStorage.length).toBe(0);
  });

  it("warns when openUrl is used without an explicit redirectUri", async () => {
    const client = makeClient();
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    try {
      await client.loginWithRedirect({
        openUrl: async () => {},
      });
      expect(warn).toHaveBeenCalledTimes(1);
    } finally {
      warn.mockRestore();
    }
  });
});

describe("native logout", () => {
  it("opens the logout URL externally when openUrl is given", async () => {
    const client = makeClient();
    let opened = "";
    await client.logout({
      redirectUri: "com.example.app://auth/callback",
      openUrl: async (params: { url: string }) => {
        opened = params.url;
      },
    });
    const url = new URL(opened);
    expect(url.pathname).toBe("/auth/logout");
    expect(url.searchParams.get("client_id")).toBe("test-client");
    expect(url.searchParams.get("redirect_uri")).toBe("com.example.app://auth/callback");
  });

  it("keeps the web default (window.location.href) without openUrl", async () => {
    const client = makeClient();
    await client.logout();
    const href = (window as unknown as { location: { href: string } }).location.href;
    expect(href).toContain("/auth/logout");
  });
});

describe("logout refresh-before-logout", () => {
  const NATIVE_URI = "com.example.app://auth/callback";

  beforeEach(() => {
    localStorage.setItem(
      "@dowardev/sso-client:pkce:xyz",
      "test-verifier-for-xyz-state",
    );
  });

  async function loginWithStoredRefresh(): Promise<SSOClient> {
    requestMock.mockImplementation(async (cfg: any) => {
      const url = String(cfg?.url ?? "");
      if (url.includes("oauth/token")) {
        return {
          data: {
            token: "stale-access",
            refresh_token: "stored-refresh",
            user: SSO_USER_NULLS,
          },
        };
      }
      if (url.includes("user-auth")) {
        return { data: SSO_USER_NULLS };
      }
      if (url.includes("refresh-token")) {
        return {
          data: {
            token: "fresh-access",
            refresh_token: "fresh-refresh",
            user: SSO_USER_NULLS,
          },
        };
      }
      return { data: null };
    });
    const token = await signJws({ payload: TOKEN_CLAIMS("1"), privateKey: keys.privateKey });
    const callbackUrl = `https://app.example.com/auth/callback?code=abc&state=xyz#profile=${buildFragment({ profile: { name: "Ada", user: "ada", email: "ada@example.com" }, token })}`;
    const client = makeClient();
    await client.handleCallback(callbackUrl);
    // init() mirrors the real app boot: it initializes the DPoP keys so
    // the authenticated refresh/logout POSTs can build proofs.
    await client.init();
    requestMock.mockClear();
    return client;
  }

  function calledUrls(): string[] {
    return requestMock.mock.calls.map((c: any) => String(c?.[0]?.url ?? ""));
  }

  it("refreshes the access token before POSTing logout", async () => {
    const client = await loginWithStoredRefresh();
    await client.logout({
      redirectUri: NATIVE_URI,
      openUrl: async () => {},
    });
    const urls = calledUrls();
    const refreshIdx = urls.findIndex((u) => u.includes("refresh-token"));
    const logoutIdx = urls.findIndex((u) => u.includes("/logout"));
    expect(refreshIdx).toBeGreaterThanOrEqual(0);
    expect(logoutIdx).toBeGreaterThan(refreshIdx);
    // The refresh request carried the stored refresh token.
    const refreshCall = requestMock.mock.calls[refreshIdx][0] as { data: unknown };
    expect(JSON.stringify(refreshCall.data)).toContain("stored-refresh");
    // The logout request carried the fresh bearer, so the server can
    // attribute it and revoke sessions + refresh tokens.
    const logoutCall = requestMock.mock.calls[logoutIdx][0] as { headers?: Record<string, string> };
    expect(logoutCall.headers?.["Authorization"]).toBe("Bearer fresh-access");
    // The rotated refresh token travels in the logout body: the server revokes
    // it even when the bearer cannot be attributed to a user.
    const logoutBody = JSON.stringify((requestMock.mock.calls[logoutIdx][0] as { data: unknown }).data);
    expect(logoutBody).toContain("fresh-refresh");
    // The identity is dropped right after, so the logout path must not spend a
    // user-auth round trip resolving it.
    expect(urls.some((u) => u.includes("user-auth"))).toBe(false);
  });

  it("logs out without a DPoP proof when no key pair was ever initialized", async () => {
    const token = await signJws({ payload: TOKEN_CLAIMS("1"), privateKey: keys.privateKey });
    const callbackUrl = `https://app.example.com/auth/callback?code=abc&state=xyz#profile=${buildFragment({
      profile: { name: "Ada", user: "ada", email: "ada@example.com" },
      token,
    })}`;
    const client = makeClient();
    await client.handleCallback(callbackUrl);
    // Deliberately no init(): this is the "logout right after a fresh boot (or
    // after a previous logout)" path. Asking for a DPoP proof here used to
    // throw and warn on a perfectly legal call.
    const warned = vi.spyOn(console, "warn").mockImplementation(() => {});
    requestMock.mockClear();

    let opened = "";
    await client.logout({
      redirectUri: NATIVE_URI,
      openUrl: async (params: { url: string }) => {
        opened = params.url;
      },
    });

    expect(calledUrls().some((u) => u.includes("/logout"))).toBe(true);
    expect(warned).not.toHaveBeenCalled();
    expect(opened).toContain("/auth/logout");
    warned.mockRestore();
  });

  it("still logs out locally when the refresh fails", async () => {
    const client = await loginWithStoredRefresh();
    requestMock.mockImplementation(async (cfg: any) => {
      const url = String(cfg?.url ?? "");
      if (url.includes("refresh-token")) {
        throw new Error("refresh expired");
      }
      return { data: null };
    });
    requestMock.mockClear();
    let opened = "";
    await client.logout({
      redirectUri: NATIVE_URI,
      openUrl: async (params: { url: string }) => {
        opened = params.url;
      },
    });
    // Falls back to the stale token for the logout POST, then always
    // clears local state and opens the SSO logout page as second chance.
    expect(opened).toContain("/auth/logout");
    expect(client.isAuthenticated).toBe(false);
  });
});

describe("PKCE persistence", () => {
  it("stores the PKCE verifier in localStorage so it survives activity recreation", async () => {
    const client = makeClient();
    let opened = "";
    await client.loginWithRedirect({
      redirectUri: "com.example.app://auth/callback",
      openUrl: async (params: { url: string }) => {
        opened = params.url;
      },
    });
    const state = new URL(opened).searchParams.get("state");
    expect(state).toBeTruthy();
    // sessionStorage dies with the WebView when the OS recreates the
    // activity behind the external browser; localStorage survives.
    expect(localStorage.getItem(`@dowardev/sso-client:pkce:${state}`)).toBeTruthy();
    expect(sessionStorage.getItem(`@dowardev/sso-client:pkce:${state}`)).toBeNull();
    expect(localStorage.getItem(`@dowardev/sso-client:pkce:${state}:redirect-uri`)).toBe(
      "com.example.app://auth/callback",
    );
  });

  it("sweeps the PKCE material of a login attempt that was abandoned", async () => {
    const storage = new TokenStorage();
    const staleKey = "@dowardev/sso-client:pkce:stale-state";
    await storage.setPkceVerifier("stale-state", "stale-verifier");
    await storage.setPkceRedirectUri("stale-state", "com.example.app://auth/callback");
    // Backdate the attempt past the TTL: the browser tab closing used to be the
    // cleanup, which localStorage does not give us.
    localStorage.setItem(`${staleKey}:at`, String(Date.now() - 11 * 60 * 1000));

    await storage.setPkceVerifier("fresh-state", "fresh-verifier");

    expect(localStorage.getItem(staleKey)).toBeNull();
    expect(localStorage.getItem(`${staleKey}:at`)).toBeNull();
    expect(localStorage.getItem(`${staleKey}:redirect-uri`)).toBeNull();
    expect(localStorage.getItem("@dowardev/sso-client:pkce:fresh-state")).toBe("fresh-verifier");
  });

  it("keeps a verifier written before the TTL keys existed", async () => {
    const storage = new TokenStorage();
    // An attempt started by an older version has no `:at` companion; it must
    // still be usable so an in-flight upgrade does not break the login.
    localStorage.setItem("@dowardev/sso-client:pkce:legacy-state", "legacy-verifier");

    expect(await storage.getPkceVerifier("legacy-state")).toBe("legacy-verifier");
    expect(localStorage.getItem("@dowardev/sso-client:pkce:legacy-state")).toBe("legacy-verifier");
  });
});
