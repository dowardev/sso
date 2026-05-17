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
  allowed_origins: string[] | null;
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
}

export interface RoleAssignment {
  role_id: number | null;
  name?: string;
  slug?: string;
}

export interface Pagination {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
}
