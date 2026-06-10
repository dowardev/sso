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
            <h2>Crear Cuenta</h2>
            <p>Regístrate para acceder</p>
          </div>

          <form @submit.prevent="handleRegister">
            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.name"
                label="Nombre"
                label-placement="floating"
                type="text"
                placeholder="Ingresa tu nombre"
                @ion-blur="v$.name.$touch()"
              />
            </ion-item>
            <div v-if="v$.name.$error" class="field-error">{{ v$.name.$errors[0].$message }}</div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.user"
                label="Usuario"
                label-placement="floating"
                type="text"
                placeholder="Ingresa tu usuario"
                @ion-blur="v$.user.$touch()"
              />
            </ion-item>
            <div v-if="v$.user.$error" class="field-error">{{ v$.user.$errors[0].$message }}</div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.email"
                label="Email"
                label-placement="floating"
                type="email"
                placeholder="Ingresa tu email"
                @ion-blur="v$.email.$touch()"
              />
            </ion-item>
            <div v-if="v$.email.$error" class="field-error">{{ v$.email.$errors[0].$message }}</div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password"
                label="Contraseña"
                label-placement="floating"
                type="password"
                placeholder="Ingresa tu contraseña"
                @ion-blur="v$.password.$touch()"
              />
            </ion-item>

            <div v-if="form.password.length > 0" class="strength-bar-container">
              <div
                class="strength-bar"
                :style="{ width: passwordStrength.percent + '%' }"
                :class="'strength-' + passwordStrength.level"
              ></div>
              <span class="strength-label">{{ passwordStrength.label }}</span>
            </div>
            <div v-if="v$.password.$error" class="field-error">{{ v$.password.$errors[0].$message }}</div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password_confirmation"
                label="Confirmar contraseña"
                label-placement="floating"
                type="password"
                placeholder="Confirma tu contraseña"
                @ion-blur="v$.password_confirmation.$touch()"
              />
            </ion-item>
            <div v-if="v$.password_confirmation.$error" class="field-error">{{ v$.password_confirmation.$errors[0].$message }}</div>

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
                <span v-else>Registrar</span>
              </ion-button>
            </div>
          </form>

          <div class="auth-card-footer">
            <ion-button fill="clear" expand="block" class="subtle" @click="goToLogin">
              Ya tengo cuenta
            </ion-button>
            <ion-button fill="clear" expand="block" class="subtle" @click="goBack">
              <ion-icon slot="start" :icon="arrowBack"></ion-icon>
              Volver
            </ion-button>
          </div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { ref, computed, onMounted } from 'vue';
  import { useRouter, useRoute } from 'vue-router';
  import {
    IonPage,
    IonContent,
    IonItem,
    IonInput,
    IonButton,
    IonSpinner,
    IonIcon,
    loadingController,
  } from '@ionic/vue';
  import { arrowBack, alertCircle } from 'ionicons/icons';
  import useVuelidate from '@vuelidate/core';
  import { required, email, minLength, sameAs } from '@vuelidate/validators';

  import { useAuthController } from '@/controllers/authController';
  import { useValidation, withMsg } from '@/composables/useValidation';
  const authController = useAuthController();
  const { handleApiError } = useValidation();

  import { useToast } from '@/composables/useToast';
  const { showSuccess } = useToast();

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

  const rules = computed(() => ({
    name: { required: withMsg('El nombre es requerido', required) },
    user: {
      required: withMsg('El usuario es requerido', required),
      minLength: withMsg('El usuario debe tener al menos 3 caracteres', minLength(3)),
    },
    email: {
      required: withMsg('El email es requerido', required),
      email: withMsg('Debe ser un email válido', email),
    },
    password: {
      required: withMsg('La contraseña es requerida', required),
      minLength: withMsg('La contraseña debe tener al menos 8 caracteres', minLength(8)),
    },
    password_confirmation: {
      required: withMsg('Debe confirmar la contraseña', required),
      sameAs: withMsg('Las contraseñas no coinciden', sameAs(computed(() => form.value.password))),
    },
  }));

  const v$ = useVuelidate(rules, form);

  const passwordStrength = computed(() => {
    const pwd = form.value.password;
    if (pwd.length === 0) return { percent: 0, level: 'none', label: '' };

    let score = 0;
    if (pwd.length >= 6) score += 25;
    if (pwd.length >= 10) score += 15;
    if (pwd.length >= 12) score += 10;
    if (/[a-z]/.test(pwd)) score += 10;
    if (/[A-Z]/.test(pwd)) score += 10;
    if (/\d/.test(pwd)) score += 10;
    if (/[^a-zA-Z0-9]/.test(pwd)) score += 20;

    if (score >= 90) return { percent: 100, level: 'very-strong', label: 'Muy fuerte' };
    if (score >= 70) return { percent: 75, level: 'strong', label: 'Fuerte' };
    if (score >= 45) return { percent: 50, level: 'medium', label: 'Media' };
    return { percent: 25, level: 'weak', label: 'Débil' };
  });

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

  const handleRegister = async (): Promise<void> => {
    const isValid = await v$.value.$validate();
    if (!isValid) return;

    loading.value = true;
    error.value = '';

    const loader = await loadingController.create({
      message: 'Creando cuenta...',
    });
    await loader.present();

    try {
      await authController.register({
        name: form.value.name,
        user: form.value.user,
        email: form.value.email,
        password: form.value.password,
        password_confirmation: form.value.password_confirmation,
        client_id: oauthParams.value.client_id || undefined,
      });
      await loader.dismiss();
      showSuccess({ message: 'Cuenta creada correctamente. Ya puedes iniciar sesión.' });
      router.push('/login');
    } catch (err: unknown) {
      await loader.dismiss();
      console.error('[Register Error]', err);
      error.value = handleApiError(err);
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
    }
    router.push({ name: 'AdminLogin', query });
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

.strength-bar-container {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 0 8px;
}

.strength-bar {
  height: 4px;
  border-radius: 999px;
  transition: all 0.3s ease;
  flex: 1;
  max-width: 100px;
}

.strength-bar.strength-weak { background: var(--ion-color-danger); }
.strength-bar.strength-medium { background: var(--ion-color-warning); }
.strength-bar.strength-strong { background: var(--ion-color-success); }
.strength-bar.strength-very-strong { background: var(--ion-color-primary); }

.strength-label {
  font-size: 11px;
  color: var(--text-tertiary);
  white-space: nowrap;
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

@media (prefers-reduced-motion: reduce) {
  .blob {
    animation: none;
  }

  .auth-card {
    animation: none;
  }
}
</style>
