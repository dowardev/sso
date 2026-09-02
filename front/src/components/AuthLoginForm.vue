<!-- SPDX-License-Identifier: MIT -->
<template>
  <AuthCard title="Iniciar Sesión" subtitle="Accede a tu cuenta de SSO Server">
    <form @submit.prevent="loginMode === 'passkey' ? handlePasskeyLogin() : handleLogin()">
        <!-- Login mode toggle -->
        <ion-segment v-model="loginMode" class="login-mode-segment" aria-label="Método de inicio de sesión">
          <ion-segment-button value="password">Contraseña</ion-segment-button>
          <ion-segment-button value="passkey">Passkey</ion-segment-button>
        </ion-segment>

        <!-- Password-mode fields -->
        <template v-if="loginMode === 'password'">
          <!-- Login ID / User / Email field -->
          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="credentials.identifier"
              label-placement="floating"
              :label="isAdvancedLogin ? 'Login ID' : 'Usuario / Email'"
              type="text"
              :placeholder="isAdvancedLogin ? 'Ingresa tu Login ID' : 'Ingresa tu usuario o email'"
              autocomplete="username"
              aria-describedby="login-identifier-error"
              @ion-blur="v$.identifier.$touch()"
            />
          </ion-item>
          <div v-if="v$.identifier.$error" id="login-identifier-error" class="field-error">
            {{ v$.identifier.$errors[0].$message }}
          </div>

          <!-- Password field -->
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
        </template>

        <!-- Passkey-mode fields -->
        <template v-else>
          <div class="advanced-info">
            <ion-icon :icon="keyOutline" size="small"></ion-icon>
            <span>Ingresá con tu passkey</span>
          </div>

          <div class="ion-padding-top">
            <ion-button
              type="submit"
              expand="block"
              class="gradient-btn"
              :disabled="loading || cryptoLoading || masterPasswordVisible"
            >
              <ion-spinner v-if="loading || cryptoLoading" name="lines"></ion-spinner>
              <span v-if="cryptoLoading">Verificando passkey...</span>
              <span v-else-if="loading">Autenticando...</span>
              <span v-else>Ingresar con passkey</span>
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
                @click="handleMasterPasswordUnlock"
              >
                <ion-spinner v-if="passkeyUnlocking" name="lines"></ion-spinner>
                <span v-else>Desbloquear</span>
              </ion-button>
            </div>
          </template>
        </template>

        <!-- Error message -->
        <div v-if="error" class="error-message" role="alert" aria-live="assertive">
          <ion-icon :icon="alertCircle" size="small"></ion-icon>
          {{ error }}
        </div>

        <!-- Password-mode submit button, forgot-password and advanced info -->
        <template v-if="loginMode === 'password'">
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
              <span v-else>Ingresar</span>
            </ion-button>
          </div>

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
          <div v-if="isAdvancedLogin" class="advanced-info">
            <ion-icon :icon="lockClosed" size="small"></ion-icon>
            <span>Modo cifrado avanzado: tu contraseña nunca sale de este dispositivo</span>
          </div>
        </template>
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
  IonSegment,
  IonSegmentButton,
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
const loginMode = ref<'password' | 'passkey'>('password');
const masterPassword = ref('');
const masterPasswordVisible = ref(false);
const masterPasswordTouched = ref(false);
const passkeyUnlocking = ref(false);
const activeLoginId = ref('');

// Detect if user is trying advanced login (by entering a login_id format)
const isAdvancedLogin = computed(() => {
  // Advanced login uses login_id which is a base64 HMAC - typically longer and different format
  // For UX, we'll check if the identifier looks like a login_id (base64, no @ symbol)
  const id = credentials.value.identifier.trim();
  return id.length > 20 && !id.includes('@') && !id.includes(' ');
});

// 7. Watch
// (none)

// 8. Computed
const rules = computed(() => ({
  identifier: { required: withMsg('El identificador es requerido', required) },
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

const handleLogin = async (): Promise<void> => {
  const isValid = await v$.value.$validate();
  if (!isValid) return;

  const identifier = credentials.value.identifier.trim();

  // Email-like identifiers always use basic login (an email cannot be a
  // ZKE login_id). A username may belong to an advanced user (whose plaintext
  // email/user are null and password is Argon2id(auth_proof)), so we compute
  // the deterministic login_id = btoa(HMAC(username)) and probe the advanced
  // path first — falling back to basic when the user is not advanced.
  if (identifier.includes('@')) {
    await handleBasicLogin(identifier);
    return;
  }

  try {
    const loginId = await authController.computeLoginId({ username: identifier });
    await authController.fetchCryptoBlobs({ loginId });
    await handleAdvancedLogin(loginId);
  } catch {
    // /crypto/blobs failed (404 / user not advanced) -> fall back to basic.
    await handleBasicLogin(identifier);
  }
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

    const handlePasskeyLogin = async (): Promise<void> => {
      // Username-less (discoverable) ceremony: the browser presents the user's
      // resident passkeys. No identifier needed — the server resolves the user
      // from the assertion userHandle.
      loading.value = true;
      error.value = '';
      try {
        const result = await authController.passkeyLogin({
          rememberMe: rememberMe.value,
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

const handleBasicLogin = async (identifier: string): Promise<void> => {
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
    const baseMessage = handleApiError(err);
    error.value = identifier.includes('@')
      ? `${baseMessage} Si tu cuenta tiene cifrado avanzado, iniciá sesión con tu nombre de usuario, no con tu correo.`
      : baseMessage;
  } finally {
    loading.value = false;
  }
};

const handleAdvancedLogin = async (loginId: string): Promise<void> => {
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