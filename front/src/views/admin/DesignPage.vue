<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-buttons slot="start">
          <ion-back-button default-href="/admin/config"></ion-back-button>
        </ion-buttons>
        <ion-title>Personalización</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content :fullscreen="true">
      <div class="design-container">
        <ion-grid class="design-grid">
          <ion-row>
            <ion-col size="12" size-md="7">
              <ion-list class="design-list">
                <ion-list-header class="section-header">Apariencia</ion-list-header>

                <ion-item button @click="openThemePopover">
                  <ion-icon slot="start" :icon="colorPalette"></ion-icon>
                  <ion-label>Tema</ion-label>
                  <div slot="end" class="theme-value">
                    <div
                      class="theme-color-preview"
                      :class="{ 'dual-color': currentTheme.name === 'auto' }"
                      :style="currentTheme.name === 'auto' ? { background: currentTheme.color } : { backgroundColor: currentTheme.color }"
                    ></div>
                    <span>{{ currentTheme.label }}</span>
                    <ion-icon :icon="chevronDown" class="dropdown-icon"></ion-icon>
                  </div>
                </ion-item>

                <template v-if="isAuto">
                  <ion-item button @click="openLightThemePopover">
                    <ion-icon slot="start" :icon="sunnyOutline"></ion-icon>
                    <ion-label>Cuando el sistema está en claro</ion-label>
                    <div slot="end" class="theme-value">
                      <div
                        class="theme-color-preview"
                        :style="{ backgroundColor: autoLightThemeInfo.color }"
                      ></div>
                      <span>{{ autoLightThemeInfo.label }}</span>
                      <ion-icon :icon="chevronForward" class="dropdown-icon"></ion-icon>
                    </div>
                  </ion-item>

                  <ion-item button @click="openDarkThemePopover">
                    <ion-icon slot="start" :icon="moonOutline"></ion-icon>
                    <ion-label>Cuando el sistema está en oscuro</ion-label>
                    <div slot="end" class="theme-value">
                      <div
                        class="theme-color-preview"
                        :style="{ backgroundColor: autoDarkThemeInfo.color }"
                      ></div>
                      <span>{{ autoDarkThemeInfo.label }}</span>
                      <ion-icon :icon="chevronForward" class="dropdown-icon"></ion-icon>
                    </div>
                  </ion-item>
                </template>
              </ion-list>
            </ion-col>

            <ion-col size="12" size-md="5">
              <div class="theme-preview">
                <h4 class="preview-title">Vista Previa</h4>
                <div class="preview-card">
                  <div class="preview-header"></div>
                  <div class="preview-body">
                    <div class="preview-stat"></div>
                    <div class="preview-stat"></div>
                    <div class="preview-stat"></div>
                  </div>
                </div>
                <p class="preview-hint">
                  El tema se aplica automáticamente al seleccionar
                </p>
              </div>
            </ion-col>
          </ion-row>
        </ion-grid>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  import {
    IonPage,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonBackButton,
    IonContent,
    IonList,
    IonListHeader,
    IonItem,
    IonIcon,
    IonLabel,
    IonGrid,
    IonRow,
    IonCol,
    popoverController,
  } from '@ionic/vue';
  import { colorPalette, chevronDown, chevronForward, sunnyOutline, moonOutline } from 'ionicons/icons';
  import { useTheme, type ResolvedThemeName } from '@/services/theme';
  import ThemePopover from '@/components/ThemePopover.vue';

  const { currentTheme, isAuto, autoLightThemeInfo, autoDarkThemeInfo, setTheme, setAutoLightTheme, setAutoDarkTheme } = useTheme();

  const openThemePopover = async (event: Event) => {
    const popover = await popoverController.create({
      component: ThemePopover,
      event: event,
      translucent: true,
      breakpoints: [0, 0.5],
      initialBreakpoint: 0.5,
    });
    await popover.present();
  };

  const openLightThemePopover = async (event: Event) => {
    const popover = await popoverController.create({
      component: ThemePopover,
      componentProps: {
        excludeAuto: true,
        onThemeSelect: (name: ResolvedThemeName) => {
          setAutoLightTheme(name);
          popover.dismiss();
        },
      },
      event: event,
      translucent: true,
      breakpoints: [0, 0.5],
      initialBreakpoint: 0.5,
    });
    await popover.present();
  };

  const openDarkThemePopover = async (event: Event) => {
    const popover = await popoverController.create({
      component: ThemePopover,
      componentProps: {
        excludeAuto: true,
        onThemeSelect: (name: ResolvedThemeName) => {
          setAutoDarkTheme(name);
          popover.dismiss();
        },
      },
      event: event,
      translucent: true,
      breakpoints: [0, 0.5],
      initialBreakpoint: 0.5,
    });
    await popover.present();
  };
</script>

<style scoped>
.design-container {
  padding: 16px;
  max-width: 900px;
  margin: 0 auto;
}

.design-grid {
  padding: 0;
  --ion-grid-padding: 0;
}

.design-grid ion-col {
  padding: 0 0 24px 0;
}

.design-list {
  background: transparent;
}

.section-header {
  font-size: 13px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-tertiary);
  padding-left: 0;
}

.design-list ion-item {
  --border-radius: 12px;
  --background: var(--background-contrast);
  --padding-start: 12px;
  --padding-end: 12px;
  margin-bottom: 8px;
}

.design-list ion-item:active {
  --background: var(--ion-color-light);
}

.design-list ion-icon {
  color: var(--text-secondary);
}

.design-list ion-label {
  color: var(--text-primary);
}

.theme-value {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--text-secondary);
  font-size: 14px;
}

.theme-color-preview {
  width: 16px;
  height: 16px;
  border-radius: 50%;
}

.theme-color-preview.dual-color {
  background: linear-gradient(135deg, var(--gradient-primary) 50%, var(--gradient-secondary, #4d91ff) 50%);
}

.dropdown-icon {
  font-size: 16px;
  color: var(--text-tertiary);
}

.theme-preview {
  position: sticky;
  top: 16px;
}

.preview-title {
  margin: 0 0 16px 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-primary);
}

.preview-card {
  background: var(--background-contrast);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: var(--card-shadow-light) 0px 1px 3px 0px, var(--card-shadow-heavy) 0px 0px 0px 1px;
}

.preview-header {
  height: 48px;
  background: var(--gradient-primary);
}

.preview-body {
  padding: 16px;
  display: flex;
  gap: 12px;
}

.preview-stat {
  flex: 1;
  height: 60px;
  background: var(--ion-color-light);
  border-radius: 8px;
}

.preview-hint {
  margin: 12px 0 0 0;
  font-size: 12px;
  color: var(--text-tertiary);
  text-align: center;
}

@media (min-width: 768px) {
  .design-container {
    padding: 24px;
  }

  .design-grid ion-col {
    padding: 0 0 0 24px;
  }

  .design-grid ion-col:first-child {
    padding-left: 0;
  }
}
</style>