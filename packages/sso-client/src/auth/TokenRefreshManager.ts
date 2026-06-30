import axios, { AxiosError, InternalAxiosRequestConfig } from "axios";
import { TokenStorage } from "../storage/TokenStorage";
import { DpopService } from "../dpop/DpopService";
import { AuthResponse } from "../types";

interface SsoRequester {
  post<T>(url: string, data: object): Promise<T>;
  getSsoUrl(): string;
  getClientId(): string;
  onRefreshFailed(): void;
}

export class TokenRefreshManager {
  private tokenStorage: TokenStorage;
  private dpopService: DpopService;
  private ssoRequester: SsoRequester;
  private isRefreshing = false;
  private autoRefreshTimer: ReturnType<typeof setInterval> | null = null;
  private pendingRequests: Array<{
    resolve: (token: string) => void;
    reject: (error: unknown) => void;
  }> = [];

  constructor(
    tokenStorage: TokenStorage,
    dpopService: DpopService,
    ssoRequester: SsoRequester
  ) {
    this.tokenStorage = tokenStorage;
    this.dpopService = dpopService;
    this.ssoRequester = ssoRequester;
  }

  async handle401Error(
    failedRequest: AxiosError,
    recreateRequest: (config: InternalAxiosRequestConfig, token: string) => Promise<unknown>
  ): Promise<unknown> {
    const originalConfig = failedRequest.config as InternalAxiosRequestConfig & {
      _retry?: boolean;
    };

    if (originalConfig._retry) {
      return Promise.reject(failedRequest);
    }

    originalConfig._retry = true;

    if (this.isRefreshing) {
      return new Promise((resolve, reject) => {
        this.pendingRequests.push({
          resolve: (newToken: string) => {
            recreateRequest(originalConfig, newToken).then(resolve).catch(reject);
          },
          reject,
        });
      });
    }

    this.isRefreshing = true;

    try {
      const newToken = await this.refreshToken();
      this.processPendingRequests(newToken);
      return await recreateRequest(originalConfig, newToken);
    } catch (error) {
      this.rejectPendingRequests(error);
      this.ssoRequester.onRefreshFailed();
      return Promise.reject(error);
    } finally {
      this.isRefreshing = false;
    }
  }

  private async refreshToken(): Promise<string> {
    const refreshToken = await this.tokenStorage.getRefreshToken();

    if (!refreshToken) {
      throw new Error("No refresh token available");
    }

    const ssoUrl = this.ssoRequester.getSsoUrl();
    const clientId = this.ssoRequester.getClientId();
    const fullUrl = `${ssoUrl.replace(/\/$/, "")}/refresh-token`;

    const accessToken = await this.tokenStorage.getToken();
    const cleanAccessToken = accessToken?.replace(/^Bearer\s+/i, "") || "";
    const dpopProof = cleanAccessToken
      ? await this.dpopService.createDpopProof("POST", fullUrl, cleanAccessToken)
      : undefined;

    const response = await axios.request<AuthResponse>({
      url: fullUrl,
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        ...(cleanAccessToken ? { Authorization: `Bearer ${cleanAccessToken}` } : {}),
        ...(dpopProof ? { DPoP: dpopProof } : {}),
      },
      data: {
        refreshToken,
        client_id: clientId,
      },
    });

    const { token, refreshToken: newRefreshToken } = response.data;

    await this.tokenStorage.setToken(token);
    if (newRefreshToken) {
      await this.tokenStorage.setRefreshToken(newRefreshToken);
    }

    return token;
  }

  private processPendingRequests(newToken: string): void {
    for (const pending of this.pendingRequests) {
      pending.resolve(newToken);
    }
    this.pendingRequests = [];
  }

  private rejectPendingRequests(error: unknown): void {
    for (const pending of this.pendingRequests) {
      pending.reject(error);
    }
    this.pendingRequests = [];
  }

  startAutoRefresh(params: {
    intervalMs: number;
    onRefreshSuccess: (token: string) => void;
    onRefreshFailed: () => void;
  }): void {
    this.stopAutoRefresh();
    this.autoRefreshTimer = setInterval(async () => {
      try {
        const newToken = await this.refreshToken();
        params.onRefreshSuccess(newToken);
      } catch {
        this.stopAutoRefresh();
        params.onRefreshFailed();
      }
    }, params.intervalMs);
  }

  stopAutoRefresh(): void {
    if (this.autoRefreshTimer !== null) {
      clearInterval(this.autoRefreshTimer);
      this.autoRefreshTimer = null;
    }
  }
}
