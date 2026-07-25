<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-title>Autorización Requerida</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content :fullscreen="true">
      <AuthCard title="Autorización Requerida" subtitle="">
        <template #header>
          <div class="auth-card-header">
            <div class="auth-logo-circle">
              <img alt="Logo SSO Server" src="@/assets/images/lambda_v1.svg" class="auth-logo" />
            </div>
            <h2>Autorización Requerida</h2>
            <p>
              La aplicación <strong>{{ clientName }}</strong> solicita acceder a tu
              información
            </p>
          </div>
        </template>

          <div v-if="!approved" class="data-access-section">
            <h3>Información que se compartirá:</h3>
            <ion-list class="data-list">
              <ion-item lines="none" class="data-item">
                <ion-icon slot="start" :icon="checkmarkCircle" color="primary" aria-hidden="true" />
                <ion-label>Nombre completo</ion-label>
              </ion-item>
              <ion-item lines="none" class="data-item">
                <ion-icon slot="start" :icon="checkmarkCircle" color="primary" aria-hidden="true" />
                <ion-label>Correo electrónico</ion-label>
              </ion-item>
              <ion-item lines="none" class="data-item">
                <ion-icon slot="start" :icon="checkmarkCircle" color="primary" aria-hidden="true" />
                <ion-label>Nombre de usuario</ion-label>
              </ion-item>
            </ion-list>
            <p class="data-note">
              Esta autorización será recordada para futuros accesos de esta
              aplicación.
            </p>
          </div>

          <div v-if="error" class="error-message" role="alert" aria-live="assertive">
            <ion-icon :icon="alertCircle" size="small"></ion-icon>
            {{ error }}
          </div>

          <div class="ion-padding-top action-buttons">
            <ion-button
              expand="block"
              class="gradient-btn"
              :disabled="loading"
              aria-label="Permitir y continuar"
              @click="handleApprove({})"
            >
              <ion-spinner v-if="loading" name="lines"></ion-spinner>
              <span v-else>Permitir y Continuar</span>
            </ion-button>
            <ion-button
              expand="block"
              fill="outline"
              :disabled="loading"
              class="deny-btn"
              aria-label="Denegar autorización"
              @click="handleDeny({})"
            >
              Denegar
            </ion-button>
          </div>
    </AuthCard>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonButton,
  IonSpinner,
  IonList,
  IonItem,
  IonLabel,
  IonIcon,
} from '@ionic/vue'
import { checkmarkCircle, alertCircle } from 'ionicons/icons'

// 2. Controllers / Composables
import { useAuthController } from '@/controllers/authController'
import { useValidation } from '@/composables/useValidation'
const authController = useAuthController()
const { handleApiError } = useValidation()

// 3. Components
import AuthCard from '@/components/AuthCard.vue'

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const error = ref('')
const approved = ref(false)
const clientName = ref('')

const oauthParams = ref({
  client_id: '',
  redirect_uri: '',
  state: '',
  response_type: '',
  code_challenge: '',
  code_challenge_method: '',
  code_verifier: '',
})

// 7. Watch

// 8. Computed

// 9. OnMounted
onMounted(async () => {
  const {
    client_id,
    redirect_uri,
    state,
    response_type,
    code_challenge,
    code_challenge_method,
    code_verifier,
    client_name,
  } = route.query

  if (client_name) {
    clientName.value = client_name as string
  }

  if (!client_id || !redirect_uri) {
    error.value = 'Parámetros de autorización inválidos'
    return
  }

  oauthParams.value = {
    client_id: client_id as string,
    redirect_uri: redirect_uri as string,
    state: (state as string) || '',
    response_type: (response_type as string) || 'code',
    code_challenge: (code_challenge as string) || '',
    code_challenge_method: (code_challenge_method as string) || '',
    code_verifier: (code_verifier as string) || '',
  }
})

// 10. Functions (named parameters pattern)
const handleApprove = async (_params: object = {}): Promise<void> => {
  if (!oauthParams.value.client_id) {
    error.value = 'Parámetros de autorización inválidos'
    return
  }

  loading.value = true
  error.value = ''

  try {
    await authController.approveOAuthAuthorization({
      client_id: oauthParams.value.client_id,
      redirect_uri: oauthParams.value.redirect_uri,
      state: oauthParams.value.state,
      response_type: oauthParams.value.response_type || 'code',
      code_challenge: oauthParams.value.code_challenge || undefined,
      code_challenge_method:
        oauthParams.value.code_challenge_method || undefined,
      code_verifier: oauthParams.value.code_verifier || undefined,
    })
    approved.value = true
  } catch (err: unknown) {
    console.error('[Authorization Error]', err)
    error.value = handleApiError(err)
  } finally {
    loading.value = false
  }
}

const handleDeny = async (_params: object = {}): Promise<void> => {
  loading.value = true
  error.value = ''

  try {
    await authController.denyOAuthAuthorization({
      client_id: oauthParams.value.client_id,
    })

    const redirectUri = oauthParams.value.redirect_uri
    if (redirectUri) {
      const urlParams = new URLSearchParams()
      urlParams.set('error', 'access_denied')
      if (oauthParams.value.state) {
        urlParams.set('state', oauthParams.value.state)
      }
      window.location.href = `${redirectUri}?${urlParams.toString()}`
    } else {
      router.replace('/')
    }
  } catch (err: unknown) {
    console.error('[Deny Error]', err)
    error.value = handleApiError(err)
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.auth-card-header {
  text-align: center;
  margin-bottom: clamp(20px, 4vw, 24px);
}

.auth-card-header h2 {
  font-family: var(--font-heading);
  font-size: clamp(22px, 5vw, 24px);
  font-weight: 700;
  color: var(--text-primary);
  margin: 0 0 8px 0;
}

.auth-card-header p {
  font-size: clamp(13px, 3vw, 14px);
  color: var(--text-tertiary);
  margin: 0;
  line-height: 1.5;
}

.data-access-section {
  margin: 16px 0;
}

.data-access-section h3 {
  font-size: clamp(14px, 3vw, 15px);
  font-weight: 600;
  color: var(--text-primary);
  margin: 0 0 8px 0;
}

.data-list {
  background: transparent;
  padding: 0;
}

.data-item {
  --background: transparent;
  --padding-start: 0;
  --inner-padding-end: 0;
  font-size: clamp(13px, 3vw, 14px);
  color: var(--text-secondary);
}

.data-item ion-icon {
  font-size: 20px;
  margin-right: 8px;
}

.data-note {
  font-size: clamp(12px, 2.5vw, 13px);
  color: var(--text-tertiary);
  margin: 12px 0 0 0;
  font-style: italic;
}

.action-buttons {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.deny-btn {
  --border-color: var(--text-tertiary);
  --color: var(--text-tertiary);
}

.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 8px 0;
}
</style>
