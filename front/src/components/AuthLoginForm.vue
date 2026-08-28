<!-- SPDX-License-Identifier: MIT -->
<template>
  <AuthCard title="Iniciar Sesión" subtitle="Accede a tu cuenta de SSO Server">
    <form @submit.prevent="handleLogin">
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

      <!-- Error message -->
      <div v-if="error" class="error-message" role="alert" aria-live="assertive">
        <ion-icon :icon="alertCircle" size="small"></ion-icon>
        {{ error }}
      </div>

      <!-- Submit button with loading states -->
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

      <!-- Advanced encryption info -->
      <div v-if="isAdvancedLogin" class="advanced-info">
        <ion-icon :icon="lockClosed" size="small"></ion-icon>
        <span>Modo cifrado avanzado: tu contraseña nunca sale de este dispositivo</span>
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

const handleBasicLogin = async (identifier: string): Promise<void> => {
  loading.value = true;
  error.value = '';
  try {
    await authController.login({
      user: identifier,
      password: credentials.value.password,
      client_id: oauthParams.value.client_id || undefined,
      rememberMe: rememberMe.value,
    });
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