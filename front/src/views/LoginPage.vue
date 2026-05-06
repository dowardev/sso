<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <div class="login-container">
        <div class="login-card">
          <div class="logo-section">
            <img
              alt="SSO Logo"
              src="@/assets/images/lambda_v1.svg"
              class="logo-image"
            />
            <h1 class="login-title">Iniciar Sesión</h1>
            <p class="login-subtitle">Accede a tu cuenta</p>
          </div>

          <form @submit.prevent="handleLogin" class="login-form">
            <ion-item lines="full" class="form-item">
              <ion-input
                v-model="credentials.user"
                label-placement="floating"
                label="Usuario / Email"
                type="text"
                required
                placeholder="Ingresa tu usuario"
              ></ion-input>
            </ion-item>

            <ion-item lines="full" class="form-item">
              <ion-input
                v-model="credentials.password"
                label="Contraseña"
                label-placement="floating"
                type="password"
                required
                placeholder="Ingresa tu contraseña"
              ></ion-input>
            </ion-item>

            <div v-if="error" class="error-message">
              {{ error }}
            </div>

            <div class="ion-padding-top">
              <ion-button
                type="submit"
                expand="block"
                :disabled="loading"
                class="submit-button"
              >
                <ion-spinner v-if="loading" name="lines"></ion-spinner>
                <span v-else>Ingresar</span>
              </ion-button>
            </div>

            <div class="auth-links">
              <ion-button
                v-if="oauthParams.redirect_uri"
                fill="clear"
                expand="block"
                :disabled="loading"
                @click="goToRegister"
              >
                ¿Olvidaste tu contraseña?
              </ion-button>

              <ion-button
                v-if="oauthParams.redirect_uri"
                fill="clear"
                expand="block"
                :disabled="loading"
                @click="goToRegister"
              >
                Crear cuenta
              </ion-button>
            </div>
          </form>
        </div>

        <ion-button class="back-button" fill="clear" size="small" @click="goBack">
          <ion-icon slot="start" :icon="arrowBack"></ion-icon>
          Volver
        </ion-button>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
  import { useRouter, useRoute } from 'vue-router';
  import {
    IonPage,
    IonContent,
    IonCard,
    IonItem,
    IonInput,
    IonButton,
    IonSpinner,
    IonIcon,
  } from '@ionic/vue';
  import { arrowBack } from 'ionicons/icons';
  import { useAuthStore } from '@/stores/auth';
  import { api } from '@/services/api';
  import { dpopService } from '@/services/dpopService';

  const router = useRouter();
  const route = useRoute();
  const authStore = useAuthStore();

  const credentials = ref({
    user: '',
    password: '',
  });

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

  onMounted(() => {
    const { client_id, redirect_uri, state, response_type, code_challenge, code_challenge_method, code_verifier } = route.query;
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
    }
  });

  const handleLogin = async () => {
    loading.value = true;
    error.value = '';

    try {
      await authStore.login({
        user: credentials.value.user,
        password: credentials.value.password,
        client_id: oauthParams.value.client_id || undefined,
      });

      if (isOAuthFlow.value) {
        await handleOAuthAuthorize();
      } else {
        router.replace('/admin/dashboard');
      }
    } catch (err: unknown) {
      if (err instanceof Error) {
        error.value = err.message;
      } else {
        error.value = 'Error al iniciar sesión';
      }
    } finally {
      loading.value = false;
    }
  };

  const handleOAuthAuthorize = async () => {
    try {
      const token = authStore.getToken();
      if (!token) {
        error.value = 'Token no disponible';
        return;
      }

      const ssoUrl =
        import.meta.env.VITE_API_URL || 'http://localhost:8001/api';
      const dpopProof = await dpopService.createDpopProof(
        'POST',
        `${ssoUrl}/oauth/authorize`,
        token
      );
      const publicKeyJwk = dpopService.getPublicKeyJwk();

      const response = await api.post<{ code: string; state: string | null }>(
        '/oauth/authorize',
        {
          client_id: oauthParams.value.client_id,
          redirect_uri: oauthParams.value.redirect_uri,
          state: oauthParams.value.state,
          ...(oauthParams.value.code_challenge
            ? {
                code_challenge: oauthParams.value.code_challenge,
                code_challenge_method: oauthParams.value.code_challenge_method || 'S256',
              }
            : {}),
          ...(oauthParams.value.code_verifier
            ? {
                code_verifier: oauthParams.value.code_verifier,
              }
            : {}),
        },
        {
          DPoP: dpopProof,
          ...(publicKeyJwk
            ? { 'DPoP-Public-Key': JSON.stringify(publicKeyJwk) }
            : {}),
        }
      );

      const params = new URLSearchParams();
      params.set('code', response.code);
      if (response.state) {
        params.set('state', response.state);
      }
      window.location.href = `${oauthParams.value.redirect_uri}?${params.toString()}`;
    } catch (err: unknown) {
      if (err instanceof Error) {
        error.value = err.message;
      } else {
        error.value = 'Error en la autorización';
      }
    }
  };

  const goToRegister = () => {
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
    router.push({ name: 'AdminRegister', query });
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
.login-container {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: var(--gradient-primary);
}

.login-card {
  width: 100%;
  max-width: 400px;
  background: var(--background-contrast);
  border-radius: var(--card-border-radius);
  box-shadow: var(--card-shadow-light) 0px 8px 24px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
  padding: 32px 24px;
}

.logo-section {
  text-align: center;
  margin-bottom: 24px;
}

.logo-image {
  width: 80px;
  height: 80px;
  margin-bottom: 16px;
}

.login-title {
  margin: 0;
  font-size: 24px;
  font-weight: 600;
  color: var(--text-primary);
}

.login-subtitle {
  margin: 8px 0 0 0;
  font-size: 14px;
  color: var(--text-secondary);
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.form-item {
  --background: var(--ion-color-light);
  --border-radius: 8px;
  --padding-start: 12px;
  --padding-end: 12px;
}

.error-message {
  color: var(--ion-color-danger);
  text-align: center;
  margin: 8px 0;
  font-size: 14px;
}

.submit-button {
  --border-radius: 8px;
  height: 48px;
  font-weight: 600;
}

.auth-links {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 8px;
}

.auth-links ion-button {
  --color: var(--ion-color-primary);
  font-size: 14px;
}

.back-button {
  margin-top: 24px;
  --color: white;
}

.back-button ion-icon {
  margin-right: 4px;
}

@media (min-width: 768px) {
  .login-container {
    padding: 48px;
  }

  .login-card {
    padding: 40px 32px;
  }

  .logo-image {
    width: 96px;
    height: 96px;
  }

  .login-title {
    font-size: 28px;
  }
}
</style>