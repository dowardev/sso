<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-title>Iniciar Sesión</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content :fullscreen="true">
      <AuthLoginForm :is-public="true" />
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { IonPage, IonHeader, IonToolbar, IonTitle, IonContent } from '@ionic/vue'

// 2. Controllers / Composables

// 3. Components
import AuthLoginForm from '@/components/AuthLoginForm.vue'

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const

// 7. Watch

// 8. Computed

// 9. OnMounted

// 10. Functions (named parameters pattern)
</script>

<style scoped>
</style>
