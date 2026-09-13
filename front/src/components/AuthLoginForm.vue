<!-- SPDX-License-Identifier: MIT -->
<template>
  <AuthCard title="Bienvenido de nuevo" subtitle="Inicia sesión para acceder a tu cuenta">
    <!-- Step 1: identifier -->
    <form
      v-if="step === 'identifier'"
      class="auth-form"
      aria-label="Paso 1 de 2: identificación"
      @submit.prevent="handleContinue()"
    >
      <p class="sr-only" role="status">Paso 1 de 2: ingresa tu usuario</p>

      <ion-item class="input-item" lines="none">
        <ion-input
          ref="identifierInput"
          v-model="credentials.identifier"
          label-placement="floating"
          label="Usuario"
          type="text"
          placeholder="Ingresa tu usuario"
          autocomplete="username"
          enterkeyhint="next"
          autocapitalize="none"
          autocorrect="off"
          :spellcheck="false"
          :aria-invalid="v$.identifier.$error ? true : undefined"
          aria-describedby="login-identifier-error"
          @ion-blur="v$.identifier.$touch()"
        />
      </ion-item>

      <div v-if="v$.identifier.$error" id="login-identifier-error" class="auth-field-error">
        {{ v$.identifier.$errors[0].$message }}
      </div>

      <div
        v-if="error"
        ref="loginError"
        tabindex="-1"
        class="auth-error"
        role="alert"
        aria-live="assertive"
      >
        <ion-icon :icon="alertCircle" size="small"></ion-icon>

        <span>{{ error }}</span>
      </div>

      <ion-button type="submit" expand="block" class="auth-submit gradient-btn" :disabled="loading">
        <ion-spinner v-if="loading" name="lines"></ion-spinner>

        <span v-else>Continuar</span>
      </ion-button>
    </form>

    <!-- Step 2: methods supported by the resolved account -->
    <form
      v-else
      class="auth-form"
      aria-label="Paso 2 de 2: autenticación"
      @submit.prevent="handleStep2Submit()"
    >
      <p v-if="!masterPasswordVisible && usePasswordMethod" class="sr-only" role="status">
        Paso 2 de 2: ingresa tu contraseña
      </p>

      <p v-else-if="!masterPasswordVisible" class="sr-only" role="status">
        Paso 2 de 2: autentica con passkey
      </p>

      <p v-else class="sr-only" role="status">Desbloquea tu perfil con tu contraseña maestra</p>

      <!-- Method selector -->
      <ion-segment
        v-if="authMethods.has_passkeys && !masterPasswordVisible"
        v-model="authMethod"
        class="auth-methods"
        aria-label="Método de inicio de sesión"
      >
        <ion-segment-button value="password" :aria-selected="authMethod === 'password'">
          <ion-label>Contraseña</ion-label>
        </ion-segment-button>

        <ion-segment-button value="passkey" :aria-selected="authMethod === 'passkey'">
          <ion-label>Passkey</ion-label>
        </ion-segment-button>
      </ion-segment>

      <!-- Error -->
      <div
        v-if="error"
        ref="loginError"
        tabindex="-1"
        class="auth-error"
        role="alert"
        aria-live="assertive"
      >
        <ion-icon :icon="alertCircle" size="small"></ion-icon>

        <span>{{ error }}</span>
      </div>

      <!-- Password method -->
      <ion-item v-if="!masterPasswordVisible && usePasswordMethod" class="input-item" lines="none">
        <ion-input
          ref="passwordInput"
          v-model="credentials.password"
          label="Contraseña"
          label-placement="floating"
          type="password"
          placeholder="Ingresa tu contraseña"
          autocomplete="current-password"
          enterkeyhint="go"
          :aria-invalid="v$.password.$error ? true : undefined"
          aria-describedby="login-password-error"
          @ion-blur="v$.password.$touch()"
        >
          <ion-input-password-toggle slot="end" />
        </ion-input>
      </ion-item>

      <div
        v-if="!masterPasswordVisible && usePasswordMethod && v$.password.$error"
        id="login-password-error"
        class="auth-field-error"
      >
        {{ v$.password.$errors[0].$message }}
      </div>

      <!-- Passkey method (initial state) -->
      <p v-if="!masterPasswordVisible && !usePasswordMethod" class="auth-hint">
        Usá tu huella, rostro o llave de seguridad.
      </p>

      <!-- Master-password unlock (sub-state of the passkey method) -->
      <template v-if="masterPasswordVisible">
        <p class="auth-hint">
          Tu perfil está protegido. Ingresá tu contraseña maestra para desbloquearlo.
        </p>

        <ion-item class="input-item" lines="none">
          <ion-input
            ref="masterPasswordInput"
            v-model="masterPassword"
            label="Contraseña maestra"
            label-placement="floating"
            type="password"
            placeholder="Contraseña para desbloquear tu perfil"
            autocomplete="current-password"
            enterkeyhint="go"
            :aria-invalid="masterPasswordTouched && !masterPassword.trim() ? true : undefined"
            aria-describedby="master-password-error"
            @ion-blur="masterPasswordTouched = true"
          >
            <ion-input-password-toggle slot="end" />
          </ion-input>
        </ion-item>

        <div
          v-if="masterPasswordTouched && !masterPassword.trim()"
          id="master-password-error"
          class="auth-field-error"
        >
          La contraseña maestra es requerida
        </div>
      </template>

      <!-- Remember me (single instance; hidden while unlocking — already honoured by the passkey ceremony) -->
      <ion-item
        v-if="!masterPasswordVisible"
        class="auth-remember"
        lines="none"
        button
        @click="rememberMe = !rememberMe"
      >
        <ion-toggle
          slot="start"
          :checked="rememberMe"
          color="primary"
          aria-describedby="remember-toggle-desc"
          @ion-change="rememberMe = $event.detail.checked"
        />
        <ion-label class="auth-remember-label">Mantener sesión abierta</ion-label>
      </ion-item>

      <span v-if="!masterPasswordVisible" id="remember-toggle-desc" class="sr-only">
        Al activar esta opción, la sesión permanecerá abierta incluso después de cerrar el navegador
      </span>

      <!-- Primary action -->
      <ion-button
        v-if="!masterPasswordVisible && usePasswordMethod"
        type="submit"
        expand="block"
        class="auth-submit gradient-btn"
        :disabled="loading || cryptoLoading"
      >
        <ion-spinner v-if="loading || cryptoLoading" name="lines"></ion-spinner>

        <span v-if="cryptoLoading">{{ cryptoLoadingMessage }}</span>

        <span v-else-if="loading">Ingresando...</span>

        <span v-else>Ingresar</span>
      </ion-button>

      <ion-button
        v-else-if="!masterPasswordVisible"
        type="button"
        expand="block"
        class="auth-submit gradient-btn"
        :disabled="loading || cryptoLoading"
        @click="handlePasskeyLogin()"
      >
        <ion-spinner v-if="loading || cryptoLoading" name="lines"></ion-spinner>

        <span v-else>
          <ion-icon slot="start" :icon="keyOutline" aria-hidden="true"></ion-icon>
          Iniciar con passkey
        </span>
      </ion-button>

      <ion-button
        v-else
        type="button"
        expand="block"
        class="auth-submit gradient-btn"
        :disabled="passkeyUnlocking"
        @click="handleMasterPasswordUnlock()"
      >
        <ion-spinner v-if="passkeyUnlocking" name="lines"></ion-spinner>

        <span v-else>Desbloquear</span>
      </ion-button>

      <span role="status" aria-live="polite" class="sr-only">
        {{ loading || cryptoLoading ? 'Procesando tu ingreso…' : '' }}
      </span>

      <!-- Secondary links -->
      <div v-if="!masterPasswordVisible" class="auth-links">
        <ion-button
          fill="clear"
          size="small"
          class="subtle"
          :disabled="loading || cryptoLoading"
          @click="goToRecovery()"
        >
          ¿Olvidaste tu contraseña?
        </ion-button>

        <ion-button
          fill="clear"
          size="small"
          class="subtle"
          :disabled="loading || cryptoLoading"
          @click="backToIdentifier()"
        >
          Usar otro usuario
        </ion-button>
      </div>

      <div v-else class="auth-links">
        <ion-button
          fill="clear"
          size="small"
          class="subtle"
          :disabled="passkeyUnlocking"
          @click="cancelUnlock()"
        >
          Volver
        </ion-button>

        <ion-button
          fill="clear"
          size="small"
          class="subtle"
          :disabled="passkeyUnlocking"
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
        v-if="step === 'identifier'"
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
import { ref, onMounted, computed, nextTick } from 'vue';
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
import { arrowBack, alertCircle, keyOutline } from 'ionicons/icons';
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
const identifierInput = ref<{ setFocus?: () => Promise<void> } | null>(null);
const passwordInput = ref<{ setFocus?: () => Promise<void> } | null>(null);
const masterPasswordInput = ref<{ setFocus?: () => Promise<void> } | null>(null);
const loginError = ref<HTMLDivElement | null>(null);

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

// Step 2 method selector: 'password' | 'passkey'
// Persisted in localStorage so the user's last choice is remembered.
const authMethod = ref<'password' | 'passkey'>('password');

// 7. Watch
// (none)

// 8. Computed
const rules = computed(() => ({
  identifier: { required: withMsg('El usuario es requerido', required) },
  password: { required: withMsg('La contraseña es requerida', required) },
}));

const v$ = useVuelidate(rules, credentials);

// The password panel is the active method when the user picked it, or when
// the account has no passkeys enrolled (segment hidden in that case).
const usePasswordMethod = computed(
  () => authMethod.value === 'password' || !authMethods.value.has_passkeys,
);

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
          code_challenge_method: oauthParams.value.code_challenge_method || undefined,
          code_verifier: oauthParams.value.code_verifier || undefined,
        });

        if (!(await handleOAuthResponse({ response: oauthResult }))) {
          return;
        }
      } finally {
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
  if (v$.value.identifier.$error) {
    await nextTick();
    await identifierInput.value?.setFocus?.();
    return;
  }

  loading.value = true;
  error.value = '';
  try {
    const methods = await authController.fetchAuthMethods({
      identifier: credentials.value.identifier.trim(),
    });

    if (!methods.account_found) {
      error.value = 'No encontramos una cuenta con ese usuario.';
      await nextTick();
      loginError.value?.focus();
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
    await nextTick();
    await passwordInput.value?.setFocus?.();
  } catch (err) {
    // Step 1 stays enumeration-safe: every lookup failure shows the same
    // message instead of leaking the server error detail.
    console.error('[AuthLoginForm] fetchAuthMethods failed', err);
    error.value = 'No encontramos una cuenta con ese usuario.';
    await nextTick();
    loginError.value?.focus();
  } finally {
    loading.value = false;
  }
};

const backToIdentifier = async (): Promise<void> => {
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
  await nextTick();
  await identifierInput.value?.setFocus?.();
};

// Step 2 submit router: unlock mode has no visible password CTA, so Enter
// inside the master-password input must unlock instead of password-login.
const handleStep2Submit = async (): Promise<void> => {
  if (masterPasswordVisible.value) {
    await handleMasterPasswordUnlock();
    return;
  }
  await handlePasswordLogin();
};

// Leave unlock mode keeping the resolved account (step 2 stays mounted).
const cancelUnlock = async (): Promise<void> => {
  masterPasswordVisible.value = false;
  masterPassword.value = '';
  masterPasswordTouched.value = false;
  activeLoginId.value = '';
  error.value = '';
  await nextTick();
  await passwordInput.value?.setFocus?.();
};

// Step 2 (password method): route to the basic or advanced flow according to
// the encryption_level reported by the backend.
const handlePasswordLogin = async (): Promise<void> => {
  if (loading.value || cryptoLoading.value) return;
  v$.value.password.$touch();
  if (v$.value.password.$error) {
    await nextTick();
    await passwordInput.value?.setFocus?.();
    return;
  }

  if (authMethods.value.encryption_level === 'advanced') {
    await handleAdvancedLogin({ loginId: resolvedLoginId.value });
    return;
  }
  await handleBasicLogin({ identifier: credentials.value.identifier.trim() });
};

// Post-login redirect shared by the password and passkey flows: OAuth apps
// go through oauthAuthorize, direct logins land on the dashboard.
const runPostLoginRedirect = async (): Promise<void> => {
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
    return;
  }
  await router.replace(props.isPublic ? '/' : '/admin/dashboard');
};

const finishPasskeyLogin = async (): Promise<void> => {
  await runPostLoginRedirect();
};

const handleMasterPasswordUnlock = async (): Promise<void> => {
  const password = masterPassword.value;
  if (!password.trim()) {
    masterPasswordTouched.value = true;
    await nextTick();
    await masterPasswordInput.value?.setFocus?.();
    return;
  }
  const loginId = activeLoginId.value;
  if (!loginId) {
    error.value = 'No se pudo determinar tu Login ID.';
    await nextTick();
    loginError.value?.focus();
    return;
  }

  passkeyUnlocking.value = true;
  error.value = '';
  try {
    await authController.passkeyUnlockProfile({ loginId, password });
    await finishPasskeyLogin();
  } catch (err) {
    error.value = handleApiError(err);
    await nextTick();
    loginError.value?.focus();
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
        authMethods.value.encryption_level === 'advanced' ? resolvedLoginId.value : undefined,
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
      await nextTick();
      await masterPasswordInput.value?.setFocus?.();
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
    await nextTick();
    loginError.value?.focus();
  } finally {
    loading.value = false;
  }
};

const handleBasicLogin = async (params: { identifier: string }): Promise<void> => {
  const { identifier } = params;
  loading.value = true;
  error.value = '';
  try {
    await authController.login({
      user: identifier,
      password: credentials.value.password,
      client_id: oauthParams.value.client_id || undefined,
      rememberMe: rememberMe.value,
    });
    // Explicit password choice: no passkey ceremony here. Passkey is only
    // invoked via handlePasskeyLogin when the user chooses that method.
    await runPostLoginRedirect();
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
    await nextTick();
    loginError.value?.focus();
  } finally {
    loading.value = false;
  }
};

const handleAdvancedLogin = async (params: { loginId: string }): Promise<void> => {
  const { loginId } = params;
  loading.value = true;
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

    // Explicit password choice: no passkey ceremony here. Passkey is only
    // invoked via handlePasskeyLogin when the user chooses that method.
    cryptoLoadingMessage.value = 'Verificando...';

    await runPostLoginRedirect();
  } catch (err) {
    const apiError = err as Error & { code?: string; email?: string };
    if (apiError.code === 'EMAIL_NOT_VERIFIED') {
      router.replace({
        name: 'VerifyEmailPending',
        query: { email: apiError.email || '' },
      });
      return;
    }
    error.value = handleApiError(err);
    await nextTick();
    loginError.value?.focus();
  } finally {
    loading.value = false;
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
/* Vertical rhythm owned by the form: every direct child is one block. */
.auth-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}

/* The global .input-item carries its own margin-bottom; inside the form the
       flex gap owns the spacing, so neutralize it to avoid double gaps. */
.auth-form .input-item {
  margin-bottom: 0;
}

/* Method selector sits slightly closer to the first field below it. */
.auth-methods {
  margin-bottom: calc(var(--space-2) * -1);
}

.auth-hint {
  margin: 0;
  text-align: center;
  font-size: var(--text-md, 14px);
  line-height: 1.5;
  color: var(--text-tertiary);
}

/* Field-level validation message: hugs the field it belongs to. */
.auth-field-error {
  margin-top: calc(var(--space-3) * -1);
  padding-left: var(--space-4);
  color: var(--ion-color-danger);
  font-size: var(--text-sm, 12px);
}

/* Form-level error: a contained banner instead of loose red text. */
.auth-error {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
  border: 1px solid rgba(var(--ion-color-danger-rgb), 0.35);
  border-radius: var(--radius-md);
  background: rgba(var(--ion-color-danger-rgb), 0.1);
  color: var(--ion-color-danger);
  font-size: var(--text-sm, 13px);
  line-height: 1.4;
}

/* Mirrors the .input-item box so the toggle aligns with the fields above */
.auth-remember {
  --background: var(--glass-bg);
  --padding-start: var(--space-4);
  --padding-end: var(--space-4);
  --inner-padding-end: var(--space-4);
  --min-height: 52px;
  margin-bottom: 0;
  border: 1.5px solid var(--glass-border);
  border-radius: var(--radius-md);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
}

.auth-remember-label {
  font-size: var(--text-md, 13px);
  color: var(--text-secondary);
}

.auth-submit {
  --border-radius: var(--radius-md);
  margin: 0;
  font-weight: 600;
}

.auth-links {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 0 var(--space-2);
  margin-top: calc(var(--space-3) * -1);
}

.auth-card-footer {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-1);
  margin-top: var(--space-2);
}
</style>
