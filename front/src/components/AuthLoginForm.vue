<!-- SPDX-License-Identifier: MIT -->
<template>
  <AuthCard title="Iniciar Sesión" subtitle="Accede a tu cuenta de SSO Server">
    <!-- Step 1: identifier -->
    <form v-if="step === 'identifier'" @submit.prevent="handleContinue()">
      <ion-item class="input-item" lines="none">
        <ion-input
          v-model="credentials.identifier"
          label-placement="floating"
          label="Usuario"
          type="text"
          placeholder="Ingresa tu usuario"
          autocomplete="username"
          aria-describedby="login-identifier-error"
          @ion-blur="v$.identifier.$touch()"
        />
      </ion-item>
      <div v-if="v$.identifier.$error" id="login-identifier-error" class="field-error">
        {{ v$.identifier.$errors[0].$message }}
      </div>

      <div v-if="error" class="error-message" role="alert" aria-live="assertive">
        <ion-icon :icon="alertCircle" size="small"></ion-icon>
        {{ error }}
      </div>

      <div class="ion-padding-top">
        <ion-button
          type="submit"
          expand="block"
          class="gradient-btn"
          :disabled="loading"
        >
          <ion-spinner v-if="loading" name="lines"></ion-spinner>
          <span v-else>Continuar</span>
        </ion-button>
      </div>
    </form>

    <!-- Step 2: methods supported by the resolved account -->
    <form v-else @submit.prevent="handlePasswordLogin()">
      <!-- Password method (always available for a found account) -->
      <ion-item class="input-item" lines="none">
        <ion-input
          v-model="credentials.password"
          label="Contraseña"
          label-placement="floating"
          type="password"
          placeholder="Ingresa tu contraseña"
          autocomplete="current-password"
          aria-describedby="login-password-error"
          @ion-blur="v$.password.$touch()"
        >
          <ion-input-password-toggle slot="end" />
        </ion-input>
      </ion-item>
      <div v-if="v$.password.$error" id="login-password-error" class="field-error">
        {{ v$.password.$errors[0].$message }}
      </div>

      <!-- Remember me toggle -->
      <ion-item class="input-item remember-item" lines="none">
        <ion-toggle
          slot="start"
          :checked="rememberMe"
          color="primary"
          aria-describedby="remember-toggle-desc"
          @ion-change="rememberMe = !rememberMe"
        />
        <ion-label class="remember-label">Mantener sesión abierta</ion-label>
      </ion-item>
      <span id="remember-toggle-desc" class="ion-hide">Al activar esta opción, la sesión permanecerá abierta incluso después de cerrar el navegador</span>

      <div v-if="error" class="error-message" role="alert" aria-live="assertive">
        <ion-icon :icon="alertCircle" size="small"></ion-icon>
        {{ error }}
      </div>

      <div class="ion-padding-top">
        <ion-button
          type="submit"
          expand="block"
          class="gradient-btn"
          :disabled="loading || cryptoLoading"
        >
          <ion-spinner v-if="loading || cryptoLoading" name="lines"></ion-spinner>
          <span v-if="cryptoLoading && !loading">{{ cryptoLoadingMessage }}</span>
          <span v-else-if="loading">Ingresando...</span>
          <span v-else>Iniciar con contraseña</span>
        </ion-button>
      </div>

      <!-- Passkey method (only when the account has passkeys enrolled) -->
      <div v-if="authMethods.has_passkeys" class="ion-padding-top">
        <ion-button
          type="button"
          expand="block"
          fill="outline"
          :disabled="loading || cryptoLoading || masterPasswordVisible"
          @click="handlePasskeyLogin()"
        >
          <ion-icon slot="start" :icon="keyOutline" aria-hidden="true"></ion-icon>
          Iniciar con passkey
        </ion-button>
      </div>

      <!-- Master-password fallback (advanced users, no PRF enrollment) -->
      <template v-if="masterPasswordVisible">
        <ion-item class="input-item" lines="none">
          <ion-input
            v-model="masterPassword"
            label="Contraseña maestra"
            label-placement="floating"
            type="password"
            placeholder="Contraseña para desbloquear tu perfil"
            autocomplete="current-password"
            aria-describedby="master-password-error"
            @ion-blur="masterPasswordTouched = true"
          >
            <ion-input-password-toggle slot="end" />
          </ion-input>
        </ion-item>
        <div v-if="masterPasswordTouched && !masterPassword.trim()" id="master-password-error" class="field-error">
          La contraseña maestra es requerida
        </div>
        <div class="ion-padding-top">
          <ion-button
            type="button"
            expand="block"
            class="gradient-btn"
            :disabled="passkeyUnlocking"
            @click="handleMasterPasswordUnlock()"
          >
            <ion-spinner v-if="passkeyUnlocking" name="lines"></ion-spinner>
            <span v-else>Desbloquear</span>
          </ion-button>
        </div>
      </template>

      <!-- Forgot password link -->
      <div class="ion-padding-top forgot-password">
        <ion-button
          fill="clear"
          expand="block"
          class="subtle"
          :disabled="loading || cryptoLoading"
          @click="goToRecovery"
        >
          ¿Olvidaste tu contraseña?
        </ion-button>
      </div>

      <!-- Advanced encryption info -->
      <div v-if="authMethods.encryption_level === 'advanced'" class="advanced-info">
        <ion-icon :icon="lockClosed" size="small"></ion-icon>
        <span>Modo cifrado avanzado: tu contraseña nunca sale de este dispositivo</span>
      </div>

      <!-- Back to identifier step -->
      <div class="ion-padding-top">
        <ion-button
          fill="clear"
          expand="block"
          class="subtle"
          :disabled="loading || cryptoLoading"
          @click="backToIdentifier()"
        >
          Usar otro usuario
        </ion-button>
      </div>
    </form>

    <div class="auth-card-footer">
      <ion-button
        v-if="oauthParams.redirect_uri"
        fill="clear"
        expand="block"
        :disabled="loading || cryptoLoading"
        class="subtle"
        @click="goToRegister"
      >
        Crear cuenta
      </ion-button>

      <ion-button
        fill="clear"
        expand="block"
        :disabled="loading || cryptoLoading"
        class="subtle"
        @click="goBack"
      >
        <ion-icon slot="start" :icon="arrowBack" aria-hidden="true"></ion-icon>
        Volver
      </ion-button>
    </div>
  </AuthCard>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref, onMounted, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import {
  IonItem,
  IonInput,
  IonButton,
  IonSpinner,
  IonIcon,
  IonInputPasswordToggle,
  IonToggle,
  IonLabel,
} from '@ionic/vue';
import {
  arrowBack,
  alertCircle,
  lockClosed,
  keyOutline,
} from 'ionicons/icons';
import useVuelidate from '@vuelidate/core';
import { required } from '@vuelidate/validators';
import { useValidation, withMsg } from '@/composables/useValidation';
import { useToast } from '@/composables/useToast';

const { handleApiError } = useValidation();
const { showSuccess } = useToast();

// 2. Controllers
import { useAuthController } from '@/controllers/authController';
import { useAuthStore } from '@/stores/auth';
import { passkeyService } from '@/services/passkeyService';

const authController = useAuthController();
const authStore = useAuthStore();

// 3. Components
import AuthCard from '@/components/AuthCard.vue';

// 4. Interface and types imports

// 5. Main (props, emits, etc.)
const props = defineProps<{
  isPublic?: boolean;
}>();

const router = useRouter();
const route = useRoute();

// 6. Vars and const
const credentials = ref({
  identifier: '',
  password: '',
});
const loading = ref(false);
const cryptoLoading = ref(false);
const cryptoLoadingMessage = ref('');
const error = ref('');

const oauthParams = ref({
  client_id: '',
  redirect_uri: '',
  state: '',
  response_type: '',
  code_challenge: '',
  code_challenge_method: '',
  code_verifier: '',
});

const isOAuthFlow = ref(false);
const rememberMe = ref(false);

// Identifier-first flow state
const step = ref<'identifier' | 'methods'>('identifier');
const authMethods = ref<{
  encryption_level: 'basic' | 'advanced' | null;
  has_passkeys: boolean;
}>({ encryption_level: null, has_passkeys: false });
// Deterministic login_id of the typed identifier, computed once the account
// resolves as advanced (used by the advanced password flow and the
// user-scoped passkey ceremony).
const resolvedLoginId = ref('');

const masterPassword = ref('');
const masterPasswordVisible = ref(false);
const masterPasswordTouched = ref(false);
const passkeyUnlocking = ref(false);
const activeLoginId = ref('');

// 7. Watch
// (none)

// 8. Computed
const rules = computed(() => ({
  identifier: { required: withMsg('El usuario es requerido', required) },
  password: { required: withMsg('La contraseña es requerida', required) },
}));

const v$ = useVuelidate(rules, credentials);

// 9. OnMounted
onMounted(async () => {
  if (route.query.verified === 'true') {
    showSuccess({ message: 'Email verificado. Ahora puedes iniciar sesión.' });
  }

  const {
    client_id,
    redirect_uri,
    state,
    response_type,
    code_challenge,
    code_challenge_method,
    code_verifier,
  } = route.query;
  if (client_id && redirect_uri) {
    isOAuthFlow.value = true;
    oauthParams.value = {
      client_id: client_id as string,
      redirect_uri: redirect_uri as string,
      state: (state as string) || '',
      response_type: (response_type as string) || 'code',
      code_challenge: (code_challenge as string) || '',
      code_challenge_method: (code_challenge_method as string) || '',
      code_verifier: (code_verifier as string) || '',
    };

    if (props.isPublic && authController.isAuthenticated.value) {
      loading.value = true;
      try {
        const oauthResult = await authController.oauthAuthorize({
          client_id: oauthParams.value.client_id,
          redirect_uri: oauthParams.value.redirect_uri,
          state: oauthParams.value.state,
          response_type: oauthParams.value.response_type || 'code',
          code_challenge: oauthParams.value.code_challenge || undefined,
          code_challenge_method:
            oauthParams.value.code_challenge_method || undefined,
          code_verifier: oauthParams.value.code_verifier || undefined,
        });

        if (!(await handleOAuthResponse({ response: oauthResult }))) {
          return;
        }
      } catch {
        loading.value = false;
      }
    }
  }
});

// 10. Functions
const handleOAuthResponse = async (params: {
  response: {
    authorization_required?: boolean;
    code?: string;
    state?: string | null;
    client?: { id: number; name: string; client_id: string };
  };
}): Promise<boolean> => {
  const { response } = params;
  if (response.authorization_required && response.client) {
    const query: Record<string, string> = {
      client_id: oauthParams.value.client_id,
      redirect_uri: oauthParams.value.redirect_uri,
      client_name: response.client.name,
      state: oauthParams.value.state,
      response_type: oauthParams.value.response_type || 'code',
    };
    if (oauthParams.value.code_challenge) {
      query.code_challenge = oauthParams.value.code_challenge;
      query.code_challenge_method = oauthParams.value.code_challenge_method;
    }
    if (oauthParams.value.code_verifier) {
      query.code_verifier = oauthParams.value.code_verifier;
    }
    router.replace({ name: 'OAuthAuthorize', query });
    return false;
  }

  if (response.code) {
    await authController.redirectOAuthCode({
      code: response.code,
      state: response.state || null,
      redirect_uri: oauthParams.value.redirect_uri,
    });
    return false;
  }

  return true;
};

// Step 1: resolve the identifier and discover the supported auth methods.
const handleContinue = async (): Promise<void> => {
  if (loading.value) return;
  v$.value.identifier.$touch();
  if (v$.value.identifier.$error) return;

  loading.value = true;
  error.value = '';
  try {
    const methods = await authController.fetchAuthMethods({
      identifier: credentials.value.identifier.trim(),
    });

    if (!methods.account_found) {
      error.value = 'No encontramos una cuenta con ese usuario.';
      return;
    }

    authMethods.value = {
      encryption_level: methods.encryption_level,
      has_passkeys: methods.has_passkeys,
    };
    // Server-resolved login_id (advanced accounts only): the identifier is
    // username-only, so this is always the HMAC-derived login_id the server
    // matched — the password and passkey flows use it directly without
    // recomputing anything client-side.
    resolvedLoginId.value = methods.login_id ?? '';

    step.value = 'methods';
  } catch (err) {
    error.value = handleApiError(err);
  } finally {
    loading.value = false;
  }
};

const backToIdentifier = (): void => {
  step.value = 'identifier';
  error.value = '';
  credentials.value.password = '';
  authMethods.value = { encryption_level: null, has_passkeys: false };
  resolvedLoginId.value = '';
  masterPassword.value = '';
  masterPasswordVisible.value = false;
  masterPasswordTouched.value = false;
  activeLoginId.value = '';
  v$.value.$reset();
};

// Step 2 (password method): route to the basic or advanced flow according to
// the encryption_level reported by the backend.
const handlePasswordLogin = async (): Promise<void> => {
  if (loading.value || cryptoLoading.value) return;
  v$.value.password.$touch();
  if (v$.value.password.$error) return;

  if (authMethods.value.encryption_level === 'advanced') {
    await handleAdvancedLogin({ loginId: resolvedLoginId.value });
    return;
  }
  await handleBasicLogin({ identifier: credentials.value.identifier.trim() });
};

const finishPasskeyLogin = async (): Promise<void> => {
  if (isOAuthFlow.value) {
    const oauthResult = await authController.oauthAuthorize({
      client_id: oauthParams.value.client_id,
      redirect_uri: oauthParams.value.redirect_uri,
      state: oauthParams.value.state,
      response_type: oauthParams.value.response_type || 'code',
      code_challenge: oauthParams.value.code_challenge || undefined,
      code_challenge_method: oauthParams.value.code_challenge_method || undefined,
      code_verifier: oauthParams.value.code_verifier || undefined,
    });

    if (!(await handleOAuthResponse({ response: oauthResult }))) {
      return;
    }
  } else {
    await router.replace(props.isPublic ? '/' : '/admin/dashboard');
  }
};

const handleMasterPasswordUnlock = async (): Promise<void> => {
  const password = masterPassword.value.trim();
  if (!password) {
    masterPasswordTouched.value = true;
    return;
  }
  const loginId = activeLoginId.value;
  if (!loginId) {
    error.value = 'No se pudo determinar tu Login ID.';
    return;
  }

  passkeyUnlocking.value = true;
  error.value = '';
  try {
    await authController.passkeyUnlockProfile({ loginId, password });
    await finishPasskeyLogin();
  } catch (err) {
    error.value = handleApiError(err);
  } finally {
    passkeyUnlocking.value = false;
  }
};

// Step 2 (passkey method): user-scoped ceremony. The server restricts
// allowCredentials to the resolved account (username for basic, login_id for
// advanced), so no resident-key discovery is needed.
const handlePasskeyLogin = async (): Promise<void> => {
  loading.value = true;
  error.value = '';
  try {
    const result = await authController.passkeyLogin({
      rememberMe: rememberMe.value,
      username:
        authMethods.value.encryption_level === 'advanced'
          ? undefined
          : credentials.value.identifier.trim(),
      login_id:
        authMethods.value.encryption_level === 'advanced'
          ? resolvedLoginId.value
          : undefined,
    });

    if (result.profile) {
      authStore.setProfile({ profile: result.profile });
    }

    await finishPasskeyLogin();
  } catch (err) {
    const apiError = err as Error & { code?: string; email?: string; loginId?: string };
    if (apiError.code === 'MASTER_PASSWORD_REQUIRED') {
      // Session applied; the user now unlocks the advanced profile locally.
      activeLoginId.value = apiError.loginId || '';
      masterPasswordVisible.value = true;
      loading.value = false;
      return;
    }
    if (apiError.code === 'EMAIL_NOT_VERIFIED') {
      router.replace({
        name: 'VerifyEmailPending',
        query: { email: apiError.email || '' },
      });
      return;
    }
    error.value = handleApiError(err);
  } finally {
    loading.value = false;
  }
};

const completePasskey2FA = async (params: {
  user_id?: number;
  login_id?: string;
}): Promise<void> => {
  try {
    // start + finish. finish verifies the assertion server-side.
    await passkeyService.authenticate(params);
  } catch (err) {
    const apiError = err as Error & { status?: number; message?: string };
    // 400 "Usuario no tiene passkeys registrados" -> no passkey -> skip.
    if (apiError.status === 400 && /passkey|no tiene/i.test(apiError.message || '')) {
      return;
    }
    // Passkey is enrolled but the ceremony failed -> abort login.
    throw err;
  }
};

const handleBasicLogin = async (params: { identifier: string }): Promise<void> => {
  const { identifier } = params;
  loading.value = true;
  error.value = '';
  try {
    const loginResult = await authController.login({
      user: identifier,
      password: credentials.value.password,
      client_id: oauthParams.value.client_id || undefined,
      rememberMe: rememberMe.value,
    });
    // Passkey 2FA (confirmation factor) when the user has a passkey enrolled.
    await completePasskey2FA({ user_id: loginResult.user.id });
    if (isOAuthFlow.value) {
      const oauthResult = await authController.oauthAuthorize({
        client_id: oauthParams.value.client_id,
        redirect_uri: oauthParams.value.redirect_uri,
        state: oauthParams.value.state,
        response_type: oauthParams.value.response_type || 'code',
        code_challenge: oauthParams.value.code_challenge || undefined,
        code_challenge_method:
          oauthParams.value.code_challenge_method || undefined,
        code_verifier: oauthParams.value.code_verifier || undefined,
      });

      if (!(await handleOAuthResponse({ response: oauthResult }))) {
        return;
      }
    } else {
      await router.replace(props.isPublic ? '/' : '/admin/dashboard');
    }
  } catch (err) {
    const apiError = err as Error & { code?: string; email?: string };
    if (apiError.code === 'EMAIL_NOT_VERIFIED') {
      router.replace({
        name: 'VerifyEmailPending',
        query: { email: apiError.email || identifier },
      });
      return;
    }
    error.value = handleApiError(err);
  } finally {
    loading.value = false;
  }
};

const handleAdvancedLogin = async (params: { loginId: string }): Promise<void> => {
  const { loginId } = params;
  loading.value = false;
  cryptoLoading.value = true;
  cryptoLoadingMessage.value = 'Derivando claves...';
  error.value = '';

  try {
    const loginResult = await authController.loginAdvanced({
      login_id: loginId,
      password: credentials.value.password,
      client_id: oauthParams.value.client_id || undefined,
      rememberMe: rememberMe.value,
    });

    // Persist the decrypted client-side profile so the admin UI can show the
    // current user's real name/email (the server stores them as null for ZKE).
    if (loginResult.profile) {
      authStore.setProfile({ profile: loginResult.profile });
    }

    // Passkey 2FA (confirmation factor) when the user has a passkey enrolled.
    await completePasskey2FA({ login_id: loginId });

    cryptoLoadingMessage.value = 'Verificando...';

    if (isOAuthFlow.value) {
      const oauthResult = await authController.oauthAuthorize({
        client_id: oauthParams.value.client_id,
        redirect_uri: oauthParams.value.redirect_uri,
        state: oauthParams.value.state,
        response_type: oauthParams.value.response_type || 'code',
        code_challenge: oauthParams.value.code_challenge || undefined,
        code_challenge_method:
          oauthParams.value.code_challenge_method || undefined,
        code_verifier: oauthParams.value.code_verifier || undefined,
      });

      if (!(await handleOAuthResponse({ response: oauthResult }))) {
        return;
      }
    } else {
      await router.replace(props.isPublic ? '/' : '/admin/dashboard');
    }
  } catch (err) {
    const apiError = err as Error & { code?: string; email?: string };
    if (apiError.code === 'EMAIL_NOT_VERIFIED') {
      router.replace({
        name: 'VerifyEmailPending',
        query: { email: apiError.email || loginId },
      });
      return;
    }
    error.value = handleApiError(err);
  } finally {
    cryptoLoading.value = false;
    cryptoLoadingMessage.value = '';
  }
};

const goToRegister = (): void => {
  const query: Record<string, string> = {
    client_id: oauthParams.value.client_id,
    redirect_uri: oauthParams.value.redirect_uri,
    response_type: oauthParams.value.response_type || 'code',
  };
  if (oauthParams.value.state) {
    query.state = oauthParams.value.state;
  }
  if (oauthParams.value.code_challenge) {
    query.code_challenge = oauthParams.value.code_challenge;
    query.code_challenge_method = oauthParams.value.code_challenge_method;
  }
  if (oauthParams.value.code_verifier) {
    query.code_verifier = oauthParams.value.code_verifier;
  }
  router.push({ name: 'AuthRegister', query });
};

const goBack = (): void => {
  if (window.history.length > 1) {
    router.back();
  } else {
    router.replace('/');
  }
};

const goToRecovery = (): void => {
  router.push({ name: 'recovery' });
};
</script>

<style scoped>
.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 8px 0;
}

.auth-card-footer {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.remember-item {
  --background: transparent;
  --padding-start: 0;
  --inner-padding-end: 0;
  margin-top: 4px;
}

.remember-label {
  font-size: 13px;
  color: var(--text-tertiary);
}

.advanced-info {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-primary);
  font-size: 12px;
  padding: 8px 0;
  border-top: 1px solid var(--ion-color-light);
  margin-top: 8px;
}

@media (max-width: 374px) {
  .auth-card-footer {
    gap: 2px;
  }
}
</style>
