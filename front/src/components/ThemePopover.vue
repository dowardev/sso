<!-- SPDX-License-Identifier: MIT -->
<template>
  <div class="theme-popover">
    <div class="popover-header">
      <h3>Seleccionar Tema</h3>
    </div>
    <ion-list class="theme-list" lines="none">
      <ion-item
        v-for="theme in filteredThemes"
        :key="theme.name"
        button
        :class="{ active: currentThemeName === theme.name }"
        :aria-current="currentThemeName === theme.name ? 'true' : undefined"
        @click="selectTheme({ themeName: theme.name })"
      >
        <div
          class="theme-color"
          :class="{ 'dual-color': theme.name === 'auto' }"
          :style="
            theme.name === 'auto'
              ? { background: theme.color }
              : { backgroundColor: theme.color }
          "
        ></div>
        <ion-label class="theme-label">{{ theme.label }}</ion-label>
        <ion-icon
          v-if="currentThemeName === theme.name"
          slot="end"
          :icon="checkmark"
          class="check-icon"
        ></ion-icon>
      </ion-item>
    </ion-list>
  </div>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { computed, onMounted, onUnmounted } from 'vue';
  import { IonIcon, IonList, IonItem, IonLabel } from '@ionic/vue';
  import { checkmark } from 'ionicons/icons';

  // 2. Controllers
  import {
    useThemeController,
    type ThemeName,
  } from '@/controllers/themeController';

  const themeController = useThemeController();
  const themes = themeController.themes;
  const currentThemeName = themeController.currentThemeName;

  // 3. Components
  // (none)

  // 4. Interface and types imports
  // (ThemeName imported from controller above)

  // 5. Main (props, emits, etc.)
  const props = defineProps<{
    excludeAuto?: boolean;
    onThemeSelect?: ((name: ThemeName) => void) | null;
  }>();

  // 6. Vars and const
  // (none)

  // 7. Watch
  // (none)

  // 8. Computed
  const filteredThemes = computed(() => {
    if (props.excludeAuto) {
      return themes.filter((t) => t.name !== 'auto');
    }
    return themes;
  });

  // 9. OnMounted
  onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
  });

  onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
  });

  // 10. Functions
  const setTheme = (params: { themeName: ThemeName }): void => {
    const { themeName } = params;
    themeController.setTheme({ themeName });
  };

  const selectTheme = (params: { themeName: ThemeName }): void => {
    const { themeName } = params;
    if (props.onThemeSelect) {
      props.onThemeSelect(themeName);
    } else {
      setTheme({ themeName });
    }
  };

  function handleKeydown(event: KeyboardEvent) {
    const items = document.querySelectorAll('.theme-list ion-item');
    const currentIndex = Array.from(items).indexOf(document.activeElement as HTMLElement);

    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
      event.preventDefault();
      const nextIndex = (currentIndex + 1) % items.length;
      (items[nextIndex] as HTMLElement).focus();
    }

    if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
      event.preventDefault();
      const prevIndex = (currentIndex - 1 + items.length) % items.length;
      (items[prevIndex] as HTMLElement).focus();
    }

    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      const item = document.activeElement as HTMLElement;
      item.click();
    }
  }
</script>

<style scoped>
  .theme-popover {
    padding: 0;
  }

  .popover-header {
    padding: 16px 16px 12px;
    border-bottom: 1px solid var(--ion-color-light);
  }

  .popover-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--ion-text-color);
  }

  .theme-list {
    padding: 0;
  }

  .theme-list ion-item.active {
    --background: var(--ion-color-primary-tint);
  }

  .theme-list ion-item {
    cursor: pointer;
  }

  .theme-list ion-item:focus-visible {
    outline: 2px solid var(--ion-color-primary);
    outline-offset: -2px;
    border-radius: var(--radius-sm, 8px);
  }

  .theme-color {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    flex-shrink: 0;
    border: 2px solid rgba(var(--ion-color-medium-rgb), 0.35);
  }

  .theme-color.dual-color {
    background: linear-gradient(135deg, var(--ion-color-primary) 50%, var(--ion-color-secondary) 50%);
    border: 2px solid rgba(var(--ion-color-medium-rgb), 0.5);
  }

  .theme-label {
    flex: 1;
    font-size: 15px;
    color: var(--ion-text-color);
  }

  .check-icon {
    color: var(--ion-color-primary);
    font-size: 20px;
  }

  @media (prefers-reduced-motion: reduce) {
    .theme-list ion-item {
      transition: none;
    }
  }
</style>
