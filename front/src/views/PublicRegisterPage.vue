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
interface ApiValidationError {
  errors: Record<string, string[]>;
}
// end interface and types imports

// main
const router = useRouter();
const route = useRoute();
// end main

// vars and const
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
// end vars and const

// watch
// end

// computed
// end computed

// onmounted
onMounted(async () => {
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

    if (authController.isAuthenticated.value) {
      loading.value = true;
      try {
        await authController.oauthAuthorize({
          client_id: oauthParams.value.client_id,
          redirect_uri: oauthParams.value.redirect_uri,
          state: oauthParams.value.state,
          response_type: oauthParams.value.response_type || 'code',
          code_challenge: oauthParams.value.code_challenge || undefined,
          code_challenge_method: oauthParams.value.code_challenge_method || undefined,
          code_verifier: oauthParams.value.code_verifier || undefined,
        });
        return;
      } catch {
        loading.value = false;
      }
    }
  }
});
// end onmounted

// functions
const handleRegister = async (): Promise<void> => {
  loading.value = true;
  error.value = '';

  try {
    await authController.register({
      name: form.value.name,
      user: form.value.user,
      email: form.value.email,
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
      client_id: oauthParams.value.client_id || undefined,
    });

    if (!oauthParams.value.client_id) {
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