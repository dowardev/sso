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

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

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

async function fetchApi<T>(
  endpoint: string,
  options: RequestInit = {}
): Promise<T> {
  const response = await fetch(`${API_URL}${endpoint}`, {
    ...options,
    headers: {
      ...getHeaders(),
      ...options.headers,
    },
  });

  if (!response.ok) {
    const error = await response
      .json()
      .catch(() => ({ message: 'Error de conexión' }));
    throw new Error(error.message || error.message || 'Error en la solicitud');
  }

  return response.json();
}

export const api = {
  get: <T>(endpoint: string, headers?: Record<string, string>) =>
    fetchApi<T>(endpoint, { headers }),
  post: <T>(
    endpoint: string,
    data?: unknown,
    extraHeaders?: Record<string, string>
  ) =>
    fetchApi<T>(endpoint, {
      method: 'POST',
      body: data ? JSON.stringify(data) : undefined,
      headers: extraHeaders,
    }),
  put: <T>(endpoint: string, data: unknown, headers?: Record<string, string>) =>
    fetchApi<T>(endpoint, {
      method: 'PUT',
      body: JSON.stringify(data),
      headers,
    }),
  delete: <T>(endpoint: string, headers?: Record<string, string>) =>
    fetchApi<T>(endpoint, {
      method: 'DELETE',
      headers,
    }),
};
