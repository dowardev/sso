<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header>
      <ion-toolbar>
        <ion-title>Cerrando sesión</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content :fullscreen="true" class="ion-text-center">
      <div class="logout-container">
        <ion-spinner name="crescent" aria-label="Cerrando sesión"></ion-spinner>
        <p aria-live="polite">Cerrando sesión...</p>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonSpinner,
} from '@ionic/vue'

// 2. Controllers / Composables
import { useAuthController } from '@/controllers/authController'
const authController = useAuthController()

// 3. Components

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const
const route = useRoute()

// 7. Watch

// 8. Computed

// 9. OnMounted
onMounted(async () => {
  try {
    await authController.logout()
  } catch {
    // Continue even if server logout fails — local state is already cleared
  }

  const redirectUri = (route.query.redirect_uri as string) || ''

  if (redirectUri) {
    window.location.href = redirectUri
  } else {
    window.location.href = '/auth/login'
  }
})

// 10. Functions (named parameters pattern)
</script>

<style scoped>
.logout-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  gap: 16px;
}
</style>
