// SPDX-License-Identifier: MIT
export interface Role {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  is_protected: boolean;
  pivot?: {
    sso_client_id: number | null;
  };
}

export interface User {
  id?: string;
  name?: string;
  user?: string;
  password?: string;
  email?: string;
  roles?: Role[];
  isEmailVerified?: boolean;
  isDisabled?: boolean;
  imageProfile?: string;
}

export interface UserServerResponse {
  created_at: string;
  email: string;
  email_verified_at: string | null;
  id: number;
  image_id: string | null;
  is_admin: boolean;
  is_email_verified: boolean;
  is_user_disabled: boolean;
  name: string;
  role: string | null;
  updated_at: string;
  user: string;
  roles?: Role[];
}

export interface ICustomError {
  status?: number;
  errorDetail?: string;
  cause?: Error;
}

export interface SSOConfig {
  ssoUrl: string;
  ssoFrontendUrl: string;
  apiBaseUrl: string;
  clientId: string;
  onAuthSuccess?: (user: User) => void;
  onAuthError?: (error: ICustomError) => void;
}

export interface AuthState {
  isAuthenticated: boolean;
  user: User | null;
}

export interface AuthResponse {
  token: string;
  refreshToken?: string;
  user?: User;
  rememberMe?: boolean;
}

export interface RegisterParams {
  name: string;
  user: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export interface LoginParams {
  email: string;
  password: string;
  rememberMe?: boolean;
}

export interface RefreshTokenParams {
  refreshToken: string;
}

export interface RecoverPasswordParams {
  email: string;
}

export interface ResetPasswordParams {
  email: string;
  code: number | string;
  password: string;
}
