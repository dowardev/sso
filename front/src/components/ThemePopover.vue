<template>
  <div class="theme-popover">
    <div class="popover-header">
      <h3>Seleccionar Tema</h3>
    </div>
    <div class="theme-list">
      <div
        v-for="theme in filteredThemes"
        :key="theme.name"
        class="theme-item"
        :class="{ active: currentThemeName === theme.name }"
        @click="selectTheme(theme.name)"
      >
        <div
          class="theme-color"
          :class="{ 'dual-color': theme.name === 'auto' }"
          :style="theme.name === 'auto' ? { background: theme.color } : { backgroundColor: theme.color }"
        ></div>
        <span class="theme-label">{{ theme.label }}</span>
        <ion-icon
          v-if="currentThemeName === theme.name"
          :icon="checkmark"
          class="check-icon"
        ></ion-icon>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { computed } from 'vue';
  import { IonIcon } from '@ionic/vue';
  import { checkmark } from 'ionicons/icons';
  import { useThemeController, type ThemeName } from '@/controllers/themeController';

  const props = defineProps<{
    excludeAuto?: boolean;
    onThemeSelect?: ((name: ThemeName) => void) | null;
  }>();

  const themeController = useThemeController();
  const themes = themeController.themes;
  const currentThemeName = themeController.currentThemeName;
  const setTheme = (name: ThemeName): void => { themeController.setTheme({ themeName: name }); };

  const filteredThemes = computed(() => {
    if (props.excludeAuto) {
      return themes.filter(t => t.name !== 'auto');
    }
    return themes;
  });

  const selectTheme = (themeName: ThemeName) => {
    if (props.onThemeSelect) {
      props.onThemeSelect(themeName);
    } else {
      setTheme(themeName);
    }
  };
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
  padding: 8px 0;
}

.theme-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  cursor: pointer;
  transition: background 0.2s ease;
}

.theme-item:active {
  background: var(--ion-color-light);
}

.theme-item.active {
  background: var(--ion-color-primary-tint);
}

.theme-color {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  flex-shrink: 0;
  border: 2px solid rgba(0, 0, 0, 0.1);
}

.theme-color.dual-color {
  background: linear-gradient(135deg, #0054e9 50%, #4d91ff 50%);
  border: 2px solid rgba(0, 0, 0, 0.15);
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
  .theme-item {
    transition: none;
  }
}
</style>