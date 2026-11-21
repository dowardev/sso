<!-- SPDX-License-Identifier: MIT -->
<template>
  <AuthCard
    title="Bienvenido de nuevo"
    subtitle="Inicia sesión para acceder a tu cuenta"
    :below="!props.isPublic"
  >
    <!-- Step 1 (LBDS-304): identifier + password in one screen. -->
    <form
      v-if="step === 'credentials'"
      class="auth-form"
      aria-label="Paso 1 de 2: credenciales"
      @submit.prevent="handleContinue()"
    >
      <p class="sr-only" role="status">Paso 1 de 2: ingresa tu usuario y contraseña</p>

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
          :class="ionicValidationClasses(v$.identifier)"
          aria-describedby="login-identifier-error"
          @ion-blur="v$.identifier.$touch()"
        />
      </ion-item>

      <div v-if="v$.identifier.$error" id="login-identifier-error" class="auth-field-error">
        {{ v$.identifier.$errors[0].$message }}
      </div>

      <ion-item class="input-item" lines="none">
        <ion-input
          ref="passwordInput"
          v-model="credentials.password"
          label="Contraseña"
          label-placement="floating"
          type="password"
          placeholder="Ingresa tu contraseña"
          autocomplete="current-password"
          enterkeyhint="go"
          :class="ionicValidationClasses(v$.password)"
          aria-describedby="login-password-error"
          @ion-blur="v$.password.$touch()"
        >
          <ion-input-password-toggle slot="end" />
        </ion-input>
      </ion-item>

      <div
        v-if="v$.password.$error"
        id="login-password-error"
        class="auth-field-error"
      >
        {{ v$.password.$errors[0].$message }}
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

    <!-- Step 2 (LBDS-304): second factor of auth. Passkey is the primary
         action when the account has passkeys; TOTP is the fallback via
         "Usar otro método". When the account has no passkeys, TOTP is
         shown directly. -->
    <form
      v-else
      class="auth-form"
      aria-label="Paso 2 de 2: verificación en dos pasos"
      @submit.prevent="handleStep2Submit()"
    >
      <p v-if="!masterPasswordVisible" class="sr-only" role="status">
        Paso 2 de 2: elegí tu método de verificación
      </p>

      <p v-else class="sr-only" role="status">Desbloquea tu perfil con tu contraseña maestra</p>

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

      <!-- Passkey prompt (default 2FA when has_passkeys) -->
      <template v-if="authMethods.has_passkeys && twoFactorView === 'passkey' && !masterPasswordVisible">
        <p class="auth-hint">
          Usá tu huella, rostro o llave de seguridad. Si este navegador no
          tiene una, escaneá el QR con tu teléfono para aprobarla desde ahí
          (Chrome y Safari; en Firefox usá la passkey local o elegí otro método).
        </p>

        <!-- LBDS-304: passkey and TOTP are presented as two equal-weight
             buttons so the user sees both options at the same visual level
             (instead of a dominant primary + an orphaned secondary link). -->
        <div class="auth-method-buttons">
          <ion-button
            ref="passkeyButton"
            type="button"
            expand="block"
            class="auth-submit gradient-btn auth-method-btn"
            :disabled="loading || cryptoLoading || passkeyUnlocking"
            @click.stop.prevent="handleUsePasskeyInstead($event)"
          >
            <ion-spinner v-if="loading || cryptoLoading || passkeyUnlocking" name="lines"></ion-spinner>

            <ion-icon v-else slot="start" :icon="keyOutline" aria-hidden="true"></ion-icon>

            <span v-if="!(loading || cryptoLoading || passkeyUnlocking)">Usar passkey</span>
          </ion-button>

          <ion-button
            v-if="totpToken"
            type="button"
            expand="block"
            class="auth-submit auth-method-btn auth-method-btn--secondary"
            :disabled="loading || cryptoLoading || passkeyUnlocking"
            @click.stop.prevent="twoFactorView = 'totp'"
          >
            Usar otro método
          </ion-button>
        </div>
      </template>

      <!-- TOTP prompt (shown when has_passkeys is false OR user clicks "Usar otro método") -->
      <template v-else-if="twoFactorView === 'totp' && !masterPasswordVisible">
        <p class="auth-hint">
          Ingresá el código de 6 dígitos de tu app autenticadora.
        </p>

        <ion-item v-if="totpInputMode === 'code'" class="input-item" lines="none">
          <ion-input
            v-model="totpCode"
            label="Código de verificación"
            label-placement="floating"
            inputmode="numeric"
            pattern="[0-9]*"
            :maxlength="totpDigits"
            :placeholder="'0'.repeat(totpDigits)"
            :disabled="totpSubmitting"
          />
        </ion-item>

        <ion-item v-else class="input-item" lines="none">
          <ion-input
            v-model="totpBackupCode"
            label="Código de respaldo"
            label-placement="floating"
            autocapitalize="characters"
            placeholder="Código de respaldo"
            :disabled="totpSubmitting"
          />
        </ion-item>

        <div v-if="totpError" class="auth-field-error" role="alert">{{ totpError }}</div>

        <div class="auth-method-buttons">
          <ion-button
            type="button"
            expand="block"
            class="auth-submit gradient-btn auth-method-btn"
            :disabled="totpSubmitting || !canSubmitTotp"
            @click.stop.prevent="submitTotpChallenge()"
          >
            <ion-spinner v-if="totpSubmitting" name="lines"></ion-spinner>

            <span v-else>Verificar</span>
          </ion-button>

          <ion-button
            v-if="pendingPasskeyToken"
            type="button"
            expand="block"
            class="auth-submit auth-method-btn auth-method-btn--secondary"
            :disabled="totpSubmitting"
            @click.stop.prevent="twoFactorView = 'passkey'"
          >
            Usar passkey
          </ion-button>
        </div>
      </template>

      <!-- Remember me (LBDS-304): on step 2 the user has already entered
           their identifier and password; remembering the session is the
           only remaining toggle. -->
      <!-- The item is not a `button`: a clickable item renders a native <button>,
           and nesting the toggle's own <input type="checkbox"> inside it would
           produce invalid markup and broken keyboard semantics. The toggle is
           the only interactive control; the item click is a mouse convenience. -->
      <ion-item
        v-if="!masterPasswordVisible"
        class="auth-remember"
        lines="none"
        @click="rememberMe = !rememberMe"
      >
        <ion-toggle
          slot="start"
          :checked="rememberMe"
          color="primary"
          aria-describedby="remember-toggle-desc"
          @click.stop
          @ion-change="rememberMe = $event.detail.checked"
        />
        <ion-label class="auth-remember-label">Mantener sesión abierta</ion-label>
      </ion-item>

      <span v-if="!masterPasswordVisible" id="remember-toggle-desc" class="sr-only">
        Al activar esta opción, la sesión permanecerá abierta incluso después de cerrar el navegador
      </span>

      <span role="status" aria-live="polite" class="sr-only">
        {{ loading || cryptoLoading ? 'Procesando tu ingreso…' : '' }}
      </span>

      <!-- LBDS-304: backup codes are a RECOVERY option, not a regular
           choice. The link sits BELOW the toggle (where the footer
           links used to be) so the recovery path is visually attached
           to the form, not floating between the input and the CTAs. -->
      <div v-if="twoFactorView === 'totp' && !masterPasswordVisible" class="auth-method-toggle-row">
        <ion-button
          type="button"
          fill="clear"
          size="small"
          class="subtle auth-method-toggle"
          :disabled="totpSubmitting"
          @click.stop.prevent="totpInputMode = totpInputMode === 'code' ? 'backup' : 'code'"
        >
          {{ totpInputMode === 'code' ? 'Usar código de respaldo' : 'Usar código de la app' }}
        </ion-button>
      </div>

      <!-- Secondary links live in the card footer below, not inside the
           form itself. -->
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
        v-if="step === 'credentials'"
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

    <!-- Secondary links: rendered right BELOW the card (outside the glass
         box), attached to its bottom edge. -->
    <template #below>
      <ion-button
        v-if="!props.isPublic"
        fill="clear"
        size="small"
        class="subtle"
        @click.stop.prevent="changeUser"
      >
        <ion-icon slot="start" :icon="arrowBack" aria-hidden="true"></ion-icon>
        Cambiar de usuario
      </ion-button>

      <ion-button
        v-if="!props.isPublic"
        fill="clear"
        size="small"
        class="subtle"
        @click="goToRecovery"
      >
        ¿Olvidaste tu contraseña?
      </ion-button>
    </template>
  </AuthCard>

  <!-- (LBDS-304): the TOTP / 2FA choice used to live in a separate modal
       after the password step. With the redesigned 2-step flow
       (credentials on step 1, second factor on step 2) the TOTP prompt
       is part of step 2 itself, so no modal is needed. -->
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ionicValidationClasses } from '@/utils/ionicValidation';
import { ref, onMounted, computed, nextTick, watch } from 'vue';
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

// Two-step flow state (LBDS-304): step 1 = credentials (identifier + password),
// step 2 = second factor (passkey primary, TOTP via "Usar otro método").
const step = ref<'credentials' | 'twoFactor'>('credentials');
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

const passkeyButton = ref<{ $el?: HTMLElement } | null>(null);

// Pending passkey token from a PASSKEY_REQUIRED challenge. Stored so the
// WebAuthn ceremony starts only when the user explicitly clicks the passkey
// button on step 2, not automatically after the password step.
const pendingPasskeyToken = ref('');

// TOTP challenge (LBDS-8): set when the password step returns
// TOTP_REQUIRED. The modal is open as long as `totpToken` is non-empty;
// closing it (or cancelling) clears the token so the user can re-enter
// their password.
const totpToken = ref('');
const totpDigits = ref(6);
const totpCode = ref('');
const totpBackupCode = ref('');
const totpInputMode = ref<'code' | 'backup'>('code');
const totpSubmitting = ref(false);
const totpError = ref('');
const totpRememberMe = ref(false);
// LBDS-8 review (F-05, F-06): template refs to the modal inputs so we
// can auto-focus on open and when the user switches between "Código" /
// "De respaldo" segments. Without these, the user has to click or
// tab into the field manually.
const totpCodeInput = ref<{ setFocus?: () => Promise<void> } | null>(null);
const totpBackupInput = ref<{ setFocus?: () => Promise<void> } | null>(null);
const totpRequired = computed(() => totpToken.value !== '');
// LBDS-304: which 2FA view is active inside the modal. 'passkey' is the
// 2FA choice view (passkey primary, TOTP via "Usar otro método"). 'totp'
// is the code/backup view. null when the modal is not open.
const twoFactorView = ref<'passkey' | 'totp' | null>(null);
const canSubmitTotp = computed(() =>
  totpInputMode.value === 'code'
    ? totpCode.value.length === totpDigits.value
    : totpBackupCode.value.trim().length > 0,
);

// Auto-focus the active input when the modal opens and when the user
// switches the segment.
watch(totpRequired, async (open) => {
  if (!open) return;
  await nextTick();
  if (totpInputMode.value === 'code') {
    await totpCodeInput.value?.setFocus?.();
  } else {
    await totpBackupInput.value?.setFocus?.();
  }
});
watch(totpInputMode, async () => {
  if (!totpRequired.value) return;
  await nextTick();
  if (totpInputMode.value === 'code') {
    await totpCodeInput.value?.setFocus?.();
  } else {
    await totpBackupInput.value?.setFocus?.();
  }
});

// 7. Watch
// (LBDS-304) The debounced identifier lookup for the interim
// "Iniciar con passkey" button was removed — the passkey challenge
// now comes from the backend after the password step.

// 8. Computed
const rules = computed(() => ({
  identifier: { required: withMsg('El usuario es requerido', required) },
  password: { required: withMsg('La contraseña es requerida', required) },
}));

const v$ = useVuelidate(rules, credentials);

// (LBDS-304): the password is the first factor (typed on step 1) and the
// 2FA is chosen on step 2. The previous "use password or passkey?" branch
// on step 2 is gone — we always collect the password on step 1, then
// either pass through directly or land on the second-factor step.

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

// (LBDS-304: focusPasskeyButton was removed — passkey button is now
// always visible on step 2 so auto-focus on the form is enough.)

// (LBDS-304): the auth-method toggle is gone — step 2 is the second
// factor (passkey or TOTP), not a password/passkey selector.

// LBDS-304: step 1 — validate identifier + password, discover supported
// methods, then attempt the password login. If the backend returns
// TOTP_REQUIRED, advance to step 2 (second factor). Otherwise, the
// controller's `login` / `loginAdvanced` handles the redirect.
const handleContinue = async (): Promise<void> => {
  if (loading.value) return;
  v$.value.identifier.$touch();
  v$.value.password.$touch();
  if (v$.value.identifier.$error || v$.value.password.$error) {
    await nextTick();
    if (v$.value.identifier.$error) {
      await identifierInput.value?.setFocus?.();
    } else {
      await passwordInput.value?.setFocus?.();
    }
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
    resolvedLoginId.value = methods.login_id ?? '';

    // Attempt the password login directly. handleBasicLogin /
    // handleAdvancedLogin catch TOTP_REQUIRED and route to step 2.
    if (authMethods.value.encryption_level === 'advanced') {
      await handleAdvancedLogin({ loginId: resolvedLoginId.value });
    } else {
      await handleBasicLogin({ identifier: credentials.value.identifier.trim() });
    }
  } catch (err) {
    // Step 1 stays enumeration-safe: every lookup failure shows the same
    // message instead of leaking the server error detail.
    console.error('[AuthLoginForm] handleContinue failed', err);
    error.value = 'No pudimos validar tus credenciales. Intentá de nuevo.';
    await nextTick();
    loginError.value?.focus();
  } finally {
    loading.value = false;
  }
};

// "← Cambiar de usuario": start over with a different account.
// Clears the typed identifier and the password, drops the resolved
// account state and returns to the credentials step, so the user can
// type another identifier. The "remember me" toggle stays as set.
const changeUser = async (event?: Event): Promise<void> => {
  event?.preventDefault();
  step.value = 'credentials';
  error.value = '';
  credentials.value.identifier = '';
  credentials.value.password = '';
  authMethods.value = { encryption_level: null, has_passkeys: false };
  resolvedLoginId.value = '';
  masterPassword.value = '';
  masterPasswordVisible.value = false;
  masterPasswordTouched.value = false;
  activeLoginId.value = '';
  twoFactorView.value = null;
  pendingPasskeyToken.value = '';
  // F3: Reset all TOTP challenge state (matching cancelTotpChallenge + missing fields)
  totpToken.value = '';
  totpCode.value = '';
  totpBackupCode.value = '';
  totpError.value = '';
  totpInputMode.value = 'code';
  totpSubmitting.value = false;
  totpRememberMe.value = false;
  totpDigits.value = 6;
  v$.value.$reset();
  await nextTick();
  await identifierInput.value?.setFocus?.();
};

// The "← Cambiar de usuario" button in the card footer calls this to
// reset the form back to the credentials step.
// "¿Olvidaste tu contraseña?" opens the recovery flow.
const goToRecovery = (): void => {
  router.push({ name: 'recovery' });
};

// Step 2 submit router: when the user presses Enter inside the form, fall
// through to whichever primary action is visible. The passkey CTA and the
// TOTP "Verificar" CTA are type="button", so they don't actually fire this
// handler — it exists to keep the form semantically valid.
const handleStep2Submit = async (): Promise<void> => {
  if (masterPasswordVisible.value) {
    await handleMasterPasswordUnlock();
    return;
  }
  if (authMethods.value.has_passkeys && twoFactorView.value !== 'totp') {
    await handlePasskeyLogin();
    return;
  }
  await submitTotpChallenge();
};

// (LBDS-304: cancelUnlock was removed — the master-password unlock
// flow is exited by going back to step 1 via changeUser, not
// via a local "cancel" button. The function was only called from
// the auth-links div that we removed.)

// Post-login redirect shared by the password and passkey flows: OAuth apps
// go through oauthAuthorize, direct logins land on the dashboard.
async function submitTotpChallenge(): Promise<void> {
  if (!totpToken.value || totpSubmitting.value) return;
  // LBDS-8 review (F-07): the backend would reject a request with
  // neither code nor backup_code, but the resulting generic error
  // message degrades the UX. Validate client-side and surface a clear
  // copy when the user hits "Verificar" with an empty field.
  if (totpInputMode.value === 'code' && totpCode.value.length !== totpDigits.value) {
    totpError.value = `Ingresá el código de ${totpDigits.value} dígitos.`;
    return;
  }
  if (totpInputMode.value === 'backup' && totpBackupCode.value.trim().length === 0) {
    totpError.value = 'Ingresá uno de los códigos de respaldo.';
    return;
  }
  totpSubmitting.value = true;
  totpError.value = '';
  try {
    await authController.loginWithTotp({
      totpToken: totpToken.value,
      code: totpInputMode.value === 'code' ? totpCode.value : undefined,
      backupCode: totpInputMode.value === 'backup' ? totpBackupCode.value.trim() : undefined,
      rememberMe: totpRememberMe.value,
    });
    // On success: clear the modal state and continue with the
    // post-login redirect (OAuth apps, dashboard, etc.).
    totpToken.value = '';
    // LBDS-8 review (F-01): the redirect used to be unguarded — if
    // OAuth authorize failed after TOTP succeeded, the modal would
    // close and the user would be left without a session. Catch and
    // surface the failure so the user can retry from a logged-in
    // state.
    try {
      await runPostLoginRedirect();
    } catch (redirectErr) {
      totpError.value = redirectErr instanceof Error
        ? redirectErr.message
        : 'No se pudo redirigir tras el login. Recargá la página.';
    }
  } catch (err) {
    const apiError = err as Error & { code?: string };
    totpError.value = apiError.code === 'TOTP_REQUIRED'
      ? 'La sesión TOTP expiró. Iniciá sesión de nuevo.'
      : (apiError.message || 'Código TOTP inválido.');
    if (apiError.code === 'TOTP_REQUIRED') {
      // Token is spent; close the modal so the user can re-enter
      // their password.
      totpToken.value = '';
    }
  } finally {
    totpSubmitting.value = false;
  }
}

function cancelTotpChallenge(): void {
  totpToken.value = '';
  totpCode.value = '';
  totpBackupCode.value = '';
  totpError.value = '';
  totpInputMode.value = 'code';
  twoFactorView.value = null;
}

// LBDS-304: escape hatch from the TOTP modal. Closes the TOTP challenge
// and triggers the passkey ceremony using the same identifier that the
// password step already resolved, so the user does not have to backtrack
// to step 2 to switch methods.
async function handleUsePasskeyInstead(event?: Event): Promise<void> {
  event?.preventDefault();
  // LBDS-304: keep step 2 mounted but switch its 2FA view to passkey and
  // start the ceremony. The TOTP challenge state is cleared so a stray
  // submission of the TOTP view cannot race the passkey flow.
  cancelTotpChallenge();
  twoFactorView.value = 'passkey';
  await handlePasskeyLogin({ passkeyToken: pendingPasskeyToken.value });
}

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
const handlePasskeyLogin = async (params: { passkeyToken?: string } = {}): Promise<void> => {
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
      passkeyToken: params.passkeyToken,
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
    if (apiError.code === 'auth.email_not_verified') {
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
    const apiError = err as Error & {
      code?: string;
      email?: string;
      totpToken?: string;
      digits?: number;
      period?: number;
      passkeyToken?: string;
    };
    if (apiError.code === 'TOTP_REQUIRED') {
      // Hand the password step's result to step 2 (second factor).
      totpRememberMe.value = rememberMe.value;
      totpDigits.value = apiError.digits ?? 6;
      totpToken.value = apiError.totpToken ?? '';
      // F1: Capture passkey_token when backend issues it for accounts with both TOTP and passkeys
      pendingPasskeyToken.value = apiError.passkeyToken ?? '';
      totpCode.value = '';
      totpBackupCode.value = '';
      totpError.value = '';
      totpInputMode.value = 'code';
      // LBDS-304: step 2 default is passkey when available, TOTP otherwise.
      twoFactorView.value = authMethods.value.has_passkeys ? 'passkey' : 'totp';
      step.value = 'twoFactor';
      return;
    }
    if (apiError.code === 'PASSKEY_REQUIRED') {
      // Password valid, account has passkeys but no TOTP — advance to step 2
      // with the passkey view. Store the passkey_token so the ceremony starts
      // only when the user explicitly clicks the passkey button.
      totpRememberMe.value = rememberMe.value;
      pendingPasskeyToken.value = apiError.passkeyToken ?? '';
      twoFactorView.value = 'passkey';
      step.value = 'twoFactor';
      return;
    }
    if (apiError.code === 'auth.email_not_verified') {
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
    const apiError = err as Error & {
      code?: string;
      email?: string;
      totpToken?: string;
      digits?: number;
      period?: number;
      passkeyToken?: string;
    };
    if (apiError.code === 'TOTP_REQUIRED') {
      totpRememberMe.value = rememberMe.value;
      totpDigits.value = apiError.digits ?? 6;
      totpToken.value = apiError.totpToken ?? '';
      // F1: Capture passkey_token when backend issues it for accounts with both TOTP and passkeys
      pendingPasskeyToken.value = apiError.passkeyToken ?? '';
      totpCode.value = '';
      totpBackupCode.value = '';
      totpError.value = '';
      totpInputMode.value = 'code';
      // LBDS-304: see handleBasicLogin above.
      twoFactorView.value = authMethods.value.has_passkeys ? 'passkey' : 'totp';
      step.value = 'twoFactor';
      return;
    }
    if (apiError.code === 'PASSKEY_REQUIRED') {
      // Password valid, account has passkeys but no TOTP — advance to step 2
      // with the passkey view. Store the passkey_token so the ceremony starts
      // only when the user explicitly clicks the passkey button.
      totpRememberMe.value = rememberMe.value;
      pendingPasskeyToken.value = apiError.passkeyToken ?? '';
      twoFactorView.value = 'passkey';
      step.value = 'twoFactor';
      return;
    }
    if (apiError.code === 'auth.email_not_verified') {
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

// (the "¿Olvidaste tu contraseña?" link is rendered in the card footer,
// right under the form, and navigates to the recovery route.)
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

/* LBDS-304: the two 2FA method buttons (passkey + alternative) sit
   side by side at the same visual level. They stack on narrow viewports
   so each button keeps a tappable hit area. */
.auth-method-buttons {
  display: flex;
  flex-direction: row;
  gap: var(--space-3);
}

.auth-method-btn {
  flex: 1 1 0;
  margin: 0;
}

/* Secondary button: outlined, same height as the gradient primary. */
.auth-method-btn--secondary {
  --background: transparent;
  --background-hover: var(--glass-bg);
  --border-color: var(--glass-border);
  --color: var(--text-primary);
  --border-width: 1.5px;
  --border-style: solid;
  font-weight: 600;
}

@media (max-width: 480px) {
  .auth-method-buttons {
    flex-direction: column;
  }
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
  gap: 0 var(--space-3);
  margin-top: var(--space-2);
}

.auth-card-footer {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-1);
  margin-top: var(--space-2);
}
</style>
