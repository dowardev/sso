<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-menu-button></ion-menu-button>
        </ion-buttons>
        <ion-title>Configuración</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="config-container">
        <ion-list class="config-list">
          <ion-list-header class="section-header">Apariencia</ion-list-header>

          <ion-item
            button
            :class="{ 'active-item': isActiveRoute('/admin/config/design') }"
            @click="router.push('/admin/config/design')"
          >
            <ion-icon slot="start" :icon="colorPalette"></ion-icon>
            <ion-label>Personalización</ion-label>
            <ion-icon
              slot="end"
              :icon="chevronForward"
              class="end-icon"
            ></ion-icon>
          </ion-item>
        </ion-list>

        <ion-list class="config-list">
          <ion-list-header class="section-header">Sistema</ion-list-header>

          <ion-item button disabled>
            <ion-icon slot="start" :icon="shield"></ion-icon>
            <ion-label>Seguridad</ion-label>
            <span class="coming-soon">Próximamente</span>
          </ion-item>

          <ion-item button disabled>
            <ion-icon slot="start" :icon="notifications"></ion-icon>
            <ion-label>Notificaciones</ion-label>
            <span class="coming-soon">Próximamente</span>
          </ion-item>

          <ion-item button disabled>
            <ion-icon slot="start" :icon="globe"></ion-icon>
            <ion-label>Idioma</ion-label>
            <span class="coming-soon">Próximamente</span>
          </ion-item>
        </ion-list>

        <div class="version-info">
          <span>SSO Admin v1.0.0</span>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import { useRouter, useRoute } from 'vue-router';
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonMenuButton,
    IonContent,
    IonList,
    IonListHeader,
    IonItem,
    IonIcon,
    IonLabel,
  } from '@ionic/vue';
  import {
    colorPalette,
    chevronForward,
    shield,
    notifications,
    globe,
  } from 'ionicons/icons';

  const router = useRouter();
  const route = useRoute();

  const isActiveRoute = (path: string) => {
    return route.path === path;
  };
</script>

<style scoped>
  .config-container {
    padding: 16px;
    max-width: 600px;
    margin: 0 auto;
  }

  .config-list {
    background: transparent;
    margin-bottom: 24px;
  }

  .section-header {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-tertiary);
    padding-left: 0;
  }

  .config-list ion-item {
    --border-radius: 12px;
    --background: var(--background-contrast);
    margin-bottom: 8px;
    --padding-start: 12px;
    --padding-end: 12px;
  }

  .config-list ion-item:active:not([disabled]) {
    --background: var(--ion-color-light);
  }

  .config-list ion-item.active-item {
    --background: var(--ion-color-primary-tint);
  }

  .config-list ion-item[disabled] {
    opacity: 0.5;
  }

  .config-list ion-icon {
    color: var(--text-secondary);
  }

  .config-list ion-label {
    color: var(--text-primary);
  }

  .end-icon {
    color: var(--text-tertiary);
    font-size: 18px;
  }

  .coming-soon {
    font-size: 12px;
    color: var(--text-tertiary);
  }

  .version-info {
    text-align: center;
    padding: 24px;
    color: var(--text-tertiary);
    font-size: 12px;
  }
</style>
