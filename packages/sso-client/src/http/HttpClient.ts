import axios, {
  AxiosInstance,
  AxiosRequestConfig,
  AxiosResponse,
  InternalAxiosRequestConfig,
} from "axios";
import { TokenStorage } from "../storage/TokenStorage";
import { DpopService } from "../dpop/DpopService";
import { TokenRefreshManager } from "../auth/TokenRefreshManager";

export interface HttpClientConfig {
  apiBaseUrl: string;
  tokenStorage: TokenStorage;
  dpopService: DpopService;
  tokenRefreshManager: TokenRefreshManager;
}

export class HttpClient {
  private axiosInstance: AxiosInstance;
  private tokenStorage: TokenStorage;
  private dpopService: DpopService;
  private tokenRefreshManager: TokenRefreshManager;

  constructor(config: HttpClientConfig) {
    this.tokenStorage = config.tokenStorage;
    this.dpopService = config.dpopService;
    this.tokenRefreshManager = config.tokenRefreshManager;

    this.axiosInstance = axios.create({
      baseURL: config.apiBaseUrl,
    });

    this.setupInterceptors();
  }

  async get<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.axiosInstance.get<T>(url, config);
    return response.data;
  }

  async post<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.axiosInstance.post<T>(url, data, config);
    return response.data;
  }

  async put<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.axiosInstance.put<T>(url, data, config);
    return response.data;
  }

  async patch<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.axiosInstance.patch<T>(url, data, config);
    return response.data;
  }

  async delete<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.axiosInstance.delete<T>(url, config);
    return response.data;
  }

  private setupInterceptors(): void {
    this.axiosInstance.interceptors.request.use(
      async (config: InternalAxiosRequestConfig) => {
        const skipAuth = (config as AxiosRequestConfig & { skipAuth?: boolean }).skipAuth;
        if (skipAuth) return config;

        const token = await this.tokenStorage.getToken();
        if (!token) return config;

        const cleanToken = token.replace(/^Bearer\s+/i, "");
        config.headers.set("Authorization", `Bearer ${cleanToken}`);

        const fullUrl = this.buildFullUrl(config.baseURL, config.url);
        try {
          const dpopProof = await this.dpopService.createDpopProof(
            (config.method || "GET").toUpperCase(),
            fullUrl,
            cleanToken
          );
          config.headers.set("DPoP", dpopProof);
        } catch {
          // DPoP not available, continue without it
        }

        return config;
      }
    );

    this.axiosInstance.interceptors.response.use(
      (response: AxiosResponse) => response,
      async (error) => {
        if (error.response?.status === 401) {
          const originalConfig = error.config as InternalAxiosRequestConfig & {
            _retry?: boolean;
            skipAuth?: boolean;
          };

          if (originalConfig.skipAuth || originalConfig._retry) {
            return Promise.reject(error);
          }

          return this.tokenRefreshManager.handle401Error(
            error,
            async (failedConfig, newToken) => {
              const cleanToken = newToken.replace(/^Bearer\s+/i, "");
              failedConfig.headers.set("Authorization", `Bearer ${cleanToken}`);

              const fullUrl = this.buildFullUrl(
                failedConfig.baseURL,
                failedConfig.url
              );
              try {
                const dpopProof = await this.dpopService.createDpopProof(
                  (failedConfig.method || "GET").toUpperCase(),
                  fullUrl,
                  cleanToken
                );
                failedConfig.headers.set("DPoP", dpopProof);
              } catch {
                // DPoP not available
              }

              return this.axiosInstance.request(failedConfig);
            }
          );
        }
        return Promise.reject(error);
      }
    );
  }

  private buildFullUrl(baseURL?: string, url?: string): string {
    if (!url) return baseURL || "";
    if (url.startsWith("http")) return url;
    const base = (baseURL || "").replace(/\/$/, "");
    return `${base}/${url.replace(/^\//, "")}`;
  }
}
