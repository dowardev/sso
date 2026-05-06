<template>
  <ion-page>
    <ion-content :fullscreen="true" class="register-content">
      <div class="register-container">
        <div class="register-header">
          <h1>SSO Admin</h1>
          <p>Crear cuenta</p>
        </div>

        <form class="register-form" @submit.prevent="handleRegister">
          <ion-item class="form-item">
            <ion-label position="stacked">Nombre</ion-label>
            <ion-input
              v-model="form.name"
              type="text"
              required
              placeholder="Ingresa tu nombre"
            ></ion-input>
          </ion-item>

          <ion-item class="form-item">
            <ion-label position="stacked">Usuario</ion-label>
            <ion-input
              v-model="form.user"
              type="text"
              required
              placeholder="Ingresa tu usuario"
            ></ion-input>
          </ion-item>

          <ion-item class="form-item">
            <ion-label position="stacked">Email</ion-label>
            <ion-input
              v-model="form.email"
              type="email"
              required
              placeholder="Ingresa tu email"
            ></ion-input>
          </ion-item>

          <ion-item class="form-item">
            <ion-label position="stacked">Contraseña</ion-label>
            <ion-input
              v-model="form.password"
              type="password"
              required
              placeholder="Ingresa tu contraseña"
            ></ion-input>
          </ion-item>

          <ion-item class="form-item">
            <ion-label position="stacked">Confirmar contraseña</ion-label>
            <ion-input
              v-model="form.password_confirmation"
              type="password"
              required
              placeholder="Confirma tu contraseña"
            ></ion-input>
          </ion-item>

          <div v-if="error" class="error-message">
            {{ error }}
          </div>

          <ion-button
            type="submit"
            expand="block"
            :disabled="loading"
            class="submit-button"
          >
            <ion-spinner v-if="loading" name="lines"></ion-spinner>
            <span v-else>Registrar</span>
          </ion-button>

          <ion-button
            fill="clear"
            expand="block"
            :disabled="loading"
            class="login-link"
            @click="goToLogin"
          >
            Ya tengo cuenta
          </ion-button>
        </form>
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
    IonLabel,
    IonInput,
    IonButton,
    IonSpinner,
  } from '@ionic/vue';
  import { api } from '@/services/api';

  const router = useRouter();
  const route = useRoute();

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

  const handleRegister = async () => {
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
        const loginResponse = await api.post<{ token: string; user: any }>(
          '/login',
          {
            user: form.value.email,
            password: form.value.password,
            client_id: oauthParams.value.client_id,
          }
        );

        localStorage.setItem('token', loginResponse.token);
        localStorage.setItem('user', JSON.stringify(loginResponse.user));

        await handleOAuthAuthorize();
      }
    } catch (err: any) {
      const message = err?.message || 'Error al registrar';
      if (typeof err === 'object' && err.errors) {
        const messages = Object.values(err.errors).flat().join(', ');
        error.value = messages;
      } else {
        error.value = message;
      }
    } finally {
      loading.value = false;
    }
  };

  const handleOAuthAuthorize = async () => {
    try {
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
        }
      );

      const params = new URLSearchParams();
      params.set('code', response.code);
      if (response.state) {
        params.set('state', response.state);
      }
      window.location.href = `${oauthParams.value.redirect_uri}?${params.toString()}`;
    } catch (err: any) {
      error.value = err.message || 'Error en la autorización';
    }
  };

  const goToLogin = () => {
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
    }
    router.push({ name: 'AdminLogin', query });
  };
</script>

<style scoped>
.register-content {
  --background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
}

.register-container {
  width: 100%;
  max-width: 400px;
  padding: 24px;
}

.register-header {
  text-align: center;
  color: white;
  margin-bottom: 32px;
}

.register-header h1 {
  margin: 0;
  font-size: 32px;
  font-weight: 700;
}

.register-header p {
  margin: 8px 0 0;
  opacity: 0.9;
}

.register-form {
  background: var(--background-contrast);
  border-radius: var(--card-border-radius);
  padding: 24px;
  box-shadow: var(--card-shadow-light) 0px 8px 24px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
}

.form-item {
  --background: var(--ion-color-light);
  --border-radius: 8px;
  --padding-start: 12px;
  --padding-end: 12px;
  margin-bottom: 8px;
}

.submit-button {
  margin-top: 16px;
  --border-radius: 8px;
  height: 48px;
  font-weight: 600;
}

.login-link {
  margin-top: 8px;
  --color: var(--ion-color-primary);
}

.error-message {
  color: var(--ion-color-danger);
  text-align: center;
  margin: 8px 0;
  font-size: 14px;
}

@media (min-width: 768px) {
  .register-container {
    padding: 48px;
  }

  .register-form {
    padding: 32px;
  }

  .register-header h1 {
    font-size: 40px;
  }
}
</style>