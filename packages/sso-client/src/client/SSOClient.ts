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
import {
  AuthState,
  AuthResponse,
  RegisterParams,
  LoginParams,
  User,
} from "../types";

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
  private _isAuthenticated: boolean = false;
  private readonly AUTO_REFRESH_INTERVAL = 3000000;

  readonly http: HttpClient;

  constructor(config: SSOClientConfig) {
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
      try {
        const user = await this.authService.getUser();
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
        await this.tokenStorage.clear();
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
  }): Promise<void> {
    const redirectUri =
      options?.redirectUri || `${window.location.origin}/auth/callback`;
    const codeVerifier = generateCodeVerifier();
    const codeChallenge = await generateCodeChallenge(codeVerifier);
    const state = crypto.randomUUID();

    await this.tokenStorage.setPkceVerifier(state, codeVerifier);

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

    window.location.href = url.toString();
  }

  async handleCallback(url: string): Promise<{ user: User }> {
    const urlObj = new URL(url);
    const params = urlObj.searchParams;

    const code = params.get("code");
    const state = params.get("state");

    if (!code) {
      throw new Error("No authorization code provided in callback");
    }

    const codeVerifier = state
      ? await this.tokenStorage.getPkceVerifier(state)
      : null;

    const data: Record<string, unknown> = {
      grant_type: 'authorization_code',
      code,
      client_id: this.clientId,
      redirect_uri: `${window.location.origin}/auth/callback`,
    };

    if (codeVerifier) {
      data.code_verifier = codeVerifier;
    }

    const dpopPublicKey = this.dpopService.getPublicKeyJwk();
    if (dpopPublicKey) {
      data.dpop_public_key = JSON.stringify(dpopPublicKey);
    }

    const response = await this.internalPost<{
      token: string;
      token_type: string;
      expires_in: number;
      user: User;
      refreshToken?: string;
    }>("oauth/token", data);

    if (state) {
      await this.tokenStorage.removePkceVerifier(state);
    }

    await this.handleAuthResponse({
      token: response.token,
      refreshToken: response.refreshToken,
      user: response.user,
    });

    return { user: response.user };
  }

  async register(params: RegisterParams): Promise<void> {
    await this.authService.register(params);
  }

  async logout(options?: { redirectUri?: string }): Promise<void> {
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
    this._isAuthenticated = false;
    this.emitAuthState({
      isAuthenticated: false,
      user: null,
    });

    const redirectUri = options?.redirectUri || `${window.location.origin}/auth/callback`;
    const url = new URL("/auth/logout", this.ssoFrontendUrl);
    url.searchParams.set("client_id", this.clientId);
    url.searchParams.set("redirect_uri", redirectUri);
    window.location.href = url.toString();
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
    if (this.currentUser) return this.currentUser;
    const user = await this.authService.getUser();
    if (user) {
      this.currentUser = user;
    }
    return user;
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
      this.currentUser = response.user;
    } else {
      try {
        const user = await this.authService.getUser();
        if (user) this.currentUser = user;
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

  private handleRefreshFailed(): void {
    this.currentUser = null;
    this._isAuthenticated = false;
    this.tokenStorage.clear();
    this.configService.setAccessToken("");
    this.emitAuthState({
      isAuthenticated: false,
      user: null,
    });
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
