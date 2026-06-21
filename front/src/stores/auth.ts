import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/services/api';
import type { User } from '@/types';
import { dpopService } from '@/services/dpopService';

const TOKEN_KEY = 'token';
const USER_KEY = 'user';
const DPOP_PUBLIC_KEY = 'dpop_public_key';
const REMEMBER_KEY = 'remember_me';
const AUTHORIZED_CLIENTS_KEY = 'authorized_clients';
const AUTO_REFRESH_INTERVAL = 3000000; // 50 min (5/6 of 3600s)
const REFRESH_THRESHOLD = 600; // 10 min threshold to refresh on init

function loadAuthorizedClients(): string[] {
  try {
    const stored = localStorage.getItem(AUTHORIZED_CLIENTS_KEY);
    return stored ? JSON.parse(stored) : [];
  } catch {
    return [];
  }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY));
  const user = ref<User | null>(null);
  const authorizedClients = ref<string[]>(loadAuthorizedClients());
  let autoRefreshTimer: ReturnType<typeof setInterval> | null = null;

  function isTokenExpired(token: string): boolean {
    try {
      const payload = JSON.parse(atob(token.split('.')[1]));
      return payload.exp * 1000 < Date.now();
    } catch {
      return true;
    }
  }

  const isAuthenticated = computed(() => !!token.value && !isTokenExpired(token.value));

  function clearAuthData() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem('sso_token');
    localStorage.removeItem(DPOP_PUBLIC_KEY);
    localStorage.removeItem(REMEMBER_KEY);
    localStorage.removeItem(AUTHORIZED_CLIENTS_KEY);
    token.value = null;
    user.value = null;
    authorizedClients.value = [];
  }

  function addAuthorizedClient(clientId: string): void {
    const clients = loadAuthorizedClients();
    if (!clients.includes(clientId)) {
      clients.push(clientId);
      localStorage.setItem(AUTHORIZED_CLIENTS_KEY, JSON.stringify(clients));
      authorizedClients.value = clients;
    }
  }

  function removeAuthorizedClient(clientId: string): void {
    const clients = loadAuthorizedClients().filter((id) => id !== clientId);
    localStorage.setItem(AUTHORIZED_CLIENTS_KEY, JSON.stringify(clients));
    authorizedClients.value = clients;
  }

  function isClientAuthorized(clientId: string): boolean {
    return authorizedClients.value.includes(clientId);
  }

  async function login(params: {
    user: string;
    password: string;
    client_id?: string;
    rememberMe?: boolean;
  }) {
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

    if (params.rememberMe) {
      loginData.remember_me = true;
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

    if (params.rememberMe) {
      localStorage.setItem(REMEMBER_KEY, 'true');
      startAutoRefresh();
    }

    return response;
  }

  async function logout() {
    stopAutoRefresh();
    localStorage.removeItem(REMEMBER_KEY);
    clearAuthData();
    await dpopService.clearKeys();
    try {
      await api.post({ endpoint: '/logout' });
    } catch (error) {
      console.error('Logout error:', error);
    }
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

  function startAutoRefresh(): void {
    stopAutoRefresh();
    autoRefreshTimer = setInterval(async () => {
      const currentToken = token.value || localStorage.getItem(TOKEN_KEY);
      if (!currentToken) {
        stopAutoRefresh();
        return;
      }
      try {
        const response = await api.post<{ token: string }>({
          endpoint: '/refresh-token',
          data: { token: currentToken },
        });
        localStorage.setItem(TOKEN_KEY, response.token);
        localStorage.setItem('sso_token', response.token);
        token.value = response.token;
      } catch {
        stopAutoRefresh();
        clearAuthData();
      }
    }, AUTO_REFRESH_INTERVAL);
  }

  function stopAutoRefresh(): void {
    if (autoRefreshTimer !== null) {
      clearInterval(autoRefreshTimer);
      autoRefreshTimer = null;
    }
  }

  function restoreAuth() {
    const storedToken = localStorage.getItem(TOKEN_KEY);
    const userStr = localStorage.getItem(USER_KEY);

    if (storedToken && userStr) {
      if (isTokenExpired(storedToken)) {
        clearAuthData();
        return null;
      }
      token.value = storedToken;
      user.value = JSON.parse(userStr);
    }

    return storedToken;
  }

  async function refreshTokenAsync(currentToken: string) {
    try {
      const response = await api.post<{ token: string }>({
        endpoint: '/refresh-token',
        data: { token: currentToken },
      });
      localStorage.setItem(TOKEN_KEY, response.token);
      localStorage.setItem('sso_token', response.token);
      token.value = response.token;
    } catch {
      clearAuthData();
    }
  }

  function init() {
    const storedToken = restoreAuth();
    if (!storedToken) return;

    const rememberMe = localStorage.getItem(REMEMBER_KEY) === 'true';
    if (!rememberMe) return;

    if (isTokenNearExpiry(storedToken, REFRESH_THRESHOLD)) {
      refreshTokenAsync(storedToken);
    }

    startAutoRefresh();
  }

  init();

  function isTokenNearExpiry(token: string, thresholdSeconds: number): boolean {
    try {
      const payload = JSON.parse(atob(token.split('.')[1]));
      return (payload.exp * 1000 - Date.now()) < thresholdSeconds * 1000;
    } catch {
      return false;
    }
  }

  return {
    token,
    user,
    isAuthenticated,
    authorizedClients,
    login,
    logout,
    getUser,
    getToken,
    getDpopPublicKey,
    init,
    addAuthorizedClient,
    removeAuthorizedClient,
    isClientAuthorized,
  };
});
