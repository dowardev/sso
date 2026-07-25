<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-item aria-hidden="true">
    <ion-avatar slot="start">
      <ion-skeleton-text animated style="width: 100%; height: 100%; border-radius: 50%"></ion-skeleton-text>
    </ion-avatar>
    <ion-label>
      <h2><ion-skeleton-text animated style="width: 40%"></ion-skeleton-text></h2>
      <p><ion-skeleton-text animated style="width: 60%"></ion-skeleton-text></p>
      <p><ion-skeleton-text animated style="width: 30%"></ion-skeleton-text></p>
    </ion-label>
    <div slot="end">
      <ion-skeleton-text animated style="width: 60px; height: 20px; border-radius: 4px"></ion-skeleton-text>
    </div>
  </ion-item>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { IonItem, IonAvatar, IonLabel, IonSkeletonText } from '@ionic/vue';
  // (no other sections needed)
</script>
