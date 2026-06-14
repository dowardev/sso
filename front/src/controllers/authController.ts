import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';
import { dpopService } from '@/services/dpopService';
import type { User } from '@/types';

interface OauthParams {
  client_id: string;
  redirect_uri: string;
  state: string;
  response_type: string;
  code_challenge?: string;
  code_challenge_method?: string;
  code_verifier?: string;
}

interface OAuthAuthorizeResponse {
  code?: string;
  state?: string | null;
  authorization_required?: boolean;
  client?: {
    id: number;
    name: string;
    client_id: string;
  };
  user?: {
    name: string;
    email: string;
    user: string;
    roles: string[];
  };
}

interface RegisterParams {
  name: string;
  user: string;
  email: string;
  password: string;
  password_confirmation: string;
  client_id?: string;
}

export function useAuthController() {
  const authStore = useAuthStore();

  const token = computed(() => authStore.token);
  const user = computed(() => authStore.user);
  const isAuthenticated = computed(() => authStore.isAuthenticated);

  async function login(params: {
    user: string;
    password: string;
    client_id?: string;
    rememberMe?: boolean;
  }): Promise<{ token: string; user: User }> {
    return authStore.login(params);
  }

  async function logout(): Promise<void> {
    return authStore.logout();
  }

  async function getUser(): Promise<User> {
    return authStore.getUser();
  }

  function init(): void {
    authStore.init();
  }

  async function oauthAuthorize(params: OauthParams): Promise<OAuthAuthorizeResponse> {
    const tokenValue = authStore.getToken();
    if (!tokenValue) {
      throw new Error('Token no disponible');
    }

    const ssoUrl = import.meta.env.VITE_API_URL || 'http://localhost:8001/api';

    await dpopService.initialize();
    const dpopProof = await dpopService.createDpopProof({
      htm: 'POST',
      htu: `${ssoUrl}/oauth/authorize`,
      accessToken: tokenValue,
    });
    const publicKeyJwk = dpopService.getPublicKeyJwk();

    const requestData: Record<string, unknown> = {
      client_id: params.client_id,
      redirect_uri: params.redirect_uri,
      state: params.state,
    };

    if (params.code_challenge) {
      requestData.code_challenge = params.code_challenge;
      requestData.code_challenge_method =
        params.code_challenge_method || 'S256';
    }

    if (params.code_verifier) {
      requestData.code_verifier = params.code_verifier;
    }

    const extraHeaders: Record<string, string> = {
      DPoP: dpopProof,
    };

    if (publicKeyJwk) {
      extraHeaders['DPoP-Public-Key'] = JSON.stringify(publicKeyJwk);
    }

    const response = await api.post<OAuthAuthorizeResponse>({
      endpoint: '/oauth/authorize',
      data: requestData,
      extraHeaders,
    });

    return response;
  }

  async function approveOAuthAuthorization(params: OauthParams): Promise<void> {
    const tokenValue = authStore.getToken();
    if (!tokenValue) {
      throw new Error('Token no disponible');
    }

    const ssoUrl = import.meta.env.VITE_API_URL || 'http://localhost:8001/api';

    await dpopService.initialize();
    const dpopProof = await dpopService.createDpopProof({
      htm: 'POST',
      htu: `${ssoUrl}/oauth/authorize/approve`,
      accessToken: tokenValue,
    });
    const publicKeyJwk = dpopService.getPublicKeyJwk();

    const requestData: Record<string, unknown> = {
      client_id: params.client_id,
      redirect_uri: params.redirect_uri,
      state: params.state,
    };

    if (params.code_challenge) {
      requestData.code_challenge = params.code_challenge;
      requestData.code_challenge_method =
        params.code_challenge_method || 'S256';
    }

    if (params.code_verifier) {
      requestData.code_verifier = params.code_verifier;
    }

    const extraHeaders: Record<string, string> = {
      DPoP: dpopProof,
    };

    if (publicKeyJwk) {
      extraHeaders['DPoP-Public-Key'] = JSON.stringify(publicKeyJwk);
    }

    const response = await api.post<{ code: string; state: string | null }>({
      endpoint: '/oauth/authorize/approve',
      data: requestData,
      extraHeaders,
    });

    authStore.addAuthorizedClient(params.client_id);

    const urlParams = new URLSearchParams();
    urlParams.set('code', response.code);

    if (response.state) {
      urlParams.set('state', response.state);
    }

    window.location.href = `${params.redirect_uri}?${urlParams.toString()}`;
  }

  async function denyOAuthAuthorization(params: {
    client_id: string;
  }): Promise<void> {
    await api.post({
      endpoint: '/oauth/authorize/deny',
      data: { client_id: params.client_id },
    });
  }

  async function revokeOAuthAuthorization(params: {
    client_id: string;
  }): Promise<void> {
    await api.post({
      endpoint: '/oauth/authorize/revoke',
      data: { client_id: params.client_id },
    });

    authStore.removeAuthorizedClient(params.client_id);
  }

  async function getUserAuthorizations(params: { user_id: number }): Promise<{ data: unknown[] }> {
    return api.get<{ data: unknown[] }>({
      endpoint: `/oauth/authorizations?user_id=${params.user_id}`,
    });
  }

  async function getClientAuthorizations(params: { client_id: number }): Promise<{ data: unknown[] }> {
    return api.get<{ data: unknown[] }>({
      endpoint: `/admin/clients/${params.client_id}/authorizations`,
    });
  }

  async function redirectOAuthCode(params: {
    code: string;
    state: string | null;
    redirect_uri: string;
  }): Promise<void> {
    const urlParams = new URLSearchParams();
    urlParams.set('code', params.code);

    if (params.state) {
      urlParams.set('state', params.state);
    }

    window.location.href = `${params.redirect_uri}?${urlParams.toString()}`;
  }

  async function checkSession(): Promise<boolean> {
    try {
      const result = await api.get<{ authenticated: boolean }>({
        endpoint: '/session/check',
      });
      return result.authenticated;
    } catch {
      return false;
    }
  }

  async function register(params: RegisterParams): Promise<void> {
    const requestData: Record<string, unknown> = {
      name: params.name,
      user: params.user,
      email: params.email,
      password: params.password,
      password_confirmation: params.password_confirmation,
    };

    if (params.client_id) {
      requestData.client_id = params.client_id;
    }

    await api.post({
      endpoint: '/register',
      data: requestData,
    });

    const isOAuthFlow = !!params.client_id;

    if (isOAuthFlow) {
      await login({
        user: params.email,
        password: params.password,
        client_id: params.client_id,
      });

      const oauthParams: OauthParams = {
        client_id: params.client_id || '',
        redirect_uri: '',
        state: '',
        response_type: 'code',
      };

      await oauthAuthorize(oauthParams);
    }
  }

  return {
    token,
    user,
    isAuthenticated,
    login,
    logout,
    getUser,
    init,
    oauthAuthorize,
    approveOAuthAuthorization,
    denyOAuthAuthorization,
    revokeOAuthAuthorization,
    getUserAuthorizations,
    getClientAuthorizations,
    redirectOAuthCode,
    register,
    checkSession,
  };
}
