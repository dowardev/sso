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

const getCookie = (name: string): string | null => {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
  return match ? decodeURIComponent(match[2]) : null
}

const getHeaders = () => {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };

  const token = localStorage.getItem(TOKEN_KEY);
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const xsrfToken = getCookie('XSRF-TOKEN')
  if (xsrfToken) {
    headers['X-XSRF-TOKEN'] = xsrfToken
  }

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
      const apiError = new Error(error.message || 'Error en la solicitud') as Error & {
        code?: string;
        status?: number;
        email?: string;
      };
      if (error.code) apiError.code = error.code;
      if (error.email) apiError.email = error.email;
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
