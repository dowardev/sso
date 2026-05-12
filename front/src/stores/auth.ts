import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/services/api';
import type { User } from '@/types';
import { dpopService } from '@/services/dpopService';

const TOKEN_KEY = 'token';
const USER_KEY = 'user';
const DPOP_PUBLIC_KEY = 'dpop_public_key';

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY));
  const user = ref<User | null>(null);

  const isAuthenticated = computed(() => !!token.value);

  async function login(params: { user: string; password: string; client_id?: string }) {
    await dpopService.initialize();

    const dpopPublicKey = dpopService.getPublicKeyJwk();

    const loginData: Record<string, unknown> = {
      user: params.user,
      password: params.password,
    };

    if (params.client_id) {
      loginData.client_id = params.client_id;
    }

    if (dpopPublicKey) {
      loginData.dpop_public_key = JSON.stringify(dpopPublicKey);
    }

    const response = await api.post<{ token: string; user: User }>({
      endpoint: '/login',
      data: loginData,
    });

    localStorage.setItem(TOKEN_KEY, response.token);
    localStorage.setItem(USER_KEY, JSON.stringify(response.user));
    localStorage.setItem('sso_token', response.token);

    if (dpopPublicKey) {
      localStorage.setItem(DPOP_PUBLIC_KEY, JSON.stringify(dpopPublicKey));
    }

    token.value = response.token;
    user.value = response.user;

    return response;
  }

  async function logout() {
    try {
      await api.post({ endpoint: '/logout' });
    } catch (error) {
      console.error('Logout error:', error);
    }

    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem('sso_token');
    localStorage.removeItem(DPOP_PUBLIC_KEY);

    await dpopService.clearKeys();

    token.value = null;
    user.value = null;
  }

  async function getUser() {
    try {
      const response = await api.get<User>({ endpoint: '/me' });
      user.value = response;
      return response;
    } catch (error) {
      await logout();
      throw error;
    }
  }

  function getToken(): string | null {
    return token.value || localStorage.getItem(TOKEN_KEY);
  }

  function getDpopPublicKey(): JsonWebKey | null {
    const stored = localStorage.getItem(DPOP_PUBLIC_KEY);
    if (stored) {
      try {
        return JSON.parse(stored);
      } catch {
        return null;
      }
    }
    return null;
  }

  function init() {
    const storedToken = localStorage.getItem(TOKEN_KEY);
    const userStr = localStorage.getItem(USER_KEY);

    if (storedToken && userStr) {
      token.value = storedToken;
      user.value = JSON.parse(userStr);
    }
  }

  init();

  return {
    token,
    user,
    isAuthenticated,
    login,
    logout,
    getUser,
    getToken,
    getDpopPublicKey,
    init,
  };
});