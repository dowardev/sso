<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <div class="home-container">
        <div class="home-card">
          <div class="logo-section">
            <img
              alt="SSO Logo"
              src="@/assets/images/lambda_v1.svg"
              class="logo-image"
            />
            <h1 class="home-title">SSO</h1>
            <p class="home-subtitle">Sistema de Autenticación Única</p>
          </div>

          <div class="features-section">
            <div class="feature-item">
              <ion-icon :icon="shieldCheckmarkOutline" class="feature-icon"></ion-icon>
              <span>Seguridad avanzada</span>
            </div>
            <div class="feature-item">
              <ion-icon :icon="keyOutline" class="feature-icon"></ion-icon>
              <span>Una sola contraseña</span>
            </div>
            <div class="feature-item">
              <ion-icon :icon="globeOutline" class="feature-icon"></ion-icon>
              <span>Múltiples aplicaciones</span>
            </div>
          </div>

          <div class="action-buttons">
            <ion-button
              expand="block"
              class="primary-button"
              @click="goToLogin"
            >
              Iniciar Sesión
            </ion-button>
            <ion-button
              expand="block"
              fill="outline"
              class="secondary-button"
              @click="goToRegister"
            >
              Crear Cuenta
            </ion-button>
          </div>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { useRouter } from 'vue-router';
  import {
    IonContent,
    IonPage,
    IonButton,
    IonIcon,
  } from '@ionic/vue';
  import { shieldCheckmarkOutline, keyOutline, globeOutline } from 'ionicons/icons';

  const router = useRouter();

  const goToLogin = () => {
    router.push('/login');
  };

  const goToRegister = () => {
    router.push('/register');
  };
</script>

<style scoped>
.home-container {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: var(--gradient-primary);
}

.home-card {
  width: 100%;
  max-width: 400px;
  background: var(--background-contrast);
  border-radius: var(--card-border-radius);
  box-shadow: var(--card-shadow-light) 0px 8px 24px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
  padding: 32px 24px;
  text-align: center;
}

.logo-section {
  margin-bottom: 24px;
}

.logo-image {
  width: 80px;
  height: 80px;
  margin-bottom: 16px;
}

.home-title {
  margin: 0;
  font-size: 32px;
  font-weight: 700;
  color: var(--text-primary);
}

.home-subtitle {
  margin: 8px 0 0 0;
  font-size: 14px;
  color: var(--text-secondary);
}

.features-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 32px;
  padding: 16px 0;
  border-top: 1px solid var(--ion-background-color-step-200);
  border-bottom: 1px solid var(--ion-background-color-step-200);
}

.feature-item {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: var(--text-secondary);
  font-size: 14px;
}

.feature-icon {
  font-size: 20px;
  color: var(--ion-color-primary);
}

.action-buttons {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.primary-button {
  --border-radius: 8px;
  height: 48px;
  font-weight: 600;
}

.secondary-button {
  --border-radius: 8px;
  height: 48px;
  font-weight: 600;
  --border-color: var(--ion-color-primary);
  --color: var(--ion-color-primary);
}

@media (min-width: 768px) {
  .home-container {
    padding: 48px;
  }

  .home-card {
    padding: 40px 32px;
  }

  .logo-image {
    width: 96px;
    height: 96px;
  }

  .home-title {
    font-size: 40px;
  }

  .features-section {
    flex-direction: row;
    justify-content: space-around;
  }
}
</style>