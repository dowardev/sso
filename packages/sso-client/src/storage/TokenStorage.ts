// SPDX-License-Identifier: MIT
export class TokenStorage {
  private readonly TOKEN_KEY = "@dowardev/sso-client:token";
  private readonly REFRESH_KEY = "@dowardev/sso-client:refresh-token";
  private readonly PKCE_PREFIX = "@dowardev/sso-client:pkce:";
  private readonly REMEMBER_KEY = "@dowardev/sso-client:remember-me";

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

  async getPkceVerifier(state: string): Promise<string | null> {
    return sessionStorage.getItem(`${this.PKCE_PREFIX}${state}`);
  }

  async setPkceVerifier(state: string, verifier: string): Promise<void> {
    sessionStorage.setItem(`${this.PKCE_PREFIX}${state}`, verifier);
  }

  async removePkceVerifier(state: string): Promise<void> {
    sessionStorage.removeItem(`${this.PKCE_PREFIX}${state}`);
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

  async clear(): Promise<void> {
    localStorage.removeItem(this.TOKEN_KEY);
    localStorage.removeItem(this.REFRESH_KEY);
    localStorage.removeItem(this.REMEMBER_KEY);
    for (let i = sessionStorage.length - 1; i >= 0; i--) {
      const key = sessionStorage.key(i);
      if (key?.startsWith(this.PKCE_PREFIX)) {
        sessionStorage.removeItem(key);
      }
    }
  }
}
