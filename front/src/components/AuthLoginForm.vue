<template>
  <div class="auth-screen">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>

    <div class="auth-card">
      <div class="auth-card-header">
        <div class="auth-logo-circle">
          <img alt="Logo SSO Server" src="@/assets/images/lambda_v1.svg" class="auth-logo" />
        </div>
        <h2>Iniciar Sesión</h2>
        <p>Accede a tu cuenta de SSO Server</p>
      </div>

      <form @submit.prevent="handleLogin">
        <ion-item class="input-item" lines="none">
          <ion-input
            v-model="credentials.user"
            label-placement="floating"
            label="Usuario / Email"
            type="text"
            required
            placeholder="Ingresa tu usuario"
          />
        </ion-item>

        <ion-item class="input-item" lines="none">
          <ion-input
            v-model="credentials.password"
            label="Contraseña"
            label-placement="floating"
            type="password"
            required
            placeholder="Ingresa tu contraseña"
          >
            <ion-input-password-toggle slot="end" />
          </ion-input>
        </ion-item>

        <ion-item class="input-item remember-item" lines="none">
          <ion-toggle
            v-model="rememberMe"
            slot="start"
            color="primary"
          />
          <ion-label class="remember-label">Mantener sesión abierta</ion-label>
        </ion-item>

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
          <ion-icon slot="start" :icon="arrowBack"></ion-icon>
          Volver
        </ion-button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
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

  import { useAuthController } from '@/controllers/authController';
  const authController = useAuthController();

  const router = useRouter();
  const route = useRoute();

  const props = defineProps<{
    isPublic?: boolean;
  }>();

  const { isPublic } = props;

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

      if (isPublic && authController.isAuthenticated.value) {
        loading.value = true;
        try {
          await authController.oauthAuthorize({
            client_id: oauthParams.value.client_id,
            redirect_uri: oauthParams.value.redirect_uri,
            state: oauthParams.value.state,
            response_type: oauthParams.value.response_type || 'code',
            code_challenge: oauthParams.value.code_challenge || undefined,
            code_challenge_method:
              oauthParams.value.code_challenge_method || undefined,
            code_verifier: oauthParams.value.code_verifier || undefined,
          });
          return;
        } catch {
          loading.value = false;
        }
      }
    }
  });

  const handleLogin = async (): Promise<void> => {
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
        await authController.oauthAuthorize({
          client_id: oauthParams.value.client_id,
          redirect_uri: oauthParams.value.redirect_uri,
          state: oauthParams.value.state,
          response_type: oauthParams.value.response_type || 'code',
          code_challenge: oauthParams.value.code_challenge || undefined,
          code_challenge_method:
            oauthParams.value.code_challenge_method || undefined,
          code_verifier: oauthParams.value.code_verifier || undefined,
        });
      } else {
        router.replace(isPublic ? '/' : '/admin/dashboard');
      }
    } catch (err: unknown) {
      if (err instanceof Error) {
        error.value = err.message;
      } else {
        error.value = 'Error al iniciar sesi&oacute;n';
      }
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
.auth-screen {
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  background: var(--ion-background-color, #0A0F1D);
  padding: 24px;
}

.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.5;
  pointer-events: none;
  will-change: transform;
}

.blob-1 {
  width: 420px;
  height: 420px;
  background: #8B5CF6;
  top: -10%;
  left: -5%;
  animation: blob-float-1 18s ease-in-out infinite;
}

.blob-2 {
  width: 350px;
  height: 350px;
  background: #06B6D4;
  bottom: -10%;
  right: -5%;
  animation: blob-float-2 22s ease-in-out infinite;
}

.blob-3 {
  width: 280px;
  height: 280px;
  background: #A78BFA;
  top: 50%;
  left: 60%;
  animation: blob-float-3 20s ease-in-out infinite;
}

@keyframes blob-float-1 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(60px, 40px) scale(1.08); }
  66% { transform: translate(-30px, 70px) scale(0.95); }
}

@keyframes blob-float-2 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(-50px, -60px) scale(1.1); }
  66% { transform: translate(40px, -30px) scale(0.92); }
}

@keyframes blob-float-3 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(30px, -50px) scale(1.05); }
  66% { transform: translate(-60px, 20px) scale(0.97); }
}

.auth-card {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 400px;
  padding: 40px 32px;
  background: var(--glass-bg);
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: 20px;
  animation: card-appear 0.6s ease both;
}

@keyframes card-appear {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.auth-card-header {
  text-align: center;
  margin-bottom: 32px;
}

.auth-logo-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
  box-shadow: 0 4px 20px rgba(var(--gradient-primary-rgb), 0.3);
}

.auth-logo {
  max-width: 32px;
  height: auto;
  filter: brightness(0) invert(1);
}

.auth-card-header h2 {
  font-family: var(--font-heading);
  font-size: 24px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0 0 6px 0;
}

.auth-card-header p {
  font-size: 14px;
  color: var(--text-tertiary);
  margin: 0;
}

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

@media (max-width: 374px) {
  .auth-card {
    padding: 24px 16px;
  }

  .auth-card-header h2 {
    font-size: 20px;
  }
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

@media (prefers-reduced-motion: reduce) {
  .blob {
    animation: none;
  }

  .auth-card {
    animation: none;
  }
}
</style>
