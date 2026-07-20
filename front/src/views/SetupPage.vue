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
                placeholder="Mínimo 6 caracteres"
                autocomplete="new-password"
                aria-describedby="setup-password-error"
                @ion-blur="v$.password.$touch()"
              >
                <ion-input-password-toggle slot="end" />
              </ion-input>
            </ion-item>
            <div v-if="v$.password.$error" id="setup-password-error" class="field-error">
              {{ v$.password.$errors[0].$message }}
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

            <div v-if="setupController.error.value" class="error-message" role="alert" aria-live="assertive">
              <ion-icon :icon="alertCircle" size="small"></ion-icon>
              {{ setupController.error.value }}
            </div>

            <div class="ion-padding-top">
              <ion-button
                type="submit"
                expand="block"
                class="gradient-btn"
                :disabled="setupController.loading.value"
              >
                <ion-spinner v-if="setupController.loading.value" name="lines"></ion-spinner>
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
import { alertCircle } from 'ionicons/icons'
import { useVuelidate } from '@vuelidate/core'
import { required, minLength, email, sameAs } from '@vuelidate/validators'

// 2. Controllers / Composables
import { useSetupController } from '@/controllers/setupController'
const setupController = useSetupController()

// 3. Components
import AuthCard from '@/components/AuthCard.vue'

// 4. Interface and types imports

// 5. Main (props, emits, etc.)

// 6. Vars and const
const router = useRouter()

const form = ref({
  name: '',
  user: '',
  email: '',
  password: '',
  password_confirmation: '',
})

// 7. Watch

// 8. Computed
const rules = computed(() => ({
  name: { required: required },
  user: { required: required },
  email: { required: required, email: email },
  password: { required: required, minLength: minLength(6) },
  password_confirmation: { required: required, sameAs: sameAs(computed(() => form.value.password)) },
}))

const v$ = useVuelidate(rules, form)

// 9. OnMounted

// 10. Functions (named parameters pattern)
const handleSetup = async (_params: object = {}): Promise<void> => {
  const isValid = await v$.value.$validate()
  if (!isValid) return

  try {
    await setupController.createAdmin({
      name: form.value.name,
      user: form.value.user,
      email: form.value.email,
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
    })
    localStorage.setItem('setup_completed', 'true')
    router.replace('/login')
  } catch {
    // error is stored in setupController.error
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
</style>
