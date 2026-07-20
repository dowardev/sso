<template>
  <div class="resource-card-skeleton" aria-hidden="true">
    <div class="skeleton-icon"></div>
    <div class="skeleton-lines">
      <div class="skeleton-line skeleton-line-title"></div>
      <div class="skeleton-line skeleton-line-desc"></div>
    </div>
  </div>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  // (none - no Ionic components in template)
  // (no other sections needed)
</script>

<style scoped>
.resource-card-skeleton {
  background: var(--glass-bg);
  border: 1px solid var(--glass-border);
  border-radius: 4px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 14px;
}

.skeleton-icon {
  width: 44px;
  height: 44px;
  border-radius: 4px;
  background: var(--ion-background-color-step-100);
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}

.skeleton-lines {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.skeleton-line {
  height: 12px;
  border-radius: 4px;
  background: var(--ion-background-color-step-100);
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}

.skeleton-line-title {
  width: 80px;
}

.skeleton-line-desc {
  width: 120px;
  height: 10px;
}

@keyframes skeleton-pulse {
  0%, 100% { opacity: 0.4; }
  50% { opacity: 0.7; }
}
</style>
