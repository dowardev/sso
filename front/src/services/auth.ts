import { ref } from 'vue';
import { api } from './api';
import type { User } from './api';
import { dpopService } from './dpopService';

const TOKEN_KEY = 'token';
const USER_KEY = 'user';
const DPOP_PUBLIC_KEY = 'dpop_public_key';

export const isAuthenticated = ref<boolean>(!!localStorage.getItem(TOKEN_KEY));
export const currentUser = ref<User | null>(null);

export const auth = {
  async login(credentials: { user: string; password: string; client_id?: string }) {
    await dpopService.initialize();
    
    const dpopPublicKey = dpopService.getPublicKeyJwk();
    
    const loginData: Record<string, unknown> = {
      user: credentials.user,
      password: credentials.password,
    };
    
    if (credentials.client_id) {
      loginData.client_id = credentials.client_id;
    }
    
    if (dpopPublicKey) {
      loginData.dpop_public_key = JSON.stringify(dpopPublicKey);
    }
    
    const response = await api.post<{ token: string; user: User }>('/login', loginData);
    
    localStorage.setItem(TOKEN_KEY, response.token);
    localStorage.setItem(USER_KEY, JSON.stringify(response.user));
    localStorage.setItem('sso_token', response.token);
    
    if (dpopPublicKey) {
      localStorage.setItem(DPOP_PUBLIC_KEY, JSON.stringify(dpopPublicKey));
    }
    
    isAuthenticated.value = true;
    currentUser.value = response.user;
    
    return response;
  },

  async logout() {
    try {
      await api.post('/logout');
    } catch (error) {
      console.error('Logout error:', error);
    }
    
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    
    isAuthenticated.value = false;
    currentUser.value = null;
  },

  async getUser() {
    try {
      const response = await api.get<User>('/me');
      currentUser.value = response;
      return response;
    } catch (error) {
      this.logout();
      throw error;
    }
  },

  getToken(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  },

  getDpopPublicKey(): JsonWebKey | null {
    const stored = localStorage.getItem(DPOP_PUBLIC_KEY);
    if (stored) {
      try {
        return JSON.parse(stored);
      } catch {
        return null;
      }
    }
    return null;
  },

  init() {
    const token = localStorage.getItem(TOKEN_KEY);
    const userStr = localStorage.getItem(USER_KEY);
    
    if (token && userStr) {
      isAuthenticated.value = true;
      currentUser.value = JSON.parse(userStr);
    }
  }
};

auth.init();
