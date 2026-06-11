<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <div class="setup-screen">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>

        <div class="setup-card">
          <div class="setup-card-header">
            <div class="setup-logo-circle">
              <img alt="Logo SSO Server" src="@/assets/images/lambda_v1.svg" class="setup-logo" />
            </div>
            <h2>Configuración Inicial</h2>
            <p>Crea el primer usuario administrador del sistema</p>
          </div>

          <form @submit.prevent="handleSetup">
            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.name"
                label-placement="floating"
                label="Nombre completo"
                type="text"
                placeholder="Ej: Juan Pérez"
                @ion-blur="v$.name.$touch()"
              />
            </ion-item>
            <div v-if="v$.name.$error" class="field-error">
              {{ v$.name.$errors[0].$message }}
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.user"
                label-placement="floating"
                label="Nombre de usuario"
                type="text"
                placeholder="Ej: juanperez"
                @ion-blur="v$.user.$touch()"
              />
            </ion-item>
            <div v-if="v$.user.$error" class="field-error">
              {{ v$.user.$errors[0].$message }}
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.email"
                label-placement="floating"
                label="Correo electrónico"
                type="email"
                placeholder="Ej: juan@ejemplo.com"
                @ion-blur="v$.email.$touch()"
              />
            </ion-item>
            <div v-if="v$.email.$error" class="field-error">
              {{ v$.email.$errors[0].$message }}
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password"
                label="Contraseña"
                label-placement="floating"
                type="password"
                placeholder="Mínimo 6 caracteres"
                @ion-blur="v$.password.$touch()"
              >
                <ion-input-password-toggle slot="end" />
              </ion-input>
            </ion-item>
            <div v-if="v$.password.$error" class="field-error">
              {{ v$.password.$errors[0].$message }}
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password_confirmation"
                label="Confirmar contraseña"
                label-placement="floating"
                type="password"
                placeholder="Repite la contraseña"
                @ion-blur="v$.password_confirmation.$touch()"
              >
                <ion-input-password-toggle slot="end" />
              </ion-input>
            </ion-item>
            <div v-if="v$.password_confirmation.$error" class="field-error">
              {{ v$.password_confirmation.$errors[0].$message }}
            </div>

            <div v-if="setupController.error.value" class="error-message" role="alert" aria-live="assertive">
              <ion-icon :icon="alertCircle" size="small"></ion-icon>
              {{ setupController.error.value }}
            </div>

            <div class="ion-padding-top">
              <ion-button
                type="submit"
                expand="block"
                class="gradient-btn"
                :disabled="setupController.loading.value"
              >
                <ion-spinner v-if="setupController.loading.value" name="lines"></ion-spinner>
                <span v-else>Crear Administrador</span>
              </ion-button>
            </div>
          </form>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  // imports
  import { ref, computed } from 'vue';
  import { useRouter } from 'vue-router';
  import {
    IonPage,
    IonContent,
    IonItem,
    IonInput,
    IonButton,
    IonSpinner,
    IonIcon,
    IonInputPasswordToggle,
  } from '@ionic/vue';
  import { alertCircle } from 'ionicons/icons';
  import useVuelidate from '@vuelidate/core';
  import { required, minLength, email, sameAs } from '@vuelidate/validators';

  // controllers
  import { useSetupController } from '@/controllers/setupController';
  const setupController = useSetupController();

  const router = useRouter();

  // vars and const
  const form = ref({
    name: '',
    user: '',
    email: '',
    password: '',
    password_confirmation: '',
  });

  // computed
  const rules = computed(() => ({
    name: { required: required },
    user: { required: required },
    email: { required: required, email: email },
    password: { required: required, minLength: minLength(6) },
    password_confirmation: { required: required, sameAs: sameAs(computed(() => form.value.password)) },
  }));

  const v$ = useVuelidate(rules, form);

  // functions
  async function handleSetup(): Promise<void> {
    const isValid = await v$.value.$validate();
    if (!isValid) return;

    try {
      await setupController.createAdmin({
        name: form.value.name,
        user: form.value.user,
        email: form.value.email,
        password: form.value.password,
        password_confirmation: form.value.password_confirmation,
      });
      localStorage.setItem('setup_completed', 'true');
      router.replace('/login');
    } catch {
      // error is stored in setupController.error
    }
  }
</script>

<style scoped>
.setup-screen {
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

.setup-card {
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

.setup-card-header {
  text-align: center;
  margin-bottom: 32px;
}

.setup-logo-circle {
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

.setup-logo {
  max-width: 32px;
  height: auto;
  filter: brightness(0) invert(1);
}

.setup-card-header h2 {
  font-family: var(--font-heading);
  font-size: 24px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0 0 6px 0;
}

.setup-card-header p {
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

@media (max-width: 374px) {
  .setup-card {
    padding: 24px 16px;
  }

  .setup-card-header h2 {
    font-size: 20px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .blob {
    animation: none;
  }

  .setup-card {
    animation: none;
  }
}
</style>