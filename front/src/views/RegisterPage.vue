<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-title>Crear Cuenta</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content :fullscreen="true">
      <AuthCard title="Crear Cuenta" subtitle="Regístrate para acceder">
        <form @submit.prevent="handleRegister">
          <!-- Name -->
          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="form.name"
              label="Nombre"
              label-placement="floating"
              type="text"
              placeholder="Ingresa tu nombre"
              autocomplete="name"
              aria-describedby="register-name-error"
              @ion-blur="v$.name.$touch()"
            />
          </ion-item>
          <div v-if="v$.name.$error" id="register-name-error" class="field-error">{{ v$.name.$errors[0].$message }}</div>

          <!-- Username -->
          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="form.user"
              label="Usuario"
              label-placement="floating"
              type="text"
              placeholder="Ingresa tu usuario"
              autocomplete="username"
              aria-describedby="register-user-error"
              @ion-blur="v$.user.$touch()"
            />
          </ion-item>
          <div v-if="v$.user.$error" id="register-user-error" class="field-error">{{ v$.user.$errors[0].$message }}</div>

          <!-- Email -->
          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="form.email"
              label="Email"
              label-placement="floating"
              type="email"
              placeholder="Ingresa tu email"
              autocomplete="email"
              aria-describedby="register-email-error"
              @ion-blur="v$.email.$touch()"
            />
          </ion-item>
          <div v-if="v$.email.$error" id="register-email-error" class="field-error">{{ v$.email.$errors[0].$message }}</div>

          <!-- Password -->
          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="form.password"
              label="Contraseña"
              label-placement="floating"
              type="password"
              placeholder="Ingresa tu contraseña"
              autocomplete="new-password"
              aria-describedby="register-password-error"
              @ion-blur="v$.password.$touch()"
            >
              <ion-input-password-toggle slot="end" />
            </ion-input>
          </ion-item>

          <div v-if="form.password.length > 0" class="strength-bar-container">
            <div
              class="strength-bar"
              :style="{ width: passwordStrength.percent + '%' }"
              :class="'strength-' + passwordStrength.level"
              role="progressbar"
              :aria-valuenow="passwordStrength.percent"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-label="'Fortaleza de la contraseña: ' + passwordStrength.label"
            ></div>
            <span class="strength-label" aria-live="polite">{{ passwordStrength.label }}</span>
          </div>

          <div v-if="v$.password.$error" id="register-password-error" class="field-error">{{ v$.password.$errors[0].$message }}</div>

          <!-- Password Confirmation -->
          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="form.password_confirmation"
              label="Confirmar contraseña"
              label-placement="floating"
              type="password"
              placeholder="Confirma tu contraseña"
              autocomplete="new-password"
              aria-describedby="register-password-confirm-error"
              @ion-blur="v$.password_confirmation.$touch()"
            />
          </ion-item>
          <div v-if="v$.password_confirmation.$error" id="register-password-confirm-error" class="field-error">{{ v$.password_confirmation.$errors[0].$message }}</div>

          <!-- Error message -->
          <div v-if="error" class="error-message" role="alert" aria-live="assertive">
            <ion-icon :icon="alertCircle" size="small"></ion-icon>
            {{ error }}
          </div>

          <!-- Submit button -->
          <div class="ion-padding-top">
            <ion-button
              type="submit"
              expand="block"
              class="gradient-btn"
              :disabled="loading"
            >
              <ion-spinner v-if="loading" name="lines"></ion-spinner>
              <span v-if="loading">Registrando...</span>
              <span v-else>Registrar</span>
            </ion-button>
          </div>
        </form>

        <div class="auth-card-footer">
          <ion-button fill="clear" expand="block" class="subtle" @click="goToLogin">
            Ya tengo cuenta
          </ion-button>
          <ion-button fill="clear" expand="block" class="subtle" @click="goBack">
            <ion-icon slot="start" :icon="arrowBack" aria-hidden="true"></ion-icon>
            Volver
          </ion-button>
        </div>
      </AuthCard>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  IonPage,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonItem,
  IonInput,
  IonButton,
  IonSpinner,
  IonIcon,
  IonInputPasswordToggle,
  loadingController,
} from '@ionic/vue'
import { arrowBack, alertCircle } from 'ionicons/icons'
import { useVuelidate } from '@vuelidate/core'
import { required, email, minLength, sameAs } from '@vuelidate/validators'

// 2. Controllers / Composables
import { useAuthController } from '@/controllers/authController'
import { useValidation, withMsg } from '@/composables/useValidation'
const authController = useAuthController()
const { handleApiError } = useValidation()

import { useToast } from '@/composables/useToast'
const { showSuccess } = useToast()

import { saveOauthPending } from '@/services/oauthPending'
import { saveEmailVerifyOrigin } from '@/services/emailVerifyOrigin'

// 3. Components
import AuthCard from '@/components/AuthCard.vue'

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const
const router = useRouter()
const route = useRoute()

const form = ref({
  name: '',
  user: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const loading = ref(false)
const error = ref('')

const rules = computed(() => ({
  name: {
    required: withMsg('El nombre es requerido', required),
  },
  user: {
    required: withMsg('El usuario es requerido', required),
    minLength: withMsg('El usuario debe tener al menos 3 caracteres', minLength(3)),
  },
  email: {
    required: withMsg('El email es requerido', required),
    email: withMsg('Debe ser un email válido', email),
  },
  password: {
    required: withMsg('La contraseña es requerida', required),
    minLength: withMsg('La contraseña debe tener al menos 12 caracteres', minLength(12)),
  },
  password_confirmation: {
    required: withMsg('Debe confirmar la contraseña', required),
    sameAs: withMsg('Las contraseñas no coinciden', sameAs(computed(() => form.value.password))),
  },
}))

const v$ = useVuelidate(rules, form)

const passwordStrength = computed(() => {
  const pwd = form.value.password
  if (pwd.length === 0) return { percent: 0, level: 'none', label: '' }

  let score = 0
  if (pwd.length >= 6) score += 25
  if (pwd.length >= 10) score += 15
  if (pwd.length >= 12) score += 10
  if (/[a-z]/.test(pwd)) score += 10
  if (/[A-Z]/.test(pwd)) score += 10
  if (/\d/.test(pwd)) score += 10
  if (/[^a-zA-Z0-9]/.test(pwd)) score += 20

  if (score >= 90) return { percent: 100, level: 'very-strong', label: 'Muy fuerte' }
  if (score >= 70) return { percent: 75, level: 'strong', label: 'Fuerte' }
  if (score >= 45) return { percent: 50, level: 'medium', label: 'Media' }
  return { percent: 25, level: 'weak', label: 'Débil' }
})

const oauthParams = ref({
  client_id: '',
  redirect_uri: '',
  state: '',
  response_type: '',
  code_challenge: '',
  code_challenge_method: '',
  code_verifier: '',
})

const isOAuthFlow = ref(false)

// 7. Watch

// 8. Computed

// 9. OnMounted
onMounted(() => {
  const {
    client_id,
    redirect_uri,
    state,
    response_type,
    code_challenge,
    code_challenge_method,
    code_verifier,
  } = route.query
  if (client_id && redirect_uri) {
    isOAuthFlow.value = true
    oauthParams.value = {
      client_id: client_id as string,
      redirect_uri: redirect_uri as string,
      state: (state as string) || '',
      response_type: (response_type as string) || 'code',
      code_challenge: (code_challenge as string) || '',
      code_challenge_method: (code_challenge_method as string) || '',
      code_verifier: (code_verifier as string) || '',
    }
  }
})

// 10. Functions
const handleRegister = async (): Promise<void> => {
  const isValid = await v$.value.$validate()
  if (!isValid) return

  loading.value = true
  error.value = ''

  const loader = await loadingController.create({
    message: 'Creando cuenta...',
  })
  await loader.present()

  try {
    await authController.register({
      name: form.value.name,
      user: form.value.user,
      email: form.value.email,
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
      client_id: oauthParams.value.client_id || undefined,
      encryption_level: 'basic',
    })
    await loader.dismiss()

    if (oauthParams.value.client_id) {
      saveOauthPending(oauthParams.value)
    }

    saveEmailVerifyOrigin('admin')

    showSuccess({ message: 'Revisa tu email para completar el registro.' })

    const query: Record<string, string> = {}
    if (form.value.email) {
      query.email = form.value.email
    }
    await router.push({ name: 'VerifyEmailPending', query })
  } catch (err: unknown) {
    await loader.dismiss()
    console.error('[Register Error]', err)
    error.value = handleApiError(err)
  } finally {
    loading.value = false
  }
}

const goToLogin = (): void => {
  const query: Record<string, string> = {}
  if (isOAuthFlow.value) {
    query.client_id = oauthParams.value.client_id
    query.redirect_uri = oauthParams.value.redirect_uri
    query.response_type = oauthParams.value.response_type || 'code'
    if (oauthParams.value.state) {
      query.state = oauthParams.value.state
    }
    if (oauthParams.value.code_challenge) {
      query.code_challenge = oauthParams.value.code_challenge
      query.code_challenge_method = oauthParams.value.code_challenge_method
    }
  }
  router.push({ name: 'AdminLogin', query })
}

const goBack = (): void => {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.replace('/')
  }
}
</script>

<style scoped>
.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 8px 0;
}

.strength-bar-container {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 0 8px;
}

.strength-bar {
  height: 4px;
  border-radius: 999px;
  transition: all 0.3s ease;
  flex: 1;
  max-width: clamp(80px, 25vw, 160px);
}

.strength-bar.strength-weak { background: var(--ion-color-danger); }
.strength-bar.strength-medium { background: var(--ion-color-warning); }
.strength-bar.strength-strong { background: var(--ion-color-success); }
.strength-bar.strength-very-strong { background: var(--ion-color-primary); }

.strength-label {
  font-size: 11px;
  color: var(--text-tertiary);
  white-space: nowrap;
}

.auth-card-footer {
  margin-top: var(--space-4, 16px);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-2, 8px);
}
</style>
