<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-card class="auth-screen">
    <div class="blob blob-1" aria-hidden="true"></div>
    <div class="blob blob-2" aria-hidden="true"></div>
    <div class="blob blob-3" aria-hidden="true"></div>

    <div class="auth-card-content">
      <slot name="header">
        <div class="auth-card-header">
          <div
            class="auth-logo-circle"
            :class="{ 'auth-logo-circle--plain': logoUrl }"
          >
            <img
              :src="logoUrl || lambdaLogo"
              :class="[
                'auth-logo',
                logoUrl ? 'auth-logo--custom' : 'auth-logo--filtered',
              ]"
              alt="Logo SSO Server"
            />
          </div>
          <h2>{{ title }}</h2>
          <p>{{ subtitle }}</p>
        </div>
      </slot>

      <slot />
    </div>
  </ion-card>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { onMounted } from 'vue'
import { IonCard } from '@ionic/vue'
import lambdaLogo from '@/assets/images/lambda_v1.svg'

// 2. Controllers / Composables
import { useBranding } from '@/composables/useBranding'
const { logoUrl, loadBranding } = useBranding()

// 3. Components

// 4. Interface and types imports

// 5. Main (props, emits, etc.)
defineProps<{
  title: string
  subtitle: string
}>()

// 6. Vars and const

// 7. Watch

// 8. Computed

// 9. OnMounted
onMounted(() => {
  void loadBranding()
})

// 10. Functions (named parameters pattern)
</script>

<style scoped>
.auth-screen {
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  background: var(--ion-background-color, #0A0F1D);
  padding: 24px;
}

.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.5;
  pointer-events: none;
  will-change: transform;
}

.blob-1 {
  width: 420px;
  height: 420px;
  background: var(--ion-color-primary);
  top: -10%;
  left: -5%;
  animation: blob-float-1 18s ease-in-out infinite;
}

.blob-2 {
  width: 350px;
  height: 350px;
  background: var(--ion-color-secondary);
  bottom: -10%;
  right: -5%;
  animation: blob-float-2 22s ease-in-out infinite;
}

.blob-3 {
  width: 280px;
  height: 280px;
  background: var(--ion-color-tertiary);
  top: 50%;
  left: 60%;
  animation: blob-float-3 20s ease-in-out infinite;
}

@keyframes blob-float-1 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(60px, 40px) scale(1.08); }
  66% { transform: translate(-30px, 70px) scale(0.95); }
}

@keyframes blob-float-2 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(-50px, -60px) scale(1.1); }
  66% { transform: translate(40px, -30px) scale(0.92); }
}

@keyframes blob-float-3 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33% { transform: translate(30px, -50px) scale(1.05); }
  66% { transform: translate(-60px, 20px) scale(0.97); }
}

.auth-card-content {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: min(420px, 100%);
  padding: clamp(24px, 5vw, 40px) clamp(16px, 4vw, 32px);
  animation: card-appear 0.6s ease both;
}

@keyframes card-appear {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.auth-card-header {
  text-align: center;
  margin-bottom: clamp(24px, 4vw, 32px);
}

.auth-logo-circle {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
  box-shadow: 0 4px 20px rgba(var(--gradient-primary-rgb), 0.3);
}

.auth-logo-circle--plain {
  width: auto;
  height: auto;
  background: transparent;
  box-shadow: none;
}

.auth-logo {
  max-width: 32px;
  height: auto;
}

.auth-logo--custom {
  max-width: 96px;
  max-height: 96px;
}

.auth-logo--filtered {
  filter: brightness(0) invert(1);
}

.auth-card-header h2 {
  font-family: var(--font-heading);
  font-size: clamp(22px, 5vw, 24px);
  font-weight: 700;
  color: var(--text-primary);
  margin: 0 0 6px 0;
}

.auth-card-header p {
  font-size: clamp(13px, 3vw, 14px);
  color: var(--text-tertiary);
  margin: 0;
}

@media (prefers-reduced-motion: reduce) {
  .blob {
    animation: none;
  }

  .auth-card-content {
    animation: none;
  }
}
</style>
