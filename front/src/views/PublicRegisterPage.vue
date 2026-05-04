<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <ion-grid class="login-grid">
        <ion-row class="ion-justify-content-center">
          <ion-col size="12" size-sm="10" size-md="8" size-lg="6" size-xl="4">
            <ion-card class="shadow1">
              <img
                alt="Silhouette of mountains"
                src="@/assets/images/lambda_v1.svg"
              />

              <ion-card-header>
                <ion-card-title>Crear Cuenta</ion-card-title>
                <ion-card-subtitle> Regístrate para acceder </ion-card-subtitle>
              </ion-card-header>

              <ion-card-content>
                <div>
                  <form @submit.prevent="handleRegister">
                    <ion-item lines="full">
                      <ion-input
                        v-model="form.name"
                        label="Nombre"
                        label-placement="floating"
                        type="text"
                        required
                        placeholder="Ingresa tu nombre"
                      ></ion-input>
                    </ion-item>

                    <ion-item lines="full">
                      <ion-input
                        v-model="form.user"
                        label="Usuario"
                        label-placement="floating"
                        type="text"
                        required
                        placeholder="Ingresa tu usuario"
                      ></ion-input>
                    </ion-item>

                    <ion-item lines="full">
                      <ion-input
                        v-model="form.email"
                        label="Email"
                        label-placement="floating"
                        type="email"
                        required
                        placeholder="Ingresa tu email"
                      ></ion-input>
                    </ion-item>

                    <ion-item lines="full">
                      <ion-input
                        v-model="form.password"
                        label="Contraseña"
                        label-placement="floating"
                        type="password"
                        required
                        placeholder="Ingresa tu contraseña"
                      ></ion-input>
                    </ion-item>

                    <ion-item lines="full">
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
                        class="no-shadow"
                      >
                        <ion-spinner v-if="loading" name="lines"></ion-spinner>
                        <span v-else>Registrar</span>
                      </ion-button>
                    </div>

                    <section>
                      <ion-button
                        fill="clear"
                        expand="block"
                        :disabled="loading"
                        @click="goToLogin"
                      >
                        Ya tengo cuenta
                      </ion-button>
                    </section>
                  </form>
                </div>
              </ion-card-content>
            </ion-card>

            <ion-button expand="full" fill="clear" size="small" @click="goBack">
              <ion-icon slot="start" :icon="arrowBack"></ion-icon>
              Volver
            </ion-button>
          </ion-col>
        </ion-row>
      </ion-grid>
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
  import { auth } from '@/services/auth';
  import { dpopService } from '@/services/dpopService';

  const router = useRouter();
  const route = useRoute();

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
        interface LoginResponse {
          token: string;
          user: Record<string, unknown>;
        }

        const loginResponse = await api.post<LoginResponse>('/login', {
          user: form.value.email,
          password: form.value.password,
          client_id: oauthParams.value.client_id,
        });

        localStorage.setItem('token', loginResponse.token);
        localStorage.setItem('user', JSON.stringify(loginResponse.user));

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
      const token = auth.getToken();
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
  .login-grid {
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    justify-content: center;
  }

  .error-message {
    color: #e74c3c;
    text-align: center;
    margin: 8px 0;
    font-size: 14px;
  }
</style>
