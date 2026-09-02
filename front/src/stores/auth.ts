// SPDX-License-Identifier: MIT
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/services/api';
import { cryptoService } from '@/services/cryptoService';
import type { User, AdvancedAuthState, CryptoUserProfile } from '@/types';
import { dpopService } from '@/services/dpopService';

const TOKEN_KEY = 'token';
const USER_KEY = 'user';
const DPOP_PUBLIC_KEY = 'dpop_public_key';
const REMEMBER_KEY = 'remember_me';
const AUTHORIZED_CLIENTS_KEY = 'authorized_clients';

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

  // ─── Crypto State (In-Memory Only - Tab Scoped) ────────────────────────────

  const cryptoState = ref<AdvancedAuthState>({
    dek: null,
    keyA: null,
    isAdvanced: false,
    loginId: null,
  });

  // Decrypted client-side profile (ZKE). Only available for the current user,
  // since the server never stores PII for advanced users.
  const profile = ref<CryptoUserProfile | null>(null);

  function isTokenExpired(params: { token: string }): boolean {
    try {
      const payload = JSON.parse(atob(params.token.split('.')[1]));
      return payload.exp * 1000 < Date.now();
    } catch {
      return true;
    }
  }

  const isAuthenticated = computed(() => !!token.value && !isTokenExpired({ token: token.value }));

  const isAdvancedUser = computed(() => cryptoState.value.isAdvanced);

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
    clearCryptoState();
  }

  function clearCryptoState(): void {
    cryptoService.clearKeys();
    cryptoState.value = {
      dek: null,
      keyA: null,
      isAdvanced: false,
      loginId: null,
    };
  }

  function setProfile(params: { profile: CryptoUserProfile }): void {
    profile.value = params.profile;
  }

  function addAuthorizedClient(params: { clientId: string }): void {
    const clients = loadAuthorizedClients();
    if (!clients.includes(params.clientId)) {
      clients.push(params.clientId);
      localStorage.setItem(AUTHORIZED_CLIENTS_KEY, JSON.stringify(clients));
      authorizedClients.value = clients;
    }
  }

  function removeAuthorizedClient(params: { clientId: string }): void {
    const clients = loadAuthorizedClients().filter((id) => id !== params.clientId);
    localStorage.setItem(AUTHORIZED_CLIENTS_KEY, JSON.stringify(clients));
    authorizedClients.value = clients;
  }

  function isClientAuthorized(params: { clientId: string }): boolean {
    return authorizedClients.value.includes(params.clientId);
  }

  // ─── Crypto Helpers ────────────────────────────────────────────────────────

  function setDek(params: { dek: CryptoKey }): void {
    cryptoState.value.dek = params.dek;
  }

  function getDek(): CryptoKey | null {
    return cryptoState.value.dek;
  }

  function setKeyA(params: { keyA: CryptoKey }): void {
    cryptoState.value.keyA = params.keyA;
  }

  function getKeyA(): CryptoKey | null {
    return cryptoState.value.keyA;
  }

  function setAdvancedState(params: { isAdvanced: boolean; loginId?: string | null }): void {
    cryptoState.value.isAdvanced = params.isAdvanced;
    cryptoState.value.loginId = params.loginId ?? null;
  }

  async function decryptProfile(params: {
    encryptedProfile: string;
  }): Promise<{ name: string; email: string; user: string } | null> {
    const dek = getDek();
    if (!dek) return null;

    try {
      const blob = JSON.parse(params.encryptedProfile);
      return await cryptoService.decryptProfile({ blob });
    } catch (error) {
      console.error('Failed to decrypt profile:', error);
      return null;
    }
  }

  // ─── Auth Operations ───────────────────────────────────────────────────────

  /**
   * Persist an authenticated session (shared by basic, advanced, and
   * passkey login paths so localStorage handling lives in one place).
   */
  function applyLoginSession(params: {
    token: string;
    user: User;
    dpopPublicKey?: JsonWebKey | null;
    rememberMe?: boolean;
  }) {
    const { token: newToken, user: newUser, dpopPublicKey, rememberMe } = params;

    localStorage.setItem(TOKEN_KEY, newToken);
    localStorage.setItem(USER_KEY, JSON.stringify(newUser));
    localStorage.setItem('sso_token', newToken);

    if (dpopPublicKey) {
      localStorage.setItem(DPOP_PUBLIC_KEY, JSON.stringify(dpopPublicKey));
    }

    token.value = newToken;
    user.value = newUser;

    if (newUser.encryption_level === 'advanced') {
      setAdvancedState({ isAdvanced: true, loginId: newUser.login_id });
    } else {
      clearCryptoState();
    }

    if (rememberMe) {
      localStorage.setItem(REMEMBER_KEY, 'true');
    }
  }

  async function login(params: {
    user: string;
    password: string;
    client_id?: string;
    rememberMe?: boolean;
  }) {
    const { client_id, rememberMe } = params;

    await dpopService.initialize();

    const dpopPublicKey = dpopService.getPublicKeyJwk();

    const loginData: Record<string, unknown> = {
      user: params.user,
      password: params.password,
    };

    if (client_id) {
      loginData.client_id = client_id;
    }

    if (dpopPublicKey) {
      loginData.dpop_public_key = JSON.stringify(dpopPublicKey);
    }

    if (rememberMe) {
      loginData.remember_me = true;
    }

    const response = await api.post<{ token: string; user: User }>({
      endpoint: '/login',
      data: loginData,
    });

    applyLoginSession({
      token: response.token,
      user: response.user,
      dpopPublicKey,
      rememberMe,
    });

    return response;
  }

  async function logout() {
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

      // Update crypto state based on user's encryption level
      if (response.encryption_level === 'advanced') {
        setAdvancedState({ isAdvanced: true, loginId: response.login_id });
      } else {
        clearCryptoState();
      }

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

  async function refreshToken() {
    try {
      const apiUrl = import.meta.env.VITE_API_URL || '/api';
      const res = await fetch(`${apiUrl.replace(/\/+$/, '')}/refresh-token`, {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return false;
      const data = await res.json();
      if (data.token) {
        localStorage.setItem(TOKEN_KEY, data.token);
        token.value = data.token;
        return true;
      }
      return false;
    } catch {
      return false;
    }
  }

  async function restoreAuth() {
    const storedToken = localStorage.getItem(TOKEN_KEY);
    const userStr = localStorage.getItem(USER_KEY);

    if (storedToken) {
      if (isTokenExpired({ token: storedToken })) {
        const refreshed = await refreshToken();
        if (refreshed) {
          const newToken = localStorage.getItem(TOKEN_KEY);
          token.value = newToken;
          return newToken;
        }
        clearAuthData();
        return null;
      }
      token.value = storedToken;
      if (userStr) {
        user.value = JSON.parse(userStr);
        // Restore crypto state
        const restoredUser = user.value;
        if (restoredUser && restoredUser.encryption_level === 'advanced') {
          setAdvancedState({ isAdvanced: true, loginId: restoredUser.login_id });
        }
        // Sessions stored before login endpoints exposed encryption_level lack the
        // marker, so the advanced re-auth path (passkey delete, etc.) would send
        // the plaintext password and fail. Refresh via /me to self-heal.
        if (restoredUser && restoredUser.encryption_level == null) {
          try {
            await getUser();
          } catch {
            // Keep going with the stale user instead of breaking restore.
          }
        }
      }
    }

    return storedToken;
  }

  let _initPromise: Promise<void> | null = null;

  async function init() {
    if (!_initPromise) {
      _initPromise = restoreAuth().then(() => {});
    }
    return _initPromise;
  }

  return {
    token,
    user,
    isAuthenticated,
    isAdvancedUser,
    authorizedClients,
    // Crypto state getters
    cryptoState: computed(() => cryptoState.value),
    getDek,
    getKeyA,
    isAdvanced: () => cryptoState.value.isAdvanced,
    getLoginId: () => cryptoState.value.loginId,
    // Actions
    login,
    applyLoginSession,
    logout,
    getUser,
    getToken,
    getDpopPublicKey,
    init,
    addAuthorizedClient,
    removeAuthorizedClient,
    isClientAuthorized,
    // Crypto actions
    profile,
    setDek,
    setKeyA,
    setAdvancedState,
    setProfile,
    clearCryptoState,
    decryptProfile,
  };
});
