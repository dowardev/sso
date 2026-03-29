<template>
  <ion-page>
    <ion-content :fullscreen="true" class="login-content">
      <div class="login-container">
        <div class="login-header">
          <h1>SSO Admin</h1>
          <p>Ingresa tus credenciales</p>
        </div>

        <form @submit.prevent="handleLogin" class="login-form">
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
        </form>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { IonPage, IonContent, IonItem, IonLabel, IonInput, IonButton, IonSpinner } from '@ionic/vue';
import { auth } from '@/services/auth';

const router = useRouter();

const credentials = ref({
  user: '',
  password: '',
});

const loading = ref(false);
const error = ref('');

const handleLogin = async () => {
  loading.value = true;
  error.value = '';

  try {
    await auth.login(credentials.value);
    router.replace('/admin/dashboard');
  } catch (err: any) {
    error.value = err.message || 'Error al iniciar sesión';
  } finally {
    loading.value = false;
  }
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

.error-message {
  color: var(--ion-color-danger);
  text-align: center;
  margin: 8px 0;
  font-size: 14px;
}
</style>
