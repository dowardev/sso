<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-title>Verifica tu email</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content :fullscreen="true">
      <AuthCard title="Revisa tu email" subtitle="Completa tu registro">
        <div class="ion-padding-top">
          <p class="pending-text">
            Te enviamos un enlace de verificación a <strong>{{ email }}</strong>.
            Revisa tu bandeja de entrada y haz clic en el botón para confirmar tu
            dirección de email. El enlace es válido por 30 minutos.
          </p>
          <div v-if="error" class="error-message" role="alert" aria-live="assertive">
            {{ error }}
          </div>
          <div class="ion-padding-top">
            <ion-button
              expand="block"
              class="gradient-btn"
              :disabled="loading"
              @click="handleResend()"
            >
              <ion-spinner v-if="loading" name="lines"></ion-spinner>
              <span v-else>Reenviar enlace</span>
            </ion-button>
          </div>
          <div class="auth-card-footer">
            <ion-button fill="clear" expand="block" class="subtle" @click="goToLogin()">
              Ya verifiqué mi email — Iniciar sesión
            </ion-button>
          </div>
        </div>
      </AuthCard>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { IonPage, IonHeader, IonToolbar, IonTitle, IonContent, IonButton, IonSpinner } from '@ionic/vue';

// 2. Controllers / Composables
import { api } from '@/services/api';
import { useToast } from '@/composables/useToast';
import { useValidation } from '@/composables/useValidation';
import {
  loadOauthPending,
  buildOauthLoginQuery,
  refreshOauthPendingTtl,
} from '@/services/oauthPending';
import { loadEmailVerifyOrigin } from '@/services/emailVerifyOrigin';

// 3. Components
import AuthCard from '@/components/AuthCard.vue';

// 4. Interface and types imports

// 5. Main (props, emits, etc.)
const router = useRouter();
const route = useRoute();

// 6. Vars and const
const email = ref((route.query.email as string) || '');
const loading = ref(false);
const error = ref('');
const { showSuccess } = useToast();
const { handleApiError } = useValidation();

// 7. Watch

// 8. Computed

// 9. OnMounted

// 10. Functions (named parameters pattern)
async function handleResend(_params: object = {}): Promise<void> {
  if (!email.value) {
    error.value = 'No se pudo identificar el email. Vuelve al registro e intenta de nuevo.';
    return;
  }

  loading.value = true;
  error.value = '';
  try {
    await api.post({
      endpoint: '/resend-confirmation-email',
      data: { email: email.value },
    });
    // The resend created a fresh 30-min token; keep the OAuth context aligned.
    refreshOauthPendingTtl();
    showSuccess({ message: 'Si el email existe, recibirás un enlace de verificación.' });
  } catch (err) {
    error.value = handleApiError(err);
  } finally {
    loading.value = false;
  }
}

function goToLogin(_params: object = {}): void {
  const pending = loadOauthPending();
  if (pending) {
    const query = buildOauthLoginQuery(pending);
    router.replace({ name: 'AuthLogin', query });
    return;
  }

  const origin = loadEmailVerifyOrigin();
  if (origin === 'admin') {
    router.replace({ name: 'AdminLogin' });
  } else {
    router.replace({ name: 'AuthLogin' });
  }
}
</script>

<style scoped>
.pending-text {
  color: #444444;
  font-size: 15px;
  line-height: 1.6;
  margin: 0;
}
.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: #b00020;
  font-size: 14px;
  margin-top: 12px;
}
</style>