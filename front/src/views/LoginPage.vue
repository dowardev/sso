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
                <ion-card-title>Iniciar Sesión</ion-card-title>
                <ion-card-subtitle> Accede a tu cuenta </ion-card-subtitle>
              </ion-card-header>

              <ion-card-content>
                <div>
                  <form @submit.prevent="handleLogin">
                    <ion-item lines="full">
                      <ion-input
                        v-model="credentials.user"
                        label-placement="floating"
                        label="Usuario / Email"
                        type="text"
                        required
                        placeholder="Ingresa tu usuario"
                      ></ion-input>
                    </ion-item>

                    <ion-item lines="full">
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
                        class="no-shadow"
                      >
                        <ion-spinner v-if="loading" name="lines"></ion-spinner>
                        <span v-else>Ingresar</span>
                      </ion-button>
                    </div>

                    <section>
                      <ion-button
                        v-if="oauthParams.redirect_uri"
                        fill="clear"
                        expand="block"
                        :disabled="loading"
                        @click="goToRegister"
                      >
                        Crear cuenta
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
  // imports
  import { ref, onMounted } from 'vue';
  import { useRouter, useRoute } from 'vue-router';
  import {
    IonPage,
    IonContent,
    IonGrid,
    IonRow,
    IonCol,
    IonCard,
    IonCardHeader,
    IonCardTitle,
    IonCardSubtitle,
    IonCardContent,
    IonItem,
    IonInput,
    IonButton,
    IonSpinner,
    IonIcon,
  } from '@ionic/vue';
  import { arrowBack } from 'ionicons/icons';

  // controllers
  import { useAuthController } from '@/controllers/authController';
  const authController = useAuthController();

  // components
  // end components

  // interface and types imports
  // end interface and types imports

  // main
  const router = useRouter();
  const route = useRoute();
  // end main

  // vars and const
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
  // end vars and const

  // watch
  // end

  // computed
  // end computed

  // onmounted
  onMounted(() => {
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
    }
  });
  // end onmounted

  // functions
  const handleLogin = async (): Promise<void> => {
    loading.value = true;
    error.value = '';

    try {
      await authController.login({
        user: credentials.value.user,
        password: credentials.value.password,
        client_id: oauthParams.value.client_id || undefined,
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

  const goToRegister = (): void => {
    const params = new URLSearchParams();
    params.set('client_id', oauthParams.value.client_id);
    params.set('redirect_uri', oauthParams.value.redirect_uri);
    if (oauthParams.value.state) {
      params.set('state', oauthParams.value.state);
    }
    params.set('response_type', oauthParams.value.response_type || 'code');
    if (oauthParams.value.code_challenge) {
      params.set('code_challenge', oauthParams.value.code_challenge);
      params.set(
        'code_challenge_method',
        oauthParams.value.code_challenge_method
      );
    }
    if (oauthParams.value.code_verifier) {
      params.set('code_verifier', oauthParams.value.code_verifier);
    }
    router.push(`/register?${params.toString()}`);
  };

  const goBack = (): void => {
    if (window.history.length > 1) {
      router.back();
    } else {
      router.replace('/');
    }
  };
  // end functions
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
