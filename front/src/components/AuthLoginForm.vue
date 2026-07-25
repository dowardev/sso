<!-- SPDX-License-Identifier: MIT -->
<template>
  <AuthCard title="Iniciar Sesión" subtitle="Accede a tu cuenta de SSO Server">
    <form @submit.prevent="handleLogin">
        <ion-item class="input-item" lines="none">
          <ion-input
            v-model="credentials.user"
            label-placement="floating"
            label="Usuario / Email"
            type="text"
            placeholder="Ingresa tu usuario"
            autocomplete="username"
            aria-describedby="login-user-error"
            @ion-blur="v$.user.$touch()"
          />
        </ion-item>
        <div v-if="v$.user.$error" id="login-user-error" class="field-error">
          {{ v$.user.$errors[0].$message }}
        </div>

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
            :disabled="loading"
          >
            <ion-spinner v-if="loading" name="lines"></ion-spinner>
            <span v-else>Ingresar</span>
          </ion-button>
        </div>
      </form>

      <div class="auth-card-footer">
        <ion-button
          v-if="oauthParams.redirect_uri"
          fill="clear"
          expand="block"
          :disabled="loading"
          class="subtle"
          @click="goToRegister"
        >
          Crear cuenta
        </ion-button>

        <ion-button
          fill="clear"
          expand="block"
          :disabled="loading"
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
  } from 'ionicons/icons';
  import useVuelidate from '@vuelidate/core';
  import { required } from '@vuelidate/validators';
  import { useValidation, withMsg } from '@/composables/useValidation';

  const { handleApiError } = useValidation();

  // 2. Controllers
  import { useAuthController } from '@/controllers/authController';

  const authController = useAuthController();

  // 3. Components
  import AuthCard from '@/components/AuthCard.vue';

  // 4. Interface and types imports
  // (none)

  // 5. Main (props, emits, etc.)
  const props = defineProps<{
    isPublic?: boolean;
  }>();

  const router = useRouter();
  const route = useRoute();

  // 6. Vars and const
  const credentials = ref({ user: '', password: '' });
  const loading = ref(false);
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

  // 7. Watch
  // (none)

  // 8. Computed
  const rules = computed(() => ({
    user: { required: withMsg('El usuario o email es requerido', required) },
    password: { required: withMsg('La contraseña es requerida', required) },
  }));

  const v$ = useVuelidate(rules, credentials);

  // 9. OnMounted
  onMounted(async () => {
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

    loading.value = true;
    error.value = '';
    try {
      await authController.login({
        user: credentials.value.user,
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
      error.value = handleApiError(err);
    } finally {
      loading.value = false;
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

@media (max-width: 374px) {
  .auth-card-footer {
    gap: 2px;
  }
}
</style>
