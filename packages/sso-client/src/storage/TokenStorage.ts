// SPDX-License-Identifier: MIT
export class TokenStorage {
  private readonly TOKEN_KEY = "@dowardev/sso-client:token";
  private readonly REFRESH_KEY = "@dowardev/sso-client:refresh-token";
  private readonly PKCE_PREFIX = "@dowardev/sso-client:pkce:";
  private readonly REMEMBER_KEY = "@dowardev/sso-client:remember-me";
  private readonly PKCE_TTL_MS = 10 * 60 * 1000;

  async getToken(): Promise<string | null> {
    return localStorage.getItem(this.TOKEN_KEY);
  }

  async setToken(token: string): Promise<void> {
    localStorage.setItem(this.TOKEN_KEY, token);
  }

  async getRefreshToken(): Promise<string | null> {
    return localStorage.getItem(this.REFRESH_KEY);
  }

  async setRefreshToken(token: string): Promise<void> {
    localStorage.setItem(this.REFRESH_KEY, token);
  }

  // NOTE: PKCE material lives in localStorage, NOT sessionStorage.
  // On native (Capacitor Custom Tabs) the OS routinely recreates the
  // activity while the external browser is in front; a recreated WebView
  // starts with an empty sessionStorage, so the verifier stored at
  // login-tap time is gone when the deep-link return arrives and the
  // code exchange dies with 'Missing PKCE verifier'. localStorage
  // survives recreation. The verifier is single-use with a ~10min TTL
  // and is useless without the authorization code, so the threat model
  // does not change. Key names are unchanged (seamless upgrade).
  async getPkceVerifier(state: string): Promise<string | null> {
    this.sweepExpiredPkce();
    return localStorage.getItem(`${this.PKCE_PREFIX}${state}`);
  }

  async setPkceVerifier(state: string, verifier: string): Promise<void> {
    // The companion `:at` key exists so abandoned login attempts can be swept:
    // sessionStorage used to clean itself up when the tab closed, localStorage
    // does not, and an app that opens a login and walks away would otherwise
    // keep one verifier (plus its redirect URI) forever, per attempt.
    this.sweepExpiredPkce();
    localStorage.setItem(`${this.PKCE_PREFIX}${state}`, verifier);
    localStorage.setItem(`${this.PKCE_PREFIX}${state}:at`, String(Date.now()));
  }

  async removePkceVerifier(state: string): Promise<void> {
    localStorage.removeItem(`${this.PKCE_PREFIX}${state}`);
  }

  async getPkceRedirectUri(state: string): Promise<string | null> {
    return localStorage.getItem(`${this.PKCE_PREFIX}${state}:redirect-uri`);
  }

  async setPkceRedirectUri(state: string, redirectUri: string): Promise<void> {
    localStorage.setItem(`${this.PKCE_PREFIX}${state}:redirect-uri`, redirectUri);
  }

  async removePkceRedirectUri(state: string): Promise<void> {
    localStorage.removeItem(`${this.PKCE_PREFIX}${state}:redirect-uri`);
  }

  async getRememberMe(): Promise<boolean> {
    return localStorage.getItem(this.REMEMBER_KEY) === "true";
  }

  async setRememberMe(value: boolean): Promise<void> {
    localStorage.setItem(this.REMEMBER_KEY, String(value));
  }

  async removeRememberMe(): Promise<void> {
    localStorage.removeItem(this.REMEMBER_KEY);
  }

  // Removes PKCE entries whose login attempt is older than the TTL. Entries
  // written by an older version have no `:at` companion and are left alone, so
  // a login that started before an upgrade can still complete.
  private sweepExpiredPkce(): void {
    const now = Date.now();
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const key = localStorage.key(i);
      if (!key?.startsWith(this.PKCE_PREFIX)) continue;
      if (key.endsWith(":at") || key.endsWith(":redirect-uri")) continue;
      const stamp = localStorage.getItem(`${key}:at`);
      if (stamp === null) continue;
      if (now - Number(stamp) <= this.PKCE_TTL_MS) continue;
      localStorage.removeItem(key);
      localStorage.removeItem(`${key}:at`);
      localStorage.removeItem(`${key}:redirect-uri`);
    }
  }

  async clear(): Promise<void> {
    localStorage.removeItem(this.TOKEN_KEY);
    localStorage.removeItem(this.REFRESH_KEY);
    localStorage.removeItem(this.REMEMBER_KEY);
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const key = localStorage.key(i);
      if (key?.startsWith(this.PKCE_PREFIX)) {
        localStorage.removeItem(key);
      }
    }
  }
}
