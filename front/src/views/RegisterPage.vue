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
          <!-- Encryption Level Selector -->
          <div class="encryption-selector">
            <ion-segment v-model="form.encryption_level" color="primary" class="ion-padding-horizontal">
              <ion-segment-button value="basic">
                <ion-label>Básico</ion-label>
              </ion-segment-button>
              <ion-segment-button value="advanced">
                <ion-label>Avanzado</ion-label>
              </ion-segment-button>
            </ion-segment>
            <p v-if="form.encryption_level === 'basic'" class="encryption-desc">
              <ion-icon slot="start" :icon="lockOpen"></ion-icon>
              Perfil almacenado en texto plano en el servidor. Recuperación por email estándar.
            </p>
            <p v-if="form.encryption_level === 'advanced'" class="encryption-desc">
              <ion-icon slot="start" :icon="lockClosed"></ion-icon>
              <strong>Cifrado de conocimiento cero:</strong> tu contraseña nunca sale de este dispositivo. Perfil cifrado con AES-256-GCM. Requiere guardar frase semilla (24 palabras) para recuperación.
            </p>
          </div>

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
              :disabled="form.encryption_level === 'advanced'"
              @ion-blur="v$.name.$touch()"
            />
          </ion-item>
          <div v-if="v$.name.$error" id="register-name-error" class="field-error">{{ v$.name.$errors[0].$message }}</div>

          <!-- Username -->
          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="form.user"
              :label="form.encryption_level === 'advanced' ? 'Usuario (no se almacena en servidor)' : 'Usuario'"
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
              :label="form.encryption_level === 'advanced' ? 'Email (no se almacena en servidor)' : 'Email'"
              label-placement="floating"
              type="email"
              placeholder="Ingresa tu email"
              autocomplete="email"
              aria-describedby="register-email-error"
              :disabled="form.encryption_level === 'advanced'"
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

          <div v-if="form.encryption_level === 'advanced' && form.password.length > 0" class="advanced-password-hint">
            <ion-icon :icon="informationCircle" size="small"></ion-icon>
            <span>Mínimo 12 caracteres para cifrado avanzado</span>
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
              :disabled="loading || cryptoLoading"
            >
              <ion-spinner v-if="loading || cryptoLoading" name="lines"></ion-spinner>
              <span v-if="cryptoLoading && !loading">{{ cryptoLoadingMessage }}</span>
              <span v-else-if="loading">Registrando...</span>
              <span v-else>Registrar</span>
            </ion-button>
          </div>
        </form>

        <!-- Seed Phrase Modal (Advanced) -->
        <ion-modal v-model:is-open="showSeedModal" :backdrop-breakpoint="0" :breakpoints="[0, 1]">
          <div class="seed-modal">
            <ion-header>
              <ion-toolbar>
                <ion-title>Tu Frase Semilla de Recuperación</ion-title>
              </ion-toolbar>
            </ion-header>
            <ion-content class="ion-padding">
              <div class="seed-warning">
                <ion-icon :icon="warning" color="warning" size="large"></ion-icon>
                <div>
                  <h3>¡IMPORTANTE! Guarda esta frase en un lugar seguro</h3>
                  <p>Esta frase de 24 palabras es la <strong>única forma</strong> de recuperar tu cuenta si olvidas tu contraseña. Nadie (ni nosotros) puede recuperarla por ti.</p>
                  <ul>
                    <li>Escríbela en papel y guárdala en un lugar seguro</li>
                    <li>No la guardes en capturas de pantalla ni archivos digitales</li>
                    <li>No la compartas con nadie</li>
                  </ul>
                </div>
              </div>

              <div v-if="seedPhrase" class="seed-phrase">
                <div
                  v-for="(word, index) in seedPhrase.split(' ')"
                  :key="index"
                  class="seed-word"
                >
                  <span class="seed-index">{{ index + 1 }}.</span>
                  <span class="seed-text">{{ word }}</span>
                </div>
              </div>

              <div class="seed-actions">
                <ion-button
                  fill="outline"
                  expand="block"
                  @click="copySeedPhrase"
                >
                  <ion-icon slot="start" :icon="copy" aria-hidden="true"></ion-icon>
                  Copiar al portapapeles
                </ion-button>
                <ion-button
                  expand="block"
                  color="success"
                  @click="confirmSeedSaved"
                >
                  Ya la guardé, continuar
                </ion-button>
              </div>
            </ion-content>
          </div>
        </ion-modal>

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
import { ref, computed, onMounted, watch } from 'vue'
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
  IonSegment,
  IonSegmentButton,
  IonLabel,
  IonModal,
  loadingController,
} from '@ionic/vue'
import { arrowBack, alertCircle, lockOpen, lockClosed, warning, informationCircle, copy } from 'ionicons/icons'
import { useVuelidate } from '@vuelidate/core'
import { required, email, minLength, sameAs } from '@vuelidate/validators'

// 2. Controllers / Composables
import { useAuthController } from '@/composables/authController'
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
  encryption_level: 'basic' as 'basic' | 'advanced',
})

const loading = ref(false)
const cryptoLoading = ref(false)
const cryptoLoadingMessage = ref('')
const error = ref('')
const showSeedModal = ref(false)
const seedPhrase = ref('')

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
    minLength: withMsg(
      form.value.encryption_level === 'advanced'
        ? 'La contraseña debe tener al menos 12 caracteres para cifrado avanzado'
        : 'La contraseña debe tener al menos 8 caracteres',
      minLength(form.value.encryption_level === 'advanced' ? 12 : 8),
    ),
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
watch(() => form.value.encryption_level, (newLevel) => {
  // Clear fields that are not used in advanced mode
  if (newLevel === 'advanced') {
    // For advanced, name/email are encrypted client-side and not stored on server
    // But we still need them for the encrypted profile
  }
  // Reset validation
  v$.value.$reset()
})

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

  if (form.value.encryption_level === 'advanced') {
    await handleAdvancedRegister()
  } else {
    await handleBasicRegister()
  }
}

const handleBasicRegister = async (): Promise<void> => {
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

const handleAdvancedRegister = async (): Promise<void> => {
  loading.value = false
  cryptoLoading.value = true
  cryptoLoadingMessage.value = 'Generando claves y cifrando perfil...'
  error.value = ''

  const loader = await loadingController.create({
    message: cryptoLoadingMessage.value,
  })
  await loader.present()

  try {
    const result = await authController.registerAdvanced({
      name: form.value.name,
      user: form.value.user,
      email: form.value.email,
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
      client_id: oauthParams.value.client_id || undefined,
    })

    await loader.dismiss()
    cryptoLoading.value = false
    cryptoLoadingMessage.value = ''

    // Show seed phrase modal
    seedPhrase.value = result.seed_phrase
    showSeedModal.value = true

    // Store OAuth context for after seed confirmation
    if (oauthParams.value.client_id) {
      saveOauthPending(oauthParams.value)
    }
    saveEmailVerifyOrigin('admin')

  } catch (err: unknown) {
    await loader.dismiss()
    cryptoLoading.value = false
    cryptoLoadingMessage.value = ''
    console.error('[Advanced Register Error]', err)
    error.value = handleApiError(err)
  }
}

const copySeedPhrase = async (): Promise<void> => {
  try {
    await navigator.clipboard.writeText(seedPhrase.value)
    showSuccess({ message: 'Frase semilla copiada al portapapeles' })
  } catch {
    showSuccess({ message: 'No se pudo copiar. Selecciona y copia manualmente.' })
  }
}

const confirmSeedSaved = (): void => {
  showSeedModal.value = false
  const query: Record<string, string> = {}
  if (form.value.email) {
    query.email = form.value.email
  }
  router.push({ name: 'VerifyEmailPending', query })
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

.encryption-selector {
  margin-bottom: 16px;
}

.encryption-desc {
  font-size: 12px;
  color: var(--text-tertiary);
  margin-top: 8px;
  padding: 8px 12px;
  background: var(--ion-color-light);
  border-radius: 8px;
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.encryption-desc strong {
  color: var(--ion-color-primary);
}

.advanced-password-hint {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-primary);
  font-size: 12px;
  padding: 4px 0 8px;
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

.seed-modal {
  --height: 90%;
}

.seed-warning {
  display: flex;
  gap: 12px;
  padding: 16px;
  background: var(--ion-color-warning-tint);
  border-radius: 12px;
  margin-bottom: 16px;
}

.seed-warning h3 {
  margin: 0 0 8px;
  color: var(--ion-color-warning);
  font-size: 16px;
}

.seed-warning p {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--text-secondary);
}

.seed-warning ul {
  margin: 0;
  padding-left: 20px;
  font-size: 13px;
  color: var(--text-secondary);
}

.seed-warning li {
  margin-bottom: 4px;
}

.seed-phrase {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  margin-bottom: 24px;
}

.seed-word {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 12px 8px;
  background: var(--ion-color-light);
  border-radius: 8px;
  font-family: monospace;
  font-size: 13px;
}

.seed-index {
  font-size: 10px;
  color: var(--text-tertiary);
  margin-bottom: 2px;
}

.seed-text {
  font-weight: 500;
  color: var(--text-primary);
  word-break: break-all;
}

.seed-actions {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.auth-card-footer {
  margin-top: var(--space-4, 16px);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-2, 8px);
}

@media (max-width: 480px) {
  .seed-phrase {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (max-width: 374px) {
  .seed-phrase {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>