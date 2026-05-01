import { auth } from './auth';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

const getHeaders = () => {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };

  const token = auth.getToken();
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  return headers;
};

async function fetchApi<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(`${API_URL}${endpoint}`, {
    ...options,
    headers: {
      ...getHeaders(),
      ...options.headers,
    },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Error de conexión' }));
    throw new Error(error.message || error.message || 'Error en la solicitud');
  }

  return response.json();
}

export const api = {
  get: <T>(endpoint: string) => fetchApi<T>(endpoint),
  post: <T>(endpoint: string, data?: unknown) => fetchApi<T>(endpoint, {
    method: 'POST',
    body: data ? JSON.stringify(data) : undefined,
  }),
  put: <T>(endpoint: string, data: unknown) => fetchApi<T>(endpoint, {
    method: 'PUT',
    body: JSON.stringify(data),
  }),
  delete: <T>(endpoint: string) => fetchApi<T>(endpoint, {
    method: 'DELETE',
  }),
};

export interface User {
  id: number;
  name: string;
  user: string;
  email: string;
  is_admin: boolean;
  created_at: string;
  sessions_count?: number;
  tokens_count?: number;
}

export interface UserDetail extends User {
  sessions: UserSession[];
  active_tokens_count: number;
}

export interface UserSession {
  id: number;
  user_id: number;
  sso_client_id: number | null;
  token_id: number;
  ip_address: string;
  user_agent: string;
  last_used_at: string;
  created_at: string;
  user?: {
    id: number;
    name: string;
    user: string;
    email: string;
  };
  client?: SsoClient;
  token?: {
    id: number;
    name: string;
    abilities: string[];
    expires_at: string | null;
  };
  token_active?: boolean;
}

export interface SsoClient {
  id: number;
  name: string;
  client_id: string;
  client_secret?: string;
  redirect_url: string | null;
  active: boolean;
  created_at: string;
  sessions_count?: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface DashboardStats {
  total_users: number;
  active_sessions: number;
  total_clients: number;
}

export interface Role {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  is_protected: boolean;
  created_at: string;
  users_count?: number;
}

export interface UserRole {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  sso_client_id: number | null;
  sso_client_name: string | null;
}
