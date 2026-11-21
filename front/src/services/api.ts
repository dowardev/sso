// SPDX-License-Identifier: MIT
import type {
  User,
  UserDetail,
  UserSession,
  SsoClient,
  PaginatedResponse,
  DashboardStats,
  Role,
  UserRole,
} from '@/types';

import type { ApiErrorDetail } from '@/utils/apiErrorCodes';

export type {
  User,
  UserDetail,
  UserSession,
  SsoClient,
  PaginatedResponse,
  DashboardStats,
  Role,
  UserRole,
};

const TOKEN_KEY = 'token';

const API_URL = import.meta.env.VITE_API_URL;

const pendingRequests = new Map<string, Promise<unknown>>();

const getHeaders = () => {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };

  const token = localStorage.getItem(TOKEN_KEY);
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  // No `X-XSRF-TOKEN` here on purpose.
  //
  // The backend never validates a CSRF token: the API routes run without
  // `VerifyCsrfToken` and without Sanctum's stateful middleware, so the
  // header was dead weight. Worse, it was harmful: the Laravel web route
  // `/` sets an `XSRF-TOKEN` cookie, and cookies are scoped by host+path
  // (not by port), so the frontend on `localhost:8101` picked up the cookie
  // set by the backend on `localhost:8001` and started sending the header.
  // That header is not in the CORS allow-list (`back/config/cors.php`), so
  // the preflight failed and the browser blocked every request with
  // `TypeError: Failed to fetch` — surfacing as a generic login error.

  return headers;
};

let refreshPromise: Promise<boolean> | null = null;

async function refreshToken(): Promise<boolean> {
  if (refreshPromise) return refreshPromise;

  refreshPromise = (async () => {
    try {
      const refreshResponse = await fetch(
        `${API_URL.replace(/\/+$/, '')}/refresh-token`,
        {
          method: 'POST',
          credentials: 'include',
          headers: { Accept: 'application/json' },
        }
      );

      if (!refreshResponse.ok) return false;

      const data = await refreshResponse.json();
      if (data.token) {
        localStorage.setItem('token', data.token);
        return true;
      }
      return false;
    } catch {
      return false;
    } finally {
      refreshPromise = null;
    }
  })();

  return refreshPromise;
}

async function fetchApi<T>(params: {
  endpoint: string;
  options?: RequestInit;
}): Promise<T> {
  const { endpoint, options = {} } = params;
  const baseUrl = API_URL.replace(/\/+$/, '');
  const normalizedEndpoint = endpoint.replace(/^\/+/, '');

  const isGetRequest = !options.method || options.method === 'GET';
  const pendingKey = isGetRequest ? endpoint : '';
  if (isGetRequest && pendingRequests.has(pendingKey)) {
    return pendingRequests.get(pendingKey) as Promise<T>;
  }

  const doFetch = (): Promise<Response> =>
    fetch(`${baseUrl}/${normalizedEndpoint}`, {
      ...options,
      headers: {
        ...getHeaders(),
        ...options.headers,
      },
      credentials: 'include',
    });

  const executeWithRetry = async (): Promise<T> => {
    let response = await doFetch();

    if (response.status === 401 && endpoint !== '/login' && endpoint !== '/refresh-token' && getHeaders()['Authorization']) {
      const refreshed = await refreshToken();
      if (refreshed) {
        response = await doFetch();
      } else {
        const { useAuthStore } = await import('@/stores/auth');
        const authStore = useAuthStore();
        await authStore.logout();
        const routerModule = await import('@/router');
        routerModule.default.push('/login');
        throw new Error('Sesión expirada');
      }
    }

    if (!response.ok) {
      const error = await response
        .json()
        .catch(() => ({ message: 'Error de conexión' }));
          console.error('[API Error]', { endpoint, status: response.status, payload: error });
      const apiError = new Error(error.message || 'Error en la solicitud') as Error & {
        code?: string;
        status?: number;
        email?: string;
            errors?: Record<string, string[]>;
        error_details?: Record<string, ApiErrorDetail[]>;
      };
      if (error.code) apiError.code = error.code;
      if (error.email) apiError.email = error.email;
      // Machine-readable channel (odd/tasks/api-error-contract-codes.md):
      // `error_details` carries the stable code per field. The legacy `errors`
      // map below keeps working for responses without codes.
      if (error.error_details && typeof error.error_details === 'object' && !Array.isArray(error.error_details)) {
        const details: Record<string, ApiErrorDetail[]> = {};
        for (const [field, value] of Object.entries(error.error_details as Record<string, unknown>)) {
          const entries = Array.isArray(value) ? value : [value];
          const shaped = entries
            .filter((entry): entry is Record<string, unknown> => typeof entry === 'object' && entry !== null)
            .map((entry) => ({
              code: typeof entry.code === 'string' ? entry.code : undefined,
              field: typeof entry.field === 'string' ? entry.field : field,
              message: typeof entry.message === 'string' ? entry.message : '',
              params:
                typeof entry.params === 'object' && entry.params !== null
                  ? (entry.params as Record<string, string | number>)
                  : undefined,
            }));
          if (shaped.length > 0) details[field] = shaped;
        }
        if (Object.keys(details).length > 0) apiError.error_details = details;
      }
          if (error.errors && typeof error.errors === 'object' && !Array.isArray(error.errors)) {
            const shaped: Record<string, string[]> = {}
            for (const [key, value] of Object.entries(error.errors)) {
              if (Array.isArray(value)) shaped[key] = value.map((item) => String(item))
              else if (typeof value === 'string') shaped[key] = [value]
            }
            if (Object.keys(shaped).length > 0) apiError.errors = shaped
          }
      apiError.status = response.status;
      throw apiError;
    }

    return response.json();
  };

  if (isGetRequest) {
    const promise = executeWithRetry().finally(() => {
      pendingRequests.delete(pendingKey);
    });
    pendingRequests.set(pendingKey, promise);
    return promise;
  }

  return executeWithRetry();
}

export const api = {
  get: <T>(params: { endpoint: string; headers?: Record<string, string> }) =>
    fetchApi<T>({
      endpoint: params.endpoint,
      options: { headers: params.headers },
    }),
  post: <T>(params: {
    endpoint: string;
    data?: unknown;
    extraHeaders?: Record<string, string>;
  }) =>
    fetchApi<T>({
      endpoint: params.endpoint,
      options: {
        method: 'POST',
        body: params.data ? JSON.stringify(params.data) : undefined,
        headers: {
          'Content-Type': 'application/json',
          ...params.extraHeaders,
        },
      },
    }),
  put: <T>(params: {
    endpoint: string;
    data: unknown;
    headers?: Record<string, string>;
  }) =>
    fetchApi<T>({
      endpoint: params.endpoint,
      options: {
        method: 'PUT',
        body: JSON.stringify(params.data),
        headers: {
          'Content-Type': 'application/json',
          ...params.headers,
        },
      },
    }),
  delete: <T>(params: { endpoint: string; data?: unknown; headers?: Record<string, string> }) =>
    fetchApi<T>({
      endpoint: params.endpoint,
      options: {
        method: 'DELETE',
        body: params.data ? JSON.stringify(params.data) : undefined,
        headers: {
          'Content-Type': 'application/json',
          ...params.headers,
        },
      },
    }),
};
