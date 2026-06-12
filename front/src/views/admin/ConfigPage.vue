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
      <div class="page-container">
        <ion-list class="config-list">
          <ion-list-header class="section-header">Apariencia</ion-list-header>

          <ion-item
            button
            :class="{ 'active-item': isActiveRoute('/admin/config/design') }"
            @click="router.push('/admin/config/design')"
          >
            <div slot="start" class="config-icon-wrapper">
              <ion-icon :icon="colorPalette"></ion-icon>
            </div>
            <ion-label>Personalizaci&oacute;n</ion-label>
            <ion-icon
              slot="end"
              :icon="chevronForward"
              class="end-icon"
            ></ion-icon>
          </ion-item>
        </ion-list>

        <ion-list class="config-list">
          <ion-list-header class="section-header">Sistema</ion-list-header>

          <ion-item
            button
            :class="{ 'active-item': isActiveRoute('/admin/config/security') }"
            @click="router.push('/admin/config/security')"
          >
            <div slot="start" class="config-icon-wrapper">
              <ion-icon :icon="shield"></ion-icon>
            </div>
            <ion-label>Seguridad</ion-label>
            <ion-icon
              slot="end"
              :icon="chevronForward"
              class="end-icon"
            ></ion-icon>
          </ion-item>

          <ion-item button disabled>
            <div slot="start" class="config-icon-wrapper">
              <ion-icon :icon="notifications"></ion-icon>
            </div>
            <ion-label>Notificaciones</ion-label>
            <span class="coming-soon">Pr&oacute;ximamente</span>
          </ion-item>

          <ion-item button disabled>
            <div slot="start" class="config-icon-wrapper">
              <ion-icon :icon="globe"></ion-icon>
            </div>
            <ion-label>Idioma</ion-label>
            <span class="coming-soon">Pr&oacute;ximamente</span>
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
  .config-list {
    background: transparent;
    margin-bottom: 24px;
  }

.config-list ion-item {
  --border-radius: 12px;
  --background: var(--glass-bg);
  margin-bottom: 8px;
  --padding-start: 12px;
  --padding-end: 12px;
  --min-height: 52px;
  transition: background 0.2s ease;
  border: 1px solid var(--glass-border);
}

.config-list ion-item:hover:not([disabled]) {
  --background: var(--glass-bg-hover);
  border-color: var(--glass-border-hover);
}

.config-list ion-item.active-item {
  --background: rgba(139, 92, 246, 0.1);
  border-color: rgba(139, 92, 246, 0.2);
}

.config-list ion-item.active-item ion-label {
  font-weight: 600;
  color: #A78BFA;
}

  .config-list ion-item[disabled] {
    opacity: 0.5;
  }

.config-icon-wrapper {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--gradient-primary);
  flex-shrink: 0;
}

.config-icon-wrapper ion-icon {
  font-size: 18px;
  color: #ffffff;
}

  .config-list ion-label {
    color: var(--text-primary);
    font-size: 14px;
  }

  .end-icon {
    color: var(--text-tertiary);
    font-size: 18px;
  }

  .coming-soon {
    font-size: 11px;
    color: var(--text-tertiary);
    border-radius: 999px;
    background: var(--ion-background-color-step-100);
    padding: 3px 10px;
    font-weight: 500;
  }

  .version-info {
    text-align: center;
    padding: 24px;
    color: var(--text-tertiary);
    font-size: 12px;
  }
</style>
