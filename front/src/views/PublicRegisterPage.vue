<template>
  <ion-page>
    <ion-content :fullscreen="true" class="login-content">
      <div class="login-container">
        <div class="login-header">
          <h1>Crear Cuenta</h1>
          <p>Regístrate para acceder</p>
        </div>

        <form @submit.prevent="handleRegister" class="login-form">
          <ion-item>
            <ion-label position="stacked">Nombre</ion-label>
            <ion-input
              v-model="form.name"
              type="text"
              required
              placeholder="Ingresa tu nombre"
            ></ion-input>
          </ion-item>

          <ion-item>
            <ion-label position="stacked">Usuario</ion-label>
            <ion-input
              v-model="form.user"
              type="text"
              required
              placeholder="Ingresa tu usuario"
            ></ion-input>
          </ion-item>

          <ion-item>
            <ion-label position="stacked">Email</ion-label>
            <ion-input
              v-model="form.email"
              type="email"
              required
              placeholder="Ingresa tu email"
            ></ion-input>
          </ion-item>

          <ion-item>
            <ion-label position="stacked">Contraseña</ion-label>
            <ion-input
              v-model="form.password"
              type="password"
              required
              placeholder="Ingresa tu contraseña"
            ></ion-input>
          </ion-item>

          <ion-item>
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
            class="login-button"
          >
            <ion-spinner v-if="loading" name="lines"></ion-spinner>
            <span v-else>Registrar</span>
          </ion-button>

          <ion-button
            fill="clear"
            expand="block"
            :disabled="loading"
            @click="goToLogin"
            class="register-link"
          >
            Ya tengo cuenta
          </ion-button>
        </form>

        <div class="back-link">
          <ion-button fill="clear" size="small" @click="goBack">
            <ion-icon slot="start" :icon="arrowBack"></ion-icon>
            Volver
          </ion-button>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { IonPage, IonContent, IonItem, IonLabel, IonInput, IonButton, IonSpinner, IonIcon } from '@ionic/vue';
import { arrowBack } from 'ionicons/icons';
import { api } from '@/services/api';
import { auth } from '@/services/auth';
import { dpopService } from '@/services/dpopService';

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
      const loginResponse = await api.post<{ token: string; user: any }>('/login', {
        user: form.value.email,
        password: form.value.password,
        client_id: oauthParams.value.client_id,
      });

      localStorage.setItem('token', loginResponse.token);
      localStorage.setItem('user', JSON.stringify(loginResponse.user));

      await handleOAuthAuthorize();
    } else {
      router.replace('/login?registered=true');
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
    const token = auth.getToken();
    if (!token) {
      error.value = 'Token no disponible';
      return;
    }

    const ssoUrl = import.meta.env.VITE_API_URL || 'http://localhost:8001/api';
    const dpopProof = await dpopService.createDpopProof('POST', `${ssoUrl}/oauth/authorize`, token);
    const publicKeyJwk = dpopService.getPublicKeyJwk();

    const response = await api.post<{ code: string; state: string | null }>(
      '/oauth/authorize',
      {
        client_id: oauthParams.value.client_id,
        redirect_uri: oauthParams.value.redirect_uri,
        state: oauthParams.value.state,
      },
      {
        DPoP: dpopProof,
        ...(publicKeyJwk ? { 'DPoP-Public-Key': JSON.stringify(publicKeyJwk) } : {}),
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
  if (isOAuthFlow.value) {
    const params = new URLSearchParams();
    params.set('client_id', oauthParams.value.client_id);
    params.set('redirect_uri', oauthParams.value.redirect_uri);
    if (oauthParams.value.state) {
      params.set('state', oauthParams.value.state);
    }
    params.set('response_type', oauthParams.value.response_type || 'code');
    router.push(`/login?${params.toString()}`);
  } else {
    router.push('/login');
  }
};

const goBack = () => {
  if (window.history.length > 1) {
    router.back();
  } else {
    router.replace('/');
  }
};
</script>

<style scoped>
.login-content {
  --background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
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
  font-size: 28px;
  font-weight: 600;
}

.login-header p {
  margin: 8px 0 0;
  opacity: 0.8;
  font-size: 14px;
}

.login-form {
  background: rgba(255, 255, 255, 0.95);
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
}

.login-form ion-item {
  --background: transparent;
  margin-bottom: 8px;
  --border-radius: 8px;
}

.login-button {
  margin-top: 16px;
  --border-radius: 8px;
  --background: #0f3460;
  --background-activated: #16213e;
}

.register-link {
  margin-top: 8px;
  color: #0f3460;
}

.error-message {
  color: #e74c3c;
  text-align: center;
  margin: 8px 0;
  font-size: 14px;
}

.back-link {
  margin-top: 24px;
  text-align: center;
}

.back-link ion-button {
  color: rgba(255, 255, 255, 0.7);
}
</style>