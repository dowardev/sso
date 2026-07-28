<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-header class="ion-no-border" mode="ios">
      <ion-toolbar>
        <ion-title>Configuración Inicial</ion-title>
      </ion-toolbar>
    </ion-header>
    <ion-content :fullscreen="true">
      <AuthCard title="Configuración Inicial" subtitle="Crea el primer usuario administrador del sistema">
        <form @submit.prevent="handleSetup({})">
            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.name"
                label-placement="floating"
                label="Nombre completo"
                type="text"
                placeholder="Ej: Juan Pérez"
                autocomplete="name"
                aria-describedby="setup-name-error"
                @ion-blur="v$.name.$touch()"
              />
            </ion-item>
            <div v-if="v$.name.$error" id="setup-name-error" class="field-error">
              {{ v$.name.$errors[0].$message }}
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.user"
                label-placement="floating"
                label="Nombre de usuario"
                type="text"
                placeholder="Ej: juanperez"
                autocomplete="username"
                aria-describedby="setup-user-error"
                @ion-blur="v$.user.$touch()"
              />
            </ion-item>
            <div v-if="v$.user.$error" id="setup-user-error" class="field-error">
              {{ v$.user.$errors[0].$message }}
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.email"
                label-placement="floating"
                label="Correo electrónico"
                type="email"
                placeholder="Ej: juan@ejemplo.com"
                autocomplete="email"
                aria-describedby="setup-email-error"
                @ion-blur="v$.email.$touch()"
              />
            </ion-item>
            <div v-if="v$.email.$error" id="setup-email-error" class="field-error">
              {{ v$.email.$errors[0].$message }}
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password"
                label="Contraseña"
                label-placement="floating"
                type="password"
                placeholder="Mínimo 10 caracteres"
                autocomplete="new-password"
                aria-describedby="setup-password-error"
                @ion-blur="v$.password.$touch()"
              >
                <ion-input-password-toggle slot="end" />
              </ion-input>
            </ion-item>
            <div v-if="form.password.length > 0 || v$.password.$dirty" id="setup-password-checklist" class="password-checklist" aria-live="polite">
              <div class="check-item" :class="{ valid: passwordChecks.minLength }">
                <span class="check-icon">
                  <ion-icon :icon="passwordChecks.minLength ? checkmarkCircleOutline : closeCircleOutline" />
                </span>
                <span>Al menos 10 caracteres</span>
              </div>
              <div class="check-item" :class="{ valid: passwordChecks.hasUpperCase }">
                <span class="check-icon">
                  <ion-icon :icon="passwordChecks.hasUpperCase ? checkmarkCircleOutline : closeCircleOutline" />
                </span>
                <span>Al menos una mayúscula</span>
              </div>
              <div class="check-item" :class="{ valid: passwordChecks.hasLowerCase }">
                <span class="check-icon">
                  <ion-icon :icon="passwordChecks.hasLowerCase ? checkmarkCircleOutline : closeCircleOutline" />
                </span>
                <span>Al menos una minúscula</span>
              </div>
              <div class="check-item" :class="{ valid: passwordChecks.hasNumber }">
                <span class="check-icon">
                  <ion-icon :icon="passwordChecks.hasNumber ? checkmarkCircleOutline : closeCircleOutline" />
                </span>
                <span>Al menos un número</span>
              </div>
              <div class="check-item" :class="{ valid: passwordChecks.hasSymbol }">
                <span class="check-icon">
                  <ion-icon :icon="passwordChecks.hasSymbol ? checkmarkCircleOutline : closeCircleOutline" />
                </span>
                <span>Al menos un símbolo especial (@ $ ! % * ? &)</span>
              </div>
            </div>

            <ion-item class="input-item" lines="none">
              <ion-input
                v-model="form.password_confirmation"
                label="Confirmar contraseña"
                label-placement="floating"
                type="password"
                placeholder="Repite la contraseña"
                autocomplete="new-password"
                aria-describedby="setup-password-confirm-error"
                @ion-blur="v$.password_confirmation.$touch()"
              >
                <ion-input-password-toggle slot="end" />
              </ion-input>
            </ion-item>
            <div v-if="v$.password_confirmation.$error" id="setup-password-confirm-error" class="field-error">
              {{ v$.password_confirmation.$errors[0].$message }}
            </div>

            <div v-if="error" class="error-message" role="alert" aria-live="assertive">
              <ion-icon :icon="alertCircle" size="small"></ion-icon>
              {{ error }}
            </div>

            <div class="ion-padding-top">
              <ion-button
                type="submit"
                expand="block"
                class="gradient-btn"
                :disabled="loading || (v$.$invalid && v$.$dirty)"
              >
                <ion-spinner v-if="loading" name="lines"></ion-spinner>
                <span v-else>Crear Administrador</span>
              </ion-button>
            </div>
          </form>
    </AuthCard>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { alertCircle, checkmarkCircleOutline, closeCircleOutline } from 'ionicons/icons'
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
} from '@ionic/vue'
import { useVuelidate } from '@vuelidate/core'
import { helpers, required, minLength, email, sameAs } from '@vuelidate/validators'

// 2. Controllers / Composables
import { useSetupController } from '@/controllers/setupController'
const setupController = useSetupController()

// 3. Components
import AuthCard from '@/components/AuthCard.vue'

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const
const router = useRouter()
const loading = ref(false)
const error = ref('')

const form = ref({
  name: '',
  user: '',
  email: '',
  password: '',
  password_confirmation: '',
})

// 7. Watch

// 8. Computed
const passwordChecks = computed(() => ({
  minLength: form.value.password.length >= 10,
  hasUpperCase: /[A-Z]/.test(form.value.password),
  hasLowerCase: /[a-z]/.test(form.value.password),
  hasNumber: /[0-9]/.test(form.value.password),
  hasSymbol: /[@$!%*?&]/.test(form.value.password),
}))

const rules = computed(() => ({
  name: { required },
  user: { required },
  email: { required, email },
  password: {
    required: helpers.withMessage('La contraseña es obligatoria.', required),
    minLength: helpers.withMessage('Debe tener al menos 10 caracteres.', minLength(10)),
    hasUpperCase: helpers.withMessage('Debe contener al menos una mayúscula.', helpers.regex(/[A-Z]/)),
    hasLowerCase: helpers.withMessage('Debe contener al menos una minúscula.', helpers.regex(/[a-z]/)),
    hasNumber: helpers.withMessage('Debe contener al menos un número.', helpers.regex(/[0-9]/)),
    hasSymbol: helpers.withMessage('Debe contener al menos un símbolo especial (@ $ ! % * ? &).', helpers.regex(/[@$!%*?&]/)),
  },
  password_confirmation: { required, sameAs: helpers.withMessage('Las contraseñas no coinciden.', sameAs(computed(() => form.value.password))) },
}))

const v$ = useVuelidate(rules, form)

// 9. OnMounted

// 10. Functions (named parameters pattern)
const handleSetup = async (_params: object = {}): Promise<void> => {
  const isValid = await v$.value.$validate()
  if (!isValid) return

  loading.value = true
  error.value = ''
  try {
    await setupController.createAdmin({
      name: form.value.name,
      user: form.value.user,
      email: form.value.email,
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
    })
    localStorage.setItem('setup_completed', 'true')
    await router.replace('/login')
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'Error al crear el administrador'
  } finally {
    loading.value = false
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

.password-checklist {
  margin: 8px 0;
  padding: 8px 12px;
  background: var(--ion-color-light);
  border-radius: 8px;
  font-size: 13px;
}

.check-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 2px 0;
  color: var(--ion-color-medium);
  transition: color 0.15s ease;
}

.check-item.valid {
  color: var(--ion-color-success);
}

.check-icon {
  font-size: 14px;
  width: 16px;
  text-align: center;
}
</style>
