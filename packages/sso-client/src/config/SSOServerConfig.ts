// SPDX-License-Identifier: MIT
export class SSOServerConfig {
  private baseUrl: string = "";
  private ssoFrontendUrl: string = "";
  private apiBaseUrl: string = "";
  private clientId: string = "";
  private accessToken: string = "";

  constructor(params: {
    baseUrl: string;
    ssoFrontendUrl?: string;
    apiBaseUrl?: string;
    clientId?: string;
    accessToken?: string;
  }) {
    if (!params) return;
    this.baseUrl = params.baseUrl ?? "";
    this.ssoFrontendUrl = params.ssoFrontendUrl ?? "";
    this.apiBaseUrl = params.apiBaseUrl ?? "";
    this.clientId = params.clientId ?? "";
    this.accessToken = params.accessToken ?? "";
  }

  setAccessToken(token: string): void {
    this.accessToken = token;
  }

  getConfig(): {
    baseUrl: string;
    ssoFrontendUrl: string;
    apiBaseUrl: string;
    clientId: string;
    accessToken: string;
  } {
    return {
      baseUrl: this.baseUrl,
      ssoFrontendUrl: this.ssoFrontendUrl,
      apiBaseUrl: this.apiBaseUrl,
      clientId: this.clientId,
      accessToken: this.accessToken,
    };
  }
}
