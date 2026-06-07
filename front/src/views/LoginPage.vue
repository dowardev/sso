<template>
  <ion-page>
    <ion-content :fullscreen="true">
      <AuthLoginForm :is-public="false" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
  // imports
  import { IonPage, IonContent } from '@ionic/vue';

  // components
  import AuthLoginForm from '@/components/AuthLoginForm.vue';
  // end components
</script>

<style scoped></style>
