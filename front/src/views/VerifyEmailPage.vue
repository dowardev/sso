<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-title>Verificación de email</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content :fullscreen="true">
      <AuthCard title="Verificando tu email" subtitle="Un momento...">
        <div class="ion-padding-top ion-text-center">
          <ion-spinner v-if="loading" name="lines"></ion-spinner>
          <div v-if="error" class="error-message" role="alert" aria-live="assertive">
            {{ error }}
          </div>
          <div v-if="error" class="ion-padding-top">
            <ion-button fill="clear" expand="block" class="subtle" @click="goToLogin()">
              Ir a iniciar sesión
            </ion-button>
          </div>
        </div>
      </AuthCard>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { IonPage, IonHeader, IonToolbar, IonTitle, IonContent, IonButton, IonSpinner } from '@ionic/vue';

// 2. Controllers / Composables
import { api } from '@/services/api';
import { useToast } from '@/composables/useToast';
import { useValidation } from '@/composables/useValidation';
import {
  loadOauthPending,
  clearOauthPending,
  buildOauthLoginQuery,
} from '@/services/oauthPending';
import {
  loadEmailVerifyOrigin,
  clearEmailVerifyOrigin,
} from '@/services/emailVerifyOrigin';

// 3. Components
import AuthCard from '@/components/AuthCard.vue';

// 4. Interface and types imports

// 5. Main (props, emits, etc.)
const router = useRouter();
const route = useRoute();

// 6. Vars and const
const loading = ref(true);
const error = ref('');
const { showSuccess } = useToast();
const { handleApiError } = useValidation();

// 7. Watch

// 8. Computed

// 9. OnMounted
onMounted(async () => {
  const token = (route.params.token as string) || '';
  if (!token) {
    error.value = 'Enlace de verificación inválido.';
    loading.value = false;
    return;
  }

  try {
    await api.get({
      endpoint: `/email/verify/${token}`,
    });

    // Route to the login of the area the user registered from. The origin is
    // stored by the register page when it navigates to the pending screen.
    const origin = loadEmailVerifyOrigin();
    clearEmailVerifyOrigin();

    const pending = loadOauthPending();
    clearOauthPending();

    if (pending) {
      const query = buildOauthLoginQuery(pending);
      query.verified = 'true';
      await router.replace({ name: 'AuthLogin', query });
      showSuccess({ message: 'Email verificado. Ahora puedes iniciar sesión.' });
      return;
    }

    if (origin === 'admin') {
      await router.replace({ name: 'AdminLogin', query: { verified: 'true' } });
    } else {
      await router.replace({ name: 'AuthLogin', query: { verified: 'true' } });
    }
    showSuccess({ message: 'Email verificado. Ahora puedes iniciar sesión.' });
  } catch (err) {
    error.value = handleApiError(err);
    loading.value = false;
  }
});

// 10. Functions (named parameters pattern)
function goToLogin(_params: object = {}): void {
  router.replace({ name: 'AuthLogin' });
}
</script>

<style scoped>
.error-message {
  color: #b00020;
  font-size: 14px;
}
</style>