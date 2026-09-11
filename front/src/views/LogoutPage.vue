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
        <p v-if="showReturnButton" class="return-hint">Si no volvés automáticamente a la app:</p>
        <ion-button v-if="showReturnButton" :href="safeReturnTarget" class="return-button">Volver a la app</ion-button>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonSpinner,
  IonButton,
} from '@ionic/vue'

// 2. Controllers / Composables
import { useAuthController } from '@/controllers/authController'
const authController = useAuthController()

// 3. Components

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const
const route = useRoute()
const redirectUri = ref((route.query.redirect_uri as string) || '')
const showReturnButton = ref(false)

// This value comes straight from the query string, so it is attacker-shaped:
// rendering it unchecked (or navigating to it) would turn the logout page into
// an open redirect / phishing hop on an app-trusted origin. Only the two
// legitimate shapes survive: a custom app scheme (com.example.app://...) or an
// http(s) URL pointing back at this same origin (the web flow).
const FORBIDDEN_PROTOCOLS = ['javascript:', 'data:', 'file:', 'blob:', 'vbscript:', 'about:']

// 7. Watch

// 8. Computed
const safeReturnTarget = computed(() => toReturnTarget({ raw: redirectUri.value }))

// 9. OnMounted
onMounted(async () => {
  try {
    await authController.logout()
  } catch {
    // Continue even if server logout fails — local state is already cleared
  }

  const redirectTarget = safeReturnTarget.value

  if (redirectTarget) {
    // System browsers may block non-gesture cross-scheme navigation, so
    // always offer an explicit tap target as fallback.
    showReturnButton.value = true
    window.location.href = redirectTarget
  } else {
    window.location.href = '/auth/login'
  }
})

// 10. Functions (named parameters pattern)
function toReturnTarget(params: { raw: string }): string {
  const { raw } = params
  if (!raw) return ''
  try {
    const parsed = new URL(raw)
    if (FORBIDDEN_PROTOCOLS.includes(parsed.protocol)) return ''
    if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
      return parsed.origin === window.location.origin ? parsed.href : ''
    }
    return parsed.href
  } catch {
    return ''
  }
}
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
