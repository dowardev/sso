<!-- SPDX-License-Identifier: MIT -->
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
            @click="navigateToDesign({})"
          >
            <div slot="start" class="config-icon-wrapper">
              <ion-icon :icon="colorPalette"></ion-icon>
            </div>
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

          <ion-item
            button
            :class="{ 'active-item': isActiveRoute('/admin/config/security') }"
            @click="navigateToSecurity({})"
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
            <span class="coming-soon">Próximamente</span>
          </ion-item>

          <ion-item button disabled>
            <div slot="start" class="config-icon-wrapper">
              <ion-icon :icon="globe"></ion-icon>
            </div>
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
// 1. Imports (ionic/vue/ionicons)
import { useRouter, useRoute } from 'vue-router'
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
} from '@ionic/vue'
import {
  colorPalette,
  chevronForward,
  shield,
  notifications,
  globe,
} from 'ionicons/icons'

// 2. Controllers / Composables

// 3. Components

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const
const router = useRouter()
const route = useRoute()

// 7. Watch

// 8. Computed
const isActiveRoute = (path: string): boolean => route.path === path

// 9. OnMounted

// 10. Functions (named parameters pattern)
const navigateToDesign = async (_params: object = {}): Promise<void> => {
  await router.push('/admin/config/design')
}

const navigateToSecurity = async (_params: object = {}): Promise<void> => {
  await router.push('/admin/config/security')
}
</script>

<style scoped>
.config-list {
  background: transparent;
  margin-bottom: var(--space-6, 24px);
}

.config-list ion-item {
  --border-radius: 4px;
  --background: var(--glass-bg);
  margin-bottom: 8px;
  --padding-start: 12px;
  --padding-end: 12px;
  --min-height: clamp(48px, 10vw, 52px);
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
  border-radius: 4px;
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
  font-size: clamp(13px, 3vw, 14px);
}

.end-icon {
  color: var(--text-tertiary);
  font-size: 18px;
}

.coming-soon {
  font-size: var(--text-xs, 10px);
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
  font-size: var(--text-sm, 12px);
}
</style>
