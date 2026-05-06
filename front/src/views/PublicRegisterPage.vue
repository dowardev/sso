<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <div class="register-container">
        <div class="register-card">
          <div class="logo-section">
            <img
              alt="SSO Logo"
              src="@/assets/images/lambda_v1.svg"
              class="logo-image"
            />
            <h1 class="register-title">Crear Cuenta</h1>
            <p class="register-subtitle">Regístrate para acceder</p>
          </div>

          <form @submit.prevent="handleRegister" class="register-form">
            <ion-item lines="full" class="form-item">
              <ion-input
                v-model="form.name"
                label="Nombre"
                label-placement="floating"
                type="text"
                required
                placeholder="Ingresa tu nombre"
              ></ion-input>
            </ion-item>

            <ion-item lines="full" class="form-item">
              <ion-input
                v-model="form.user"
                label="Usuario"
                label-placement="floating"
                type="text"
                required
                placeholder="Ingresa tu usuario"
              ></ion-input>
            </ion-item>

            <ion-item lines="full" class="form-item">
              <ion-input
                v-model="form.email"
                label="Email"
                label-placement="floating"
                type="email"
                required
                placeholder="Ingresa tu email"
              ></ion-input>
            </ion-item>

            <ion-item lines="full" class="form-item">
              <ion-input
                v-model="form.password"
                label="Contraseña"
                label-placement="floating"
                type="password"
                required
                placeholder="Ingresa tu contraseña"
              ></ion-input>
            </ion-item>

            <ion-item lines="full" class="form-item">
              <ion-input
                v-model="form.password_confirmation"
                label="Confirmar contraseña"
                label-placement="floating"
                type="password"
                required
                placeholder="Confirma tu contraseña"
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
                <span v-else>Registrar</span>
              </ion-button>
            </div>

            <div class="auth-links">
              <ion-button
                fill="clear"
                expand="block"
                :disabled="loading"
                @click="goToLogin"
              >
                Ya tengo cuenta
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
    IonItem,
    IonInput,
    IonButton,
    IonSpinner,
    IonIcon,
  } from '@ionic/vue';
  import { arrowBack } from 'ionicons/icons';
  import { api } from '@/services/api';
  import { useAuthStore } from '@/stores/auth';
  import { dpopService } from '@/services/dpopService';

  const router = useRouter();
  const route = useRoute();
  const authStore = useAuthStore();

  interface ApiValidationError {
    errors: Record<string, string[]>;
  }

  const form = ref({
    name: '',
    user: '',
    email: '',
    password: '',
    password_confirmation: '',
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

  const handleRegister = async (): Promise<void> => {
    loading.value = true;
    error.value = '';

    try {
      const data: Record<string, unknown> = {
        name: form.value.name,
        user: form.value.user,
        email: form.value.email,
        password: form.value.password,
        password_confirmation: form.value.password_confirmation,
        client_id: oauthParams.value.client_id || undefined,
      };

      await api.post('/register', data);

      if (isOAuthFlow.value) {
        await authStore.login({
          user: form.value.email,
          password: form.value.password,
          client_id: oauthParams.value.client_id || undefined,
        });

        await handleOAuthAuthorize();
      } else {
        router.replace({ name: 'AuthLogin', query: { registered: 'true' } });
      }
    } catch (err: unknown) {
      if (
        err instanceof Error &&
        'errors' in err &&
        typeof (err as unknown as ApiValidationError).errors === 'object'
      ) {
        const validationErr = err as unknown as ApiValidationError;
        const messages = Object.values(validationErr.errors).flat().join(', ');
        error.value = messages;
      } else if (err instanceof Error) {
        error.value = err.message;
      } else {
        error.value = 'Error al registrar';
      }
    } finally {
      loading.value = false;
    }
  };

  const handleOAuthAuthorize = async (): Promise<void> => {
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
                code_challenge_method: oauthParams.value.code_challenge_method,
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

  const goToLogin = (): void => {
    const query: Record<string, string> = {};
    if (isOAuthFlow.value) {
      query.client_id = oauthParams.value.client_id;
      query.redirect_uri = oauthParams.value.redirect_uri;
      query.response_type = oauthParams.value.response_type || 'code';
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
    }
    router.push({ name: 'AuthLogin', query });
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
.register-container {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: var(--gradient-primary);
}

.register-card {
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

.register-title {
  margin: 0;
  font-size: 24px;
  font-weight: 600;
  color: var(--text-primary);
}

.register-subtitle {
  margin: 8px 0 0 0;
  font-size: 14px;
  color: var(--text-secondary);
}

.register-form {
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
  .register-container {
    padding: 48px;
  }

  .register-card {
    padding: 40px 32px;
  }

  .logo-image {
    width: 96px;
    height: 96px;
  }

  .register-title {
    font-size: 28px;
  }
}
</style>