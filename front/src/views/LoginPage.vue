<template>
  <ion-page>
    <ion-content :fullscreen="true" class="login-content">
      <div class="login-container">
        <div class="login-header">
          <h1>SSO Admin</h1>
          <p>Ingresa tus credenciales</p>
        </div>

        <form class="login-form" @submit.prevent="handleLogin">
          <ion-item>
            <ion-label position="stacked">Usuario / Email</ion-label>
            <ion-input
              v-model="credentials.user"
              type="text"
              required
              placeholder="Ingresa tu usuario"
            ></ion-input>
          </ion-item>

          <ion-item>
            <ion-label position="stacked">Contraseña</ion-label>
            <ion-input
              v-model="credentials.password"
              type="password"
              required
              placeholder="Ingresa tu contraseña"
            ></ion-input>
          </ion-item>

          <div v-if="error" class="error-message">
            {{ error }}
          </div>

          <ion-button
            type="submit"
            expand="block"
            :disabled="loading"
            class="login-button"
          >
            <ion-spinner v-if="loading" name="lines"></ion-spinner>
            <span v-else>Ingresar</span>
          </ion-button>

          <ion-button
            v-if="oauthParams.redirect_uri"
            fill="clear"
            expand="block"
            :disabled="loading"
            class="register-link"
            @click="goToRegister"
          >
            Crear cuenta
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
  import { auth } from '@/services/auth';
  import { api } from '@/services/api';

  const router = useRouter();
  const route = useRoute();

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
  });

  const isOAuthFlow = ref(false);

  onMounted(() => {
    const { client_id, redirect_uri, state, response_type } = route.query;
    if (client_id && redirect_uri) {
      isOAuthFlow.value = true;
      oauthParams.value = {
        client_id: client_id as string,
        redirect_uri: redirect_uri as string,
        state: (state as string) || '',
        response_type: (response_type as string) || 'code',
      };
    }
  });

  const handleLogin = async () => {
    loading.value = true;
    error.value = '';

    try {
      await auth.login({
        user: credentials.value.user,
        password: credentials.value.password,
        client_id: oauthParams.value.client_id || undefined,
      });

      if (isOAuthFlow.value) {
        await handleOAuthAuthorize();
      } else {
        router.replace('/admin/dashboard');
      }
    } catch (err: any) {
      error.value = err.message || 'Error al iniciar sesión';
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

  const goToRegister = () => {
    const params = new URLSearchParams();
    params.set('client_id', oauthParams.value.client_id);
    params.set('redirect_uri', oauthParams.value.redirect_uri);
    if (oauthParams.value.state) {
      params.set('state', oauthParams.value.state);
    }
    params.set('response_type', oauthParams.value.response_type || 'code');
    router.push(`/register?${params.toString()}`);
  };
</script>

<style scoped>
  .login-content {
    --background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .login-container {
    width: 100%;
    max-width: 400px;
    padding: 24px;
  }

  .login-header {
    text-align: center;
    color: white;
    margin-bottom: 32px;
  }

  .login-header h1 {
    margin: 0;
    font-size: 32px;
    font-weight: bold;
  }

  .login-header p {
    margin: 8px 0 0;
    opacity: 0.9;
  }

  .login-form {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
  }

  .login-form ion-item {
    --background: transparent;
    margin-bottom: 16px;
  }

  .login-button {
    margin-top: 16px;
    --border-radius: 8px;
  }

  .register-link {
    margin-top: 8px;
  }

  .error-message {
    color: var(--ion-color-danger);
    text-align: center;
    margin: 8px 0;
    font-size: 14px;
  }
</style>
