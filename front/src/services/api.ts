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

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8001/api';

const getHeaders = () => {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };

  const token = localStorage.getItem(TOKEN_KEY);
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
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
  const doFetch = (retry = false) =>
    fetch(`${baseUrl}/${normalizedEndpoint}`, {
      ...options,
      headers: {
        ...(retry ? getHeaders() : {}),
        ...options.headers,
      },
      credentials: 'include',
    });

  let response = await doFetch();

  if (response.status === 401 && endpoint !== '/login' && endpoint !== '/refresh-token') {
    const refreshed = await refreshToken();
    if (refreshed) {
      response = await doFetch(true);
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
    throw new Error(error.message || 'Error en la solicitud');
  }

  return response.json();
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
        headers: params.extraHeaders,
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
        headers: params.headers,
      },
    }),
  delete: <T>(params: { endpoint: string; data?: unknown; headers?: Record<string, string> }) =>
    fetchApi<T>({
      endpoint: params.endpoint,
      options: {
        method: 'DELETE',
        body: params.data ? JSON.stringify(params.data) : undefined,
        headers: params.headers,
      },
    }),
};
