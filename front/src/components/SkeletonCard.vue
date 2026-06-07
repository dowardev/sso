<template>
  <ion-card>
    <div class="skeleton-card-content">
      <ion-skeleton-text animated style="width: 100%; height: 20px; border-radius: 4px"></ion-skeleton-text>
      <ion-skeleton-text animated style="width: 60%; height: 14px; border-radius: 4px; margin-top: 8px"></ion-skeleton-text>
      <ion-skeleton-text animated style="width: 80%; height: 14px; border-radius: 4px; margin-top: 12px"></ion-skeleton-text>
      <ion-skeleton-text animated style="width: 40%; height: 28px; border-radius: 4px; margin-top: 12px"></ion-skeleton-text>
    </div>
  </ion-card>
</template>

<script setup lang="ts">
import { IonCard, IonSkeletonText } from '@ionic/vue';
</script>

<style scoped>
.skeleton-card-content {
  padding: 20px;
}
</style>
