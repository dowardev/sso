// SPDX-License-Identifier: MIT
import { DpopService } from "../dpop/DpopService";
import {
  AuthResponse,
  RegisterParams,
  LoginParams,
  RefreshTokenParams,
  RecoverPasswordParams,
  ResetPasswordParams,
  User,
  ICustomError,
} from "../types";

interface InternalClient {
  post<T>(params: { url: string; data?: object }): Promise<T>;
  get<T>(url: string): Promise<T>;
  getClientId(): string;
}

export class AuthService {
  private ssoClient: InternalClient;
  private dpopService: DpopService | null = null;

  constructor(ssoClient: InternalClient) {
    this.ssoClient = ssoClient;
  }

  setDpopService(service: DpopService): void {
    this.dpopService = service;
  }

  async login(params: LoginParams): Promise<AuthResponse> {
    const { email, password, rememberMe } = params;
    const clientId = this.ssoClient.getClientId();
    const data: Record<string, unknown> = {
      email,
      password,
      client_id: clientId,
    };
    if (rememberMe) {
      data.remember_me = true;
    }
    const response = await this.ssoClient.post<AuthResponse>({
      url: "login",
      data,
    });
    return response;
  }

  async register(params: RegisterParams): Promise<void> {
    const dpopPublicKey = this.dpopService?.getPublicKeyJwk();
    const data: Record<string, unknown> = {
      ...params,
      client_id: this.ssoClient.getClientId(),
    };

    if (dpopPublicKey) {
      data.dpop_public_key = JSON.stringify(dpopPublicKey);
    }

    await this.ssoClient.post({
      url: "register",
      data,
    });
  }

  async logout(): Promise<void> {
    await this.dpopService?.clearKeys();
  }

  async refreshToken(params: RefreshTokenParams): Promise<AuthResponse> {
    const response = await this.ssoClient.post<AuthResponse>({
      url: "refresh-token",
      data: { refreshToken: params.refreshToken },
    });
    return response;
  }

  async recoverPassword(params: RecoverPasswordParams): Promise<void> {
    await this.ssoClient.post({
      url: "recover-password",
      data: { email: params.email },
    });
  }

  async resetPassword(params: ResetPasswordParams): Promise<void> {
    const { email, code, password } = params;
    await this.ssoClient.post({
      url: "reset-password",
      data: { email, code, password },
    });
  }

  async resendEmailVerification(email: string): Promise<void> {
    await this.ssoClient.post({
      url: "resend-confirmation-email",
      data: { email },
    });
  }

  async getUser(): Promise<User | null> {
    const clientId = this.getClientId();
    if (!clientId) {
      console.warn("SSO Client: client_id not configured");
      return null;
    }

    try {
      const user = await this.ssoClient.get<User>(
        `user-auth?client_id=${clientId}`
      );
      return user;
    } catch (error) {
      const err = error as ICustomError & { response?: { status?: number } };
      const isAuthDead =
        err.errorDetail === "Unauthenticated." ||
        err.response?.status === 401;
      if (isAuthDead) {
        return null;
      }
      throw error;
    }
  }

  private getClientId(): string | null {
    const clientId = this.ssoClient.getClientId();
    return clientId || null;
  }
}
