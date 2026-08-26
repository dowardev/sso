// SPDX-License-Identifier: MIT
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';
import { cryptoService } from '@/services/cryptoService';
import { dpopService } from '@/services/dpopService';
import type {
  User,
  CryptoUserProfile,
  AdvancedLoginResponse,
  AdvancedRegistrationPayload,
  RecoveryVerifyResponse,
} from '@/types';

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
  encryption_level?: 'basic' | 'advanced';
}

interface UserProfile {
  id: number;
  name: string;
  user: string;
  email: string;
  is_admin: boolean;
  created_at: string;
  sessions_count: number;
  tokens_count: number;
  roles: Array<{
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_protected: boolean;
  }>;
}

interface UserSession {
  id: number;
  client: {
    id: number;
    name: string;
    client_id: string;
  } | null;
  ip_address: string;
  user_agent: string;
  last_used_at: string;
  created_at: string;
  token_active: boolean;
}

interface ActivityLogEntry {
  id: number;
  action: string;
  description: string;
  created_at: string;
  metadata: Record<string, unknown>;
}

/**
 * Encode a JSON payload as base64 (unicode-safe) for URL fragment delivery.
 */
function jsonToBase64(params: { value: unknown }): string {
  const json = JSON.stringify(params.value);
  const bytes = new TextEncoder().encode(json);
  let binary = '';
  bytes.forEach((b) => {
    binary += String.fromCharCode(b);
  });
  return btoa(binary);
}

/**
 * Build the redirect URL for an OAuth app. If the user is advanced and a
 * freshly decrypted profile is available in memory, it is appended as a
 * `#profile=` fragment so it never reaches server logs.
 */
function buildAppRedirectUrl(params: { redirectUri: string; urlParams: URLSearchParams }): string {
  let url = `${params.redirectUri}?${params.urlParams.toString()}`;
  const profile = cryptoService.getCachedProfile();
  if (profile) {
    url += `#profile=${jsonToBase64({ value: profile })}`;
  }
  return url;
}

function generateRandomSalt(): string {
  const array = new Uint8Array(32);
  crypto.getRandomValues(array);
  return btoa(String.fromCharCode(...array));
}

/**
 * Compute login_id = HMAC(username, "login_id")
 */
async function computeLoginId(params: { username: string }): Promise<string> {
  const { username } = params;
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode('login_id_salt_v1'),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const signature = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(username));
  return btoa(String.fromCharCode(...new Uint8Array(signature)));
}

export function useAuthController() {
  const authStore = useAuthStore();

  const token = computed(() => authStore.token);
  const user = computed(() => authStore.user);
  const isAuthenticated = computed(() => authStore.isAuthenticated);

  // ─── Basic Login ───────────────────────────────────────────────────────────

  async function login(params: {
    user: string;
    password: string;
    client_id?: string;
    rememberMe?: boolean;
  }): Promise<{ token: string; user: User }> {
    return authStore.login(params);
  }

  // ─── Advanced Login (Zero-Knowledge) ───────────────────────────────────────

  /**
   * Fetch the client-side crypto material (salts, wrapped DEK, encrypted
   * profile) for an advanced user. Used by login, recovery and profile
   * refresh flows — decryption always happens locally.
   */
  async function fetchCryptoBlobs(params: { loginId: string }): Promise<AdvancedLoginResponse> {
    return api.post<AdvancedLoginResponse>({
      endpoint: '/crypto/blobs',
      data: { login_id: params.loginId },
    });
  }

  /**
   * Step 2: Send auth_proof (HMAC(Key_A, "auth")) for verification
   */
  async function loginAdvancedSecondStep(params: {
    login_id: string;
    auth_proof: string;
    client_id?: string;
    rememberMe?: boolean;
    dpop_public_key?: string;
  }): Promise<{ token: string; user: User }> {
    await dpopService.initialize();

    // params.dpop_public_key arrives serialized; normalize to a JWK object.
    const dpopPublicKey: JsonWebKey | null = params.dpop_public_key
      ? (JSON.parse(params.dpop_public_key) as JsonWebKey)
      : dpopService.getPublicKeyJwk();

    const requestData: Record<string, unknown> = {
      login_id: params.login_id,
      auth_proof: params.auth_proof,
    };

    if (params.client_id) {
      requestData.client_id = params.client_id;
    }

    if (params.rememberMe) {
      requestData.remember_me = true;
    }

    if (dpopPublicKey) {
      requestData.dpop_public_key = JSON.stringify(dpopPublicKey);
    }

    const response = await api.post<{ token: string; user: User }>({
      endpoint: '/login/advanced/verify',
      data: requestData,
    });

    // Session persistence (localStorage + crypto state) lives in the store.
    authStore.applyLoginSession({
      token: response.token,
      user: response.user,
      dpopPublicKey,
      rememberMe: params.rememberMe,
    });

    return response;
  }

  /**
   * Full advanced login flow: derive Key_A → unwrap DEK → decrypt profile → compute auth_proof → verify
   */
  async function loginAdvanced(params: {
    login_id: string;
    password: string;
    client_id?: string;
    rememberMe?: boolean;
  }): Promise<{ token: string; user: User; profile: CryptoUserProfile }> {
    // Step 1: Get crypto blobs from server
    const blobs = await fetchCryptoBlobs({ loginId: params.login_id });

    // Derive Key_A from password + salt_a
    const keyA = await cryptoService.deriveKeyA({
      password: params.password,
      salt: blobs.salt_a,
    });
    authStore.setKeyA({ keyA });

    // Unwrap DEK using Key_A
    const dek = await cryptoService.unwrapDek({
      wrapped: blobs.wrapped_dek_a,
      unwrappingKey: keyA,
    });
    authStore.setDek({ dek });

    // Decrypt profile
    const profile = await cryptoService.decryptProfile({
      dek,
      blob: blobs.encrypted_profile,
    });

    // Compute auth proof
    const authProof = await cryptoService.computeAuthProof({ keyA });

    // Step 2: Verify with server
    const result = await loginAdvancedSecondStep({
      login_id: params.login_id,
      auth_proof: authProof,
      client_id: params.client_id,
      rememberMe: params.rememberMe,
    });

    return { ...result, profile };
  }

  // ─── Advanced Registration ─────────────────────────────────────────────────

  /**
   * Register with advanced encryption (zero-knowledge)
   */
  async function registerAdvanced(params: {
    name: string;
    user: string;
    email: string;
    password: string;
    password_confirmation: string;
    client_id?: string;
  }): Promise<{ user: User; seed_phrase: string }> {
    // Generate DEK
    const dek = await cryptoService.generateDek();

    // Generate salt_a and salt_r
    const salt_a = generateRandomSalt();
    const salt_r = generateRandomSalt();

    // Derive Key_A from password
    const keyA = await cryptoService.deriveKeyA({
      password: params.password,
      salt: salt_a,
    });
    authStore.setKeyA({ keyA });

    // Derive Key_R from seed phrase (for recovery)
    const seedPhrase = await cryptoService.generateSeedPhrase();
    const keyR = await cryptoService.deriveKeyR({
      seedPhrase,
      salt: salt_r,
    });

    // Wrap DEK with Key_A and Key_R
    const wrapped_dek_a = await cryptoService.wrapDek({ dek, wrappingKey: keyA });
    const wrapped_dek_r = await cryptoService.wrapDek({ dek, wrappingKey: keyR });

    // Encrypt profile
    const profile: CryptoUserProfile = {
      name: params.name,
      email: params.email,
      user: params.user,
    };
    const encrypted_profile = await cryptoService.encryptProfile({ dek, profile });

    // Compute seed verification
    // HMAC(Key_R, "verify") — the backend compares this exact context on recovery.
    const encrypted_seed_verification = await cryptoService.computeHmacProof({
      key: keyR,
      context: 'verify',
    });

    // Compute login_id = HMAC(username) - for privacy
    const loginId = await computeLoginId({ username: params.user });

    // Compute auth_proof for server to hash
    const auth_proof = await cryptoService.computeAuthProof({ keyA });

    // Prepare registration payload
    const registrationData: AdvancedRegistrationPayload = {
      login_id: loginId,
      auth_hash: '', // Server computes from auth_proof
      salt_a,
      salt_r,
      wrapped_dek_a,
      wrapped_dek_r,
      encrypted_profile,
      encrypted_seed_verification,
      encryption_level: 'advanced',
    };

    // Add auth_proof as extra field for server to hash
    const payloadWithProof = {
      ...registrationData,
      auth_proof,
    };

    // Send registration
    const response = await api.post<{ user: User }>({
      endpoint: '/register/advanced',
      data: payloadWithProof,
    });

    // Store crypto state
    authStore.setDek({ dek });
    authStore.setAdvancedState({ isAdvanced: true, loginId: response.user.login_id });

    return { user: response.user, seed_phrase: seedPhrase };
  }

  // ─── Recovery (Phase 2) ─────────────────────────────────────────────────

  /**
   * Recovery - Step 1: Verify seed phrase
   *
   * Client provides login_id + seed_phrase.
   * Client derives Key_R, computes verification = HMAC(Key_R, "verify"),
   * sends to server. Server verifies and returns recovery blobs.
   */
  async function recoveryVerify(params: {
    login_id: string;
    seed_phrase: string;
  }): Promise<RecoveryVerifyResponse> {
    // Step 1: Get salt_r from login endpoint (now includes salt_r)
    const loginBlobs = await fetchCryptoBlobs({ loginId: params.login_id });
    const saltR = loginBlobs.salt_r;
    if (!saltR) {
      throw new Error('salt_r no disponible en respuesta de login');
    }

    // Step 2: Derive Key_R from seed_phrase + salt_r
    const keyR = await cryptoService.deriveKeyR({
      seedPhrase: params.seed_phrase,
      salt: saltR,
    });

    // Step 3: Compute seed_verification = HMAC(Key_R, "verify")
    const seedVerification = await cryptoService.computeHmacProof({ key: keyR, context: 'verify' });

    // Step 4: Call backend /recovery/verify with login_id + seed_verification
    const response = await api.post<RecoveryVerifyResponse>({
      endpoint: '/recovery/verify',
      data: {
        login_id: params.login_id,
        seed_verification: seedVerification,
      },
    });

    // Step 5: Verify the returned blobs match what we expect
    if (!response.salt_r || !response.wrapped_dek_r || !response.encrypted_profile) {
      throw new Error('Respuesta de recovery/verify incompleta');
    }

    // Step 6: Unwrap DEK with Key_R for use in recoveryComplete
    const dek = await cryptoService.unwrapDek({
      wrapped: response.wrapped_dek_r,
      unwrappingKey: keyR,
    });

    // Store DEK in auth store for recoveryComplete
    authStore.setDek({ dek });

    return response;
  }

  /**
   * Recovery - Step 2: Complete recovery with new password
   *
   * Client has verified seed phrase, has DEK unwrapped with Key_R.
   * Generates new Key_A from new password, wraps DEK, re-encrypts profile.
   */
  async function recoveryComplete(params: {
    login_id: string;
    seed_phrase: string;
    new_password: string;
  }): Promise<{ token: string; user: User; profile: CryptoUserProfile }> {
    // Step 1: Verify seed phrase and get recovery blobs + DEK
    await recoveryVerify({
      login_id: params.login_id,
      seed_phrase: params.seed_phrase,
    });

    // DEK is now in auth store (set by recoveryVerify)
    const dek = authStore.getDek();
    if (!dek) {
      throw new Error('DEK no disponible después de recoveryVerify');
    }

    // Step 2: Generate new salt_a
    const newSaltA = generateRandomSalt();

    // Step 3: Derive new Key_A from new_password + new salt_a
    const newKeyA = await cryptoService.deriveKeyA({
      password: params.new_password,
      salt: newSaltA,
    });

    // Step 4: Wrap DEK with new Key_A
    const newWrappedDekA = await cryptoService.wrapDek({ dek, wrappingKey: newKeyA });

    // Step 5: Re-encrypt profile with DEK (same plaintext, same DEK).
    // recoveryVerify already decrypted it; use the in-memory cache.
    const profile = cryptoService.getCachedProfile();
    if (!profile) {
      throw new Error('Perfil descifrado no disponible en memoria');
    }
    const newEncryptedProfile = await cryptoService.encryptProfile({ dek, profile });

    // Step 6: re-wrap DEK with Key_R — the seed phrase did not rotate,
    // but wrapped_dek_r must be regenerated for the new DEK material.
    const keyR = await cryptoService.deriveKeyR({
      seedPhrase: params.seed_phrase,
      salt: (authStore.user as User & { salt_r?: string }).salt_r || '',
    });
    const wrappedDekR = await cryptoService.wrapDek({ dek, wrappingKey: keyR });

    // Step 7: Compute new auth_proof = HMAC(new_Key_A, "auth")
    const newAuthProof = await cryptoService.computeAuthProof({ keyA: newKeyA });

    // Step 8: POST /recovery/complete
    await api.post({
      endpoint: '/recovery/complete',
      data: {
        login_id: params.login_id,
        auth_proof: newAuthProof,
        salt_a: newSaltA,
        wrapped_dek_a: newWrappedDekA,
        wrapped_dek_r: wrappedDekR,
        encrypted_profile: newEncryptedProfile,
      },
    });

    // Step 9: Login with new password using advanced flow
    return loginAdvanced({
      login_id: params.login_id,
      password: params.new_password,
    });
  }

  /**
   * Change password for advanced user (same DEK, new Key_A)
   *
   * Different from recovery: user knows current password, keeps same DEK.
   */
  async function changePasswordAdvanced(params: {
    current_password: string;
    new_password: string;
    new_password_confirmation: string;
  }): Promise<void> {
    const loginId = authStore.getLoginId();
    if (!loginId || !authStore.isAdvanced()) {
      throw new Error('Usuario no tiene cifrado avanzado');
    }

    if (params.new_password !== params.new_password_confirmation) {
      throw new Error('Las contraseñas no coinciden');
    }

    const dek = authStore.getDek();
    if (!dek) {
      throw new Error('DEK no disponible en memoria');
    }

    // NOTE: current-password verification is delegated to the authenticated
    // session; the server rejects an invalid auth_proof rotation.

    // Generate new salt_a
    const newSaltA = generateRandomSalt();

    // Derive new Key_A
    const newKeyA = await cryptoService.deriveKeyA({
      password: params.new_password,
      salt: newSaltA,
    });

    // Wrap DEK with new Key_A
    const newWrappedDekA = await cryptoService.wrapDek({ dek, wrappingKey: newKeyA });

    // Compute new auth_proof
    const newAuthProof = await cryptoService.computeAuthProof({ keyA: newKeyA });

    // Send to backend
    await api.post({
      endpoint: '/password/change-advanced',
      data: {
        login_id: loginId,
        auth_proof: newAuthProof,
        salt_a: newSaltA,
        wrapped_dek_a: newWrappedDekA,
      },
    });

    // Update local state
    authStore.setKeyA({ keyA: newKeyA });
    // DEK stays the same
  }

  // ─── Basic Registration ────────────────────────────────────────────────────

  // ─── Basic → Advanced Migration (Phase 4) ─────────────────────────────────

  /**
   * Upgrade basic user to advanced encryption (zero-knowledge).
   *
   * Generates all crypto material client-side; the seed phrase never leaves
   * this device. Returns it so the UI can display it once.
   */
  async function upgradeToAdvanced(params: {
    currentPassword: string;
  }): Promise<{ user: User; seedPhrase: string }> {
    const { currentPassword } = params;

    const currentUser = authStore.user;
    if (!currentUser) {
      throw new Error('No hay usuario autenticado');
    }

    if ((currentUser as User & { encryption_level?: string }).encryption_level === 'advanced') {
      throw new Error('El usuario ya tiene cifrado avanzado');
    }

    // Current profile in plaintext (basic users have it stored server-side)
    const profile: CryptoUserProfile = {
      name: currentUser.name || '',
      email: currentUser.email || '',
      user: currentUser.user || '',
    };

    if (!profile.name || !profile.email || !profile.user) {
      throw new Error('Perfil incompleto para migrar a cifrado avanzado');
    }

    const dek = await cryptoService.generateDek();
    const saltA = generateRandomSalt();
    const saltR = generateRandomSalt();

    const keyA = await cryptoService.deriveKeyA({
      password: currentPassword,
      salt: saltA,
    });
    authStore.setKeyA({ keyA });

    const seedPhrase = await cryptoService.generateSeedPhrase();
    const keyR = await cryptoService.deriveKeyR({ seedPhrase, salt: saltR });

    const wrappedDekA = await cryptoService.wrapDek({ dek, wrappingKey: keyA });
    const wrappedDekR = await cryptoService.wrapDek({ dek, wrappingKey: keyR });
    const encryptedProfile = await cryptoService.encryptProfile({ dek, profile });

    // HMAC(Key_R, "verify") — proves knowledge of the seed without revealing it.
    const encryptedSeedVerification = await cryptoService.computeHmacProof({
      key: keyR,
      context: 'verify',
    });

    const loginId = await computeLoginId({ username: profile.user });

    // HMAC(Key_A, "auth") — server stores Argon2id(auth_proof).
    const authProof = await cryptoService.computeAuthProof({ keyA });

    const response = await api.post<{ message: string; user: User }>({
      endpoint: '/user/upgrade',
      data: {
        current_password: currentPassword,
        auth_proof: authProof,
        login_id: loginId,
        salt_a: saltA,
        salt_r: saltR,
        wrapped_dek_a: wrappedDekA,
        wrapped_dek_r: wrappedDekR,
        encrypted_profile: encryptedProfile,
        encrypted_seed_verification: encryptedSeedVerification,
      },
    });

    authStore.setDek({ dek });
    authStore.setAdvancedState({ isAdvanced: true, loginId });

    return { user: response.user, seedPhrase };
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

    // Basic registration
    if (params.encryption_level === 'advanced') {
      await registerAdvanced(params);
    } else {
      await api.post({
        endpoint: '/register',
        data: requestData,
      });
    }
  }

  // ─── Logout ────────────────────────────────────────────────────────────────
  async function logout(): Promise<void> {
    return authStore.logout();
  }

  // ─── User ──────────────────────────────────────────────────────────────────
  async function getUser(): Promise<User> {
    return authStore.getUser();
  }

  function init(): void {
    authStore.init();
  }

  // ─── OAuth ─────────────────────────────────────────────────────────────────
  async function oauthAuthorize(params: OauthParams): Promise<OAuthAuthorizeResponse> {
    const tokenValue = authStore.getToken();
    if (!tokenValue) {
      throw new Error('Token no disponible');
    }

    const ssoUrl = import.meta.env.VITE_API_URL;

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

    const ssoUrl = import.meta.env.VITE_API_URL;

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

    authStore.addAuthorizedClient({ clientId: params.client_id });

    const urlParams = new URLSearchParams();
    urlParams.set('code', response.code);

    if (response.state) {
      urlParams.set('state', response.state);
    }

    window.location.href = buildAppRedirectUrl({
      redirectUri: params.redirect_uri,
      urlParams,
    });
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

    authStore.removeAuthorizedClient({ clientId: params.client_id });
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

    window.location.href = buildAppRedirectUrl({
      redirectUri: params.redirect_uri,
      urlParams,
    });
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

  // ─── Profile & Account ─────────────────────────────────────────────────────
  async function getUserProfile(): Promise<UserProfile> {
    return api.get<UserProfile>({
      endpoint: '/user/profile',
    });
  }

  async function updateUserProfile(params: {
    name: string;
    user: string;
    email: string;
  }): Promise<UserProfile> {
    return api.put<UserProfile>({
      endpoint: '/user/profile',
      data: params,
    });
  }

  async function updateUserPassword(params: {
    current_password: string;
    new_password: string;
    new_password_confirmation: string;
  }): Promise<void> {
    await api.put({
      endpoint: '/user/password',
      data: params,
    });
  }

  async function getUserSessions(): Promise<UserSession[]> {
    return api.get<UserSession[]>({
      endpoint: '/user/sessions',
    });
  }

  async function revokeUserSession(params: { sessionId: number }): Promise<void> {
    await api.delete({
      endpoint: `/user/sessions/${params.sessionId}`,
    });
  }

  async function revokeAllUserSessions(): Promise<void> {
    await api.delete({
      endpoint: '/user/sessions',
    });
  }

  interface ActivityLogResponse {
    data: ActivityLogEntry[];
    current_page: number;
    last_page: number;
    total: number;
  }

  async function deleteAccount(params: {
    password: string;
    confirmation: string;
  }): Promise<void> {
    await api.delete({
      endpoint: '/user/account',
      data: params,
    });
  }

  async function getUserActivityLog(): Promise<ActivityLogEntry[]> {
    const response = await api.get<ActivityLogResponse>({
      endpoint: '/user/activity-log',
    });
    return response.data ?? [];
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
    getUserProfile,
    updateUserProfile,
    updateUserPassword,
    getUserSessions,
    revokeUserSession,
    revokeAllUserSessions,
    getUserActivityLog,
    deleteAccount,
    // Advanced encryption methods
    loginAdvanced,
    fetchCryptoBlobs,
    loginAdvancedSecondStep,
    registerAdvanced,
    computeLoginId,
    // Recovery methods (Phase 2)
    recoveryVerify,
    recoveryComplete,
    changePasswordAdvanced,
    // Migration methods (Phase 4)
    upgradeToAdvanced,
  };
}