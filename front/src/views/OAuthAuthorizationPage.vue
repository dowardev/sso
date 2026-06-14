<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <div class="auth-screen">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>

        <div class="auth-card">
          <div class="auth-card-header">
            <div class="auth-logo-circle">
              <img alt="Logo SSO Server" src="@/assets/images/lambda_v1.svg" class="auth-logo" />
            </div>
            <h2>Autorización Requerida</h2>
            <p>
              La aplicación <strong>{{ clientName }}</strong> solicita acceder a tu
              información
            </p>
          </div>

          <div v-if="!approved" class="data-access-section">
            <h3>Información que se compartirá:</h3>
            <ion-list class="data-list">
              <ion-item lines="none" class="data-item">
                <ion-icon slot="start" :icon="checkmarkCircle" color="primary" />
                <ion-label>Nombre completo</ion-label>
              </ion-item>
              <ion-item lines="none" class="data-item">
                <ion-icon slot="start" :icon="checkmarkCircle" color="primary" />
                <ion-label>Correo electrónico</ion-label>
              </ion-item>
              <ion-item lines="none" class="data-item">
                <ion-icon slot="start" :icon="checkmarkCircle" color="primary" />
                <ion-label>Nombre de usuario</ion-label>
              </ion-item>
            </ion-list>
            <p class="data-note">
              Esta autorización será recordada para futuros accesos de esta
              aplicación.
            </p>
          </div>

          <div v-if="error" class="error-message" role="alert" aria-live="assertive">
            <ion-icon :icon="alertCircle" size="small"></ion-icon>
            {{ error }}
          </div>

          <div class="ion-padding-top action-buttons">
            <ion-button
              expand="block"
              class="gradient-btn"
              :disabled="loading"
              @click="handleApprove"
            >
              <ion-spinner v-if="loading" name="lines"></ion-spinner>
              <span v-else>Permitir y Continuar</span>
            </ion-button>
            <ion-button
              expand="block"
              fill="outline"
              :disabled="loading"
              class="deny-btn"
              @click="handleDeny"
            >
              Denegar
            </ion-button>
          </div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
  import { useRoute, useRouter } from 'vue-router';
  import {
    IonPage,
    IonContent,
    IonButton,
    IonSpinner,
    IonList,
    IonItem,
    IonLabel,
    IonIcon,
  } from '@ionic/vue';
  import { checkmarkCircle, alertCircle } from 'ionicons/icons';

  import { useAuthController } from '@/controllers/authController';
  import { useValidation } from '@/composables/useValidation';
  const authController = useAuthController();
  const { handleApiError } = useValidation();

  const route = useRoute();
  const router = useRouter();

  const loading = ref(false);
  const error = ref('');
  const approved = ref(false);
  const clientName = ref('');

  const oauthParams = ref({
    client_id: '',
    redirect_uri: '',
    state: '',
    response_type: '',
    code_challenge: '',
    code_challenge_method: '',
    code_verifier: '',
  });

  onMounted(async () => {
    const {
      client_id,
      redirect_uri,
      state,
      response_type,
      code_challenge,
      code_challenge_method,
      code_verifier,
      client_name,
    } = route.query;

    if (client_name) {
      clientName.value = client_name as string;
    }

    if (!client_id || !redirect_uri) {
      error.value = 'Parámetros de autorización inválidos';
      return;
    }

    oauthParams.value = {
      client_id: client_id as string,
      redirect_uri: redirect_uri as string,
      state: (state as string) || '',
      response_type: (response_type as string) || 'code',
      code_challenge: (code_challenge as string) || '',
      code_challenge_method: (code_challenge_method as string) || '',
      code_verifier: (code_verifier as string) || '',
    };
  });

  async function handleApprove(): Promise<void> {
    if (!oauthParams.value.client_id) {
      error.value = 'Parámetros de autorización inválidos';
      return;
    }

    loading.value = true;
    error.value = '';

    try {
      await authController.approveOAuthAuthorization({
        client_id: oauthParams.value.client_id,
        redirect_uri: oauthParams.value.redirect_uri,
        state: oauthParams.value.state,
        response_type: oauthParams.value.response_type || 'code',
        code_challenge: oauthParams.value.code_challenge || undefined,
        code_challenge_method:
          oauthParams.value.code_challenge_method || undefined,
        code_verifier: oauthParams.value.code_verifier || undefined,
      });
      approved.value = true;
    } catch (err: unknown) {
      console.error('[Authorization Error]', err);
      error.value = handleApiError(err);
    } finally {
      loading.value = false;
    }
  }

  async function handleDeny(): Promise<void> {
    loading.value = true;
    error.value = '';

    try {
      await authController.denyOAuthAuthorization({
        client_id: oauthParams.value.client_id,
      });

      const redirectUri = oauthParams.value.redirect_uri;
      if (redirectUri) {
        const urlParams = new URLSearchParams();
        urlParams.set('error', 'access_denied');
        if (oauthParams.value.state) {
          urlParams.set('state', oauthParams.value.state);
        }
        window.location.href = `${redirectUri}?${urlParams.toString()}`;
      } else {
        router.replace('/');
      }
    } catch (err: unknown) {
      console.error('[Deny Error]', err);
      error.value = handleApiError(err);
    } finally {
      loading.value = false;
    }
  }
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
  max-width: 420px;
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
  margin-bottom: 24px;
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
  margin: 0 0 8px 0;
}

.auth-card-header p {
  font-size: 14px;
  color: var(--text-tertiary);
  margin: 0;
  line-height: 1.5;
}

.data-access-section {
  margin: 16px 0;
}

.data-access-section h3 {
  font-size: 15px;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0 0 8px 0;
}

.data-list {
  background: transparent;
  padding: 0;
}

.data-item {
  --background: transparent;
  --padding-start: 0;
  --inner-padding-end: 0;
  font-size: 14px;
  color: var(--text-secondary);
}

.data-item ion-icon {
  font-size: 20px;
  margin-right: 8px;
}

.data-note {
  font-size: 13px;
  color: var(--text-tertiary);
  margin: 12px 0 0 0;
  font-style: italic;
}

.action-buttons {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.deny-btn {
  --border-color: var(--text-tertiary);
  --color: var(--text-tertiary);
}

.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 8px 0;
}

@media (max-width: 374px) {
  .auth-card {
    padding: 24px 16px;
  }

  .auth-card-header h2 {
    font-size: 20px;
  }
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
