<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <div class="home-container">
        <ion-card class="home-card">
          <div class="logo-section">
            <img
              alt="SSO Logo"
              src="@/assets/images/lambda_v1.svg"
              class="logo-image"
            />
            <h1 class="home-title">SSO</h1>
            <p class="home-subtitle">Sistema de Autenticación Única</p>
          </div>

          <ion-list class="features-section">
            <ion-item class="feature-item" lines="none">
              <ion-icon
                :icon="shieldCheckmarkOutline"
                class="feature-icon"
                aria-hidden="true"
              ></ion-icon>
              <ion-label>Seguridad avanzada</ion-label>
            </ion-item>

            <ion-item class="feature-item" lines="none">
              <ion-icon :icon="keyOutline" class="feature-icon" aria-hidden="true"></ion-icon>
              <ion-label>Una sola contraseña</ion-label>
            </ion-item>

            <ion-item class="feature-item" lines="none">
              <ion-icon :icon="globeOutline" class="feature-icon" aria-hidden="true"></ion-icon>
              <ion-label>Múltiples aplicaciones</ion-label>
            </ion-item>
          </ion-list>

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
        </ion-card>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { useRouter } from 'vue-router';
  import { IonContent, IonPage, IonButton, IonIcon, IonCard, IonList, IonItem, IonLabel } from '@ionic/vue';
  import {
    shieldCheckmarkOutline,
    keyOutline,
    globeOutline,
  } from 'ionicons/icons';

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
    background: var(--ion-color-primary);
  }

  .home-card {
    width: 100%;
    max-width: 400px;
    background: var(--background-contrast);
    border-radius: var(--card-border-radius);
    box-shadow:
      var(--card-shadow-light) 0px 8px 24px 0px,
      var(--card-shadow-heavy) 0px 0px 0px 1px;
    margin: 0;
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
    --ion-item-background: transparent;
    background: transparent;
    margin-bottom: 32px;
    padding: 16px 0;
    border-top: 1px solid var(--ion-background-color-step-200);
    border-bottom: 1px solid var(--ion-background-color-step-200);
  }

  .feature-item {
    --background: transparent;
    --ion-item-background: transparent;
    /* The item sets its own minimum height, so the baseline height needs the
       important flag: the rules of the shadow tree win over the rules of the
       outer tree for the same property. */
    --min-height: 20px !important;
    --inner-padding-top: 0;
    --inner-padding-bottom: 0;
    --inner-padding-start: 0;
    --inner-padding-end: 0;
    --padding-start: 0;
    --padding-end: 0;
    justify-content: center;
  }

  /* The baseline spaced the feature rows with a 12px gap. */
  .feature-item + .feature-item {
    margin-top: 12px;
  }

  /* The row keeps the centred icon and label of the baseline: the item native
     part is the flex row that holds the label, and the inner part hugs its
     content, so the pair is centred together instead of sticking to the edge.
     The parts need the important flag because the rules of the shadow tree
     win over the rules of the outer tree for the same property. */
  .feature-item::part(native) {
    justify-content: center !important;
  }

  .feature-item::part(inner) {
    flex: 0 0 auto !important;
    height: 20px !important;
  }

  .feature-item ion-label {
    color: var(--text-secondary);
    font-size: 14px;
    --padding-start: 0;
    --padding-end: 0;
    padding: 0;
    white-space: nowrap;
  }

  .feature-icon {
    font-size: 20px;
    color: var(--ion-color-primary);
    flex-shrink: 0;
    /* The baseline spaced the icon and the label. */
    margin-inline-end: 8px;
  }

  .action-buttons {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .primary-button {
    --border-radius: 4px;
    height: 48px;
    font-weight: 600;
  }

  .secondary-button {
    --border-radius: 4px;
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
      justify-content: space-evenly;
    }
  }
</style>
