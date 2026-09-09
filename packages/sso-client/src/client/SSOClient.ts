// SPDX-License-Identifier: MIT
import axios from "axios";
import { SSOServerConfig } from "../config/SSOServerConfig";
import { DpopService } from "../dpop/DpopService";
import { AuthService } from "../auth/AuthService";
import { TokenStorage } from "../storage/TokenStorage";
import { TokenRefreshManager } from "../auth/TokenRefreshManager";
import { HttpClient } from "../http/HttpClient";
import {
  generateCodeVerifier,
  generateCodeChallenge,
} from "../utils/pkce";
import { randomUuid } from "../utils/randomUuid";

function defaultCallbackUri(): string {
  return `${window.location.origin}/auth/callback`;
}

export type OpenUrlHandler = (params: { url: string }) => void | Promise<void>;
import {
  AuthState,
  AuthResponse,
  RegisterParams,
  LoginParams,
  User,
} from "../types";
import { verifyProfileFragment } from "../crypto/profileSignature";
import {
  persistProfileAtRest,
  loadProfileAtRest,
  clearProfileAtRest,
} from "../crypto/profileVault";
import type { UserProfile } from "../types/crypto";

interface SSOClientConfig {
  ssoUrl: string;
  ssoFrontendUrl: string;
  apiBaseUrl: string;
  clientId: string;
}

export class SSOClient {
  private configService: SSOServerConfig;
  private baseUrl: string = "";
  private ssoFrontendUrl: string = "";
  private clientId: string = "";
  private dpopService: DpopService;
  private authService: AuthService;
  private tokenStorage: TokenStorage;
  private tokenRefreshManager: TokenRefreshManager;
  private authStateListeners: Array<(state: AuthState) => void> = [];
  private currentUser: User | null = null;
  private activeProfile: UserProfile | null = null;
  private _isAuthenticated: boolean = false;
  private readonly AUTO_REFRESH_INTERVAL = 3000000;

  readonly http: HttpClient;

  constructor(config: SSOClientConfig) {
    if (!config.ssoUrl || !isValidUrl(config.ssoUrl)) {
      throw new Error(
        "SSOClient: ssoUrl is required and must be a valid URL (e.g., https://auth.example.com/api/)"
      );
    }
    if (!config.ssoFrontendUrl || !isValidUrl(config.ssoFrontendUrl)) {
      throw new Error(
        "SSOClient: ssoFrontendUrl is required and must be a valid URL (e.g., https://auth.example.com)"
      );
    }
    if (!config.apiBaseUrl || !isValidUrl(config.apiBaseUrl)) {
      throw new Error(
        "SSOClient: apiBaseUrl is required and must be a valid URL (e.g., https://api.example.com/api/)"
      );
    }
    if (!config.clientId) {
      throw new Error("SSOClient: clientId is required");
    }
    this.configService = new SSOServerConfig({
      baseUrl: config.ssoUrl,
      ssoFrontendUrl: config.ssoFrontendUrl,
      apiBaseUrl: config.apiBaseUrl,
      clientId: config.clientId,
    });
    const cfg = this.configService.getConfig();
    this.baseUrl = cfg.baseUrl;
    this.ssoFrontendUrl = cfg.ssoFrontendUrl;
    this.clientId = cfg.clientId;

    this.dpopService = new DpopService();
    this.tokenStorage = new TokenStorage();
    this.authService = new AuthService(
      new SSOClientInternal(this.configService, this.dpopService)
    );
    this.authService.setDpopService(this.dpopService);

    this.tokenRefreshManager = new TokenRefreshManager(
      this.tokenStorage,
      this.dpopService,
      {
        post: <T>(url: string, data: object) => this.internalPost<T>(url, data),
        getSsoUrl: () => this.baseUrl,
        getClientId: () => this.clientId,
        onRefreshFailed: () => this.handleRefreshFailed(),
      }
    );

    this.http = new HttpClient({
      apiBaseUrl: cfg.apiBaseUrl,
      tokenStorage: this.tokenStorage,
      dpopService: this.dpopService,
      tokenRefreshManager: this.tokenRefreshManager,
    });
  }

  get isAuthenticated(): boolean {
    return this._isAuthenticated;
  }

  async init(): Promise<void> {
    await this.dpopService.initialize();
    const token = await this.tokenStorage.getToken();
    if (token) {
      this.configService.setAccessToken(token);
      if (!this.activeProfile) {
        this.activeProfile = await loadProfileAtRest();
      }
      try {
        const user = await this.getUser();
        if (user) {
          this.currentUser = user;
          this._isAuthenticated = true;
          this.emitAuthState({
            isAuthenticated: true,
            user,
          });
          const rememberMe = await this.tokenStorage.getRememberMe();
          if (rememberMe) {
            this.startAutoRefresh();
          }
          return;
        }
      } catch {
        // Transient error (network, 5xx) — preserve token for next init
      }
    }
    this._isAuthenticated = false;
    this.emitAuthState({
      isAuthenticated: false,
      user: null,
    });
  }

  async login(params: LoginParams): Promise<AuthResponse> {
    const response = await this.authService.login(params);
    await this.handleAuthResponse(response);

    const rememberMe = params.rememberMe ?? false;
    await this.tokenStorage.setRememberMe(rememberMe);

    if (rememberMe) {
      this.startAutoRefresh();
    }

    return response;
  }

  async loginWithRedirect(options?: {
    redirectUri?: string;
    screenHint?: "login" | "register";
    openUrl?: OpenUrlHandler;
  }): Promise<void> {
    const redirectUri =
      options?.redirectUri || defaultCallbackUri();
    if (options?.openUrl && !options.redirectUri) {
      console.warn(
        '[sso-client] openUrl without an explicit redirectUri falls back to the web callback URI, which cannot return to a native app.',
      );
    }
    const codeVerifier = generateCodeVerifier();
    const codeChallenge = await generateCodeChallenge(codeVerifier);
    const state = randomUuid();

    await this.tokenStorage.setPkceVerifier(state, codeVerifier);
    await this.tokenStorage.setPkceRedirectUri(state, redirectUri);

    const url = new URL(
      options?.screenHint === "register" ? "/auth/register" : "/auth/login",
      this.ssoFrontendUrl
    );
    url.searchParams.set("client_id", this.clientId);
    url.searchParams.set("redirect_uri", redirectUri);
    url.searchParams.set("response_type", "code");
    url.searchParams.set("state", state);
    url.searchParams.set("code_challenge", codeChallenge);
    url.searchParams.set("code_challenge_method", "S256");

    if (options?.openUrl) {
      try {
        await options.openUrl({ url: url.toString() });
      } catch (error) {
        await this.tokenStorage.removePkceVerifier(state);
        await this.tokenStorage.removePkceRedirectUri(state);
        throw error;
      }
      return;
    }
    window.location.href = url.toString();
  }

  async handleCallback(url: string, options?: { redirectUri?: string }): Promise<{ user: User }> {
    let urlObj: URL;
    try {
      urlObj = new URL(url);
    } catch {
      throw new Error('Malformed callback URL. Please restart the login.');
    }
    const params = urlObj.searchParams;

    const frag =
      urlObj.hash.startsWith("#profile=")
        ? urlObj.hash.slice("#profile=".length)
        : null;

    const code = params.get("code");
    const state = params.get("state");

    if (!code) {
      throw new Error("No authorization code provided in callback");
    }

    const codeVerifier = state
      ? await this.tokenStorage.getPkceVerifier(state)
      : null;
    if (state && !codeVerifier) {
      throw new Error('Missing PKCE verifier for this login attempt. Please restart the login.');
    }
    const storedRedirectUri = state
      ? await this.tokenStorage.getPkceRedirectUri(state)
      : null;

    const data: Record<string, unknown> = {
      grant_type: 'authorization_code',
      code,
      client_id: this.clientId,
      redirect_uri: options?.redirectUri || storedRedirectUri || defaultCallbackUri(),
    };

    if (codeVerifier) {
      data.code_verifier = codeVerifier;
    }

    const dpopPublicKey = this.dpopService.getPublicKeyJwk();
    if (dpopPublicKey) {
      data.dpop_public_key = JSON.stringify(dpopPublicKey);
    }

    let response: {
      token: string;
      token_type: string;
      expires_in: number;
      user: User;
      refresh_token?: string;
      refreshToken?: string;
    };
    try {
      response = await this.internalPost<{
        token: string;
        token_type: string;
        expires_in: number;
        user: User;
        refresh_token?: string;
        refreshToken?: string;
      }>("oauth/token", data);
    } finally {
      if (state) {
        await this.tokenStorage.removePkceVerifier(state);
        await this.tokenStorage.removePkceRedirectUri(state);
      }
    }

    if (frag) {
      try {
        this.activeProfile = await verifyProfileFragment({
          fragment: frag,
          ssoUrl: this.baseUrl,
          expectedUserId: String(response.user.id),
        });
        await persistProfileAtRest(this.activeProfile);
      } catch {
        this.activeProfile = null;
      }
    }

    await this.handleAuthResponse({
      token: response.token,
      refreshToken: response.refresh_token ?? response.refreshToken,
      user: this.mergeProfile(response.user),
    });

    return { user: this.mergeProfile(response.user) };
  }

  async register(params: RegisterParams): Promise<void> {
    await this.authService.register(params);
  }

  async logout(options?: { redirectUri?: string; openUrl?: OpenUrlHandler }): Promise<void> {
    this.stopAutoRefresh();
    await this.tokenStorage.removeRememberMe();
    try {
      await this.internalPost("logout", {});
    } catch {
      // ignore errors on logout — the SSO frontend logout page will handle server-side cleanup
    }
    await this.authService.logout();
    await this.tokenStorage.clear();
    this.configService.setAccessToken("");
    this.currentUser = null;
    this.activeProfile = null;
    await clearProfileAtRest();
    this._isAuthenticated = false;
    this.emitAuthState({
      isAuthenticated: false,
      user: null,
    });

    const redirectUri = options?.redirectUri || defaultCallbackUri();
    const url = new URL("/auth/logout", this.ssoFrontendUrl);
    url.searchParams.set("client_id", this.clientId);
    url.searchParams.set("redirect_uri", redirectUri);
    const logoutUrl = url.toString();
    if (options?.openUrl) {
      await options.openUrl({ url: logoutUrl });
      return;
    }
    window.location.href = logoutUrl;
  }

  async refreshToken(refreshToken: string): Promise<AuthResponse> {
    const response = await this.authService.refreshToken({ refreshToken });
    await this.handleAuthResponse(response);
    return response;
  }

  async recoverPassword(params: { email: string }): Promise<void> {
    await this.authService.recoverPassword(params);
  }

  async resetPassword(params: {
    email: string;
    code: number | string;
    password: string;
  }): Promise<void> {
    await this.authService.resetPassword(params);
  }

  async resendEmailVerification(email: string): Promise<void> {
    await this.authService.resendEmailVerification(email);
  }

  async getUser(): Promise<User | null> {
    if (this.currentUser) return this.mergeProfile(this.currentUser);
    const user = await this.authService.getUser();
    if (user) {
      this.currentUser = this.mergeProfile(user);
    }
    return this.currentUser;
  }

  async checkSession(): Promise<{ authenticated: boolean; user?: User }> {
    const result = await this.authService.checkSession();

    if (result.authenticated) {
      this.currentUser = result.user ?? this.currentUser;
      this._isAuthenticated = true;
    } else {
      this.currentUser = null;
      this._isAuthenticated = false;
    }

    this.emitAuthState({
      isAuthenticated: this._isAuthenticated,
      user: this.currentUser,
    });

    return result;
  }

  onAuthStateChange(callback: (state: AuthState) => void): () => void {
    this.authStateListeners.push(callback);
    callback({
      isAuthenticated: this._isAuthenticated,
      user: this.currentUser,
    });
    return () => {
      const idx = this.authStateListeners.indexOf(callback);
      if (idx >= 0) this.authStateListeners.splice(idx, 1);
    };
  }

  private async startAutoRefresh(): Promise<void> {
    this.tokenRefreshManager.startAutoRefresh({
      intervalMs: this.AUTO_REFRESH_INTERVAL,
      onRefreshSuccess: (newToken: string) => {
        this.configService.setAccessToken(newToken);
      },
      onRefreshFailed: () => {
        this.handleRefreshFailed();
      },
    });
  }

  private stopAutoRefresh(): void {
    this.tokenRefreshManager.stopAutoRefresh();
  }

  private async handleAuthResponse(response: AuthResponse): Promise<void> {
    if (response.token) {
      await this.tokenStorage.setToken(response.token);
      this.configService.setAccessToken(response.token);
    }
    if (response.refreshToken) {
      await this.tokenStorage.setRefreshToken(response.refreshToken);
    }
    if (response.user) {
      this.currentUser = this.mergeProfile(response.user);
    } else {
      try {
        const user = await this.authService.getUser();
        if (user) this.currentUser = this.mergeProfile(user);
      } catch {
        // ignore
      }
    }
    this._isAuthenticated = !!this.currentUser;
    this.emitAuthState({
      isAuthenticated: this._isAuthenticated,
      user: this.currentUser,
    });
  }

  private async handleRefreshFailed(): Promise<void> {
    this.currentUser = null;
    this._isAuthenticated = false;
    const refreshToken = await this.tokenStorage.getRefreshToken();
    if (refreshToken) {
      await this.tokenStorage.clear();
      this.configService.setAccessToken("");
    }
    this.emitAuthState({
      isAuthenticated: false,
      user: null,
    });
  }

  private mergeProfile(user: User): User {
    if (!user || !this.activeProfile) return user;
    const p = this.activeProfile;
    return {
      ...user,
      name: p.name ?? user.name,
      user: p.user ?? user.user,
      email: p.email ?? user.email,
    };
  }

  private emitAuthState(state: AuthState): void {
    for (const listener of this.authStateListeners) {
      listener(state);
    }
  }

  private getFullUrl(url: string): string {
    const base = this.baseUrl.endsWith("/")
      ? this.baseUrl.slice(0, -1)
      : this.baseUrl;
    return `${base}/${url}`;
  }

  private async internalPost<T>(url: string, data: object): Promise<T> {
    const accessToken = this.configService.getConfig().accessToken;
    const cleanToken = accessToken.replace(/^Bearer\s+/i, "");
    const dpopProof = accessToken
      ? await this.dpopService.createDpopProof("POST", this.getFullUrl(url), cleanToken)
      : undefined;

    const response = await axios.request<T>({
      url: this.getFullUrl(url),
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...(accessToken ? { Authorization: `Bearer ${cleanToken}` } : {}),
        ...(dpopProof ? { DPoP: dpopProof } : {}),
      },
      data,
    });
    return response.data;
  }
}

function isValidUrl(url: string): boolean {
  try {
    new URL(url);
    return true;
  } catch {
    return false;
  }
}

class SSOClientInternal {
  private configService: SSOServerConfig;
  private dpopService: DpopService;

  constructor(configService: SSOServerConfig, dpopService: DpopService) {
    this.configService = configService;
    this.dpopService = dpopService;
  }

  getClientId(): string {
    return this.configService.getConfig().clientId;
  }

  async get<T>(url: string): Promise<T> {
    return this.request<T>("GET", url);
  }

  async post<T>(params: { url: string; data?: object }): Promise<T> {
    return this.request<T>("POST", params.url, params.data);
  }

  private async request<T>(
    method: string,
    url: string,
    data?: object
  ): Promise<T> {
    const accessToken = this.configService.getConfig().accessToken;
    const cleanToken = accessToken.replace(/^Bearer\s+/i, "");
    const fullUrl = this.getFullUrl(url);
    const dpopProof = accessToken
      ? await this.dpopService.createDpopProof(
          method.toUpperCase(),
          fullUrl,
          cleanToken
        )
      : undefined;

    const response = await axios.request<T>({
      url: fullUrl,
      method,
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        ...(accessToken ? { Authorization: `Bearer ${cleanToken}` } : {}),
        ...(dpopProof ? { DPoP: dpopProof } : {}),
      },
      ...(data ? { data } : {}),
      ...(method === "GET" && data ? { params: data } : {}),
    });
    return response.data;
  }

  private getFullUrl(url: string): string {
    const base = this.configService
      .getConfig()
      .baseUrl.replace(/\/$/, "");
    return `${base}/${url}`;
  }
}
