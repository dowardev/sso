<!-- SPDX-License-Identifier: MIT -->
<template>
  <ion-page>
    <ion-content class="ion-padding">
      <AuthCard title="Recuperar contraseña" subtitle="Te ayudamos a volver a tu cuenta">
        <!-- Step A: identifier -->
        <form v-if="step === 'identifier'" @submit.prevent="submitIdentifier">
          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="identifier"
              label-placement="floating"
              label="Usuario o Email"
              type="text"
              placeholder="Ingresa tu usuario o email"
              autocomplete="username"
              :class="ionicValidationClasses(v$.identifier)"
              @ion-blur="v$.identifier.$touch()"
            />
          </ion-item>
          <div v-if="v$.identifier.$error" class="field-error">
            {{ v$.identifier.$errors[0].$message }}
          </div>

          <div v-if="error" class="error-message" role="alert" aria-live="assertive">
            <ion-icon :icon="alertCircle" size="small"></ion-icon>
            {{ error }}
          </div>

          <div class="ion-padding-top">
            <ion-button expand="block" type="submit" :disabled="loading">Continuar</ion-button>
          </div>
        </form>

        <!-- Basic: instructions sent -->
        <div v-else-if="step === 'basic-sent'" class="recovery-step">
          <p class="recovery-text">
            Si la cuenta existe, te enviamos instrucciones para restablecer tu contraseña a tu correo.
          </p>
          <ion-button fill="clear" expand="block" @click="goToLogin">Volver al inicio de sesión</ion-button>
        </div>

        <!-- Basic: reset with token -->
        <form v-else-if="step === 'basic-reset'" @submit.prevent="submitReset">
          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="password"
              label-placement="floating"
              label="Nueva contraseña"
              type="password"
              placeholder="Ingresa tu nueva contraseña"
              autocomplete="new-password"
              :class="ionicValidationClasses(v$.password)"
              @ion-blur="v$.password.$touch()"
            >
              <ion-input-password-toggle slot="end" />
            </ion-input>
          </ion-item>
          <div v-if="v$.password.$error" class="field-error">
            {{ v$.password.$errors[0].$message }}
          </div>

          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="passwordConfirmation"
              label-placement="floating"
              label="Confirmar contraseña"
              type="password"
              placeholder="Repite tu nueva contraseña"
              autocomplete="new-password"
              :class="ionicValidationClasses(v$.passwordConfirmation)"
              @ion-blur="v$.passwordConfirmation.$touch()"
            >
              <ion-input-password-toggle slot="end" />
            </ion-input>
          </ion-item>
          <div v-if="v$.passwordConfirmation.$error" class="field-error">
            {{ v$.passwordConfirmation.$errors[0].$message }}
          </div>

          <div v-if="error" class="error-message" role="alert" aria-live="assertive">
            <ion-icon :icon="alertCircle" size="small"></ion-icon>
            {{ error }}
          </div>

          <div class="ion-padding-top">
            <ion-button expand="block" type="submit" :disabled="loading">Restablecer contraseña</ion-button>
          </div>
        </form>

        <!-- Advanced (ZKE): seed phrase -->
        <form v-else-if="step === 'zke'" @submit.prevent="submitZke">
          <ion-item class="input-item" lines="none">
            <ion-textarea
              v-model="seedPhrase"
              label-placement="floating"
              label="Frase de recuperación"
              :auto-grow="true"
              :rows="3"
              placeholder="Ingresa tu frase de recuperación"
            ></ion-textarea>
          </ion-item>

          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="password"
              label-placement="floating"
              label="Nueva contraseña"
              type="password"
              placeholder="Ingresa tu nueva contraseña"
              autocomplete="new-password"
              :class="ionicValidationClasses(v$.password)"
              @ion-blur="v$.password.$touch()"
            >
              <ion-input-password-toggle slot="end" />
            </ion-input>
          </ion-item>
          <div v-if="v$.password.$error" class="field-error">
            {{ v$.password.$errors[0].$message }}
          </div>

          <ion-item class="input-item" lines="none">
            <ion-input
              v-model="passwordConfirmation"
              label-placement="floating"
              label="Confirmar contraseña"
              type="password"
              placeholder="Repite tu nueva contraseña"
              autocomplete="new-password"
              :class="ionicValidationClasses(v$.passwordConfirmation)"
              @ion-blur="v$.passwordConfirmation.$touch()"
            >
              <ion-input-password-toggle slot="end" />
            </ion-input>
          </ion-item>
          <div v-if="v$.passwordConfirmation.$error" class="field-error">
            {{ v$.passwordConfirmation.$errors[0].$message }}
          </div>

          <div v-if="error" class="error-message" role="alert" aria-live="assertive">
            <ion-icon :icon="alertCircle" size="small"></ion-icon>
            {{ error }}
          </div>

          <div class="ion-padding-top">
            <ion-button expand="block" type="submit" :disabled="loading">Restablecer contraseña</ion-button>
          </div>
        </form>

        <div class="auth-card-footer">
          <ion-button fill="clear" expand="block" :disabled="loading" @click="goToLogin">
            <ion-icon slot="start" :icon="arrowBack" aria-hidden="true"></ion-icon>
            Volver al inicio de sesión
          </ion-button>
        </div>
      </AuthCard>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ionicValidationClasses } from '@/utils/ionicValidation';
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import {
  IonPage,
  IonContent,
  IonItem,
  IonInput,
  IonTextarea,
  IonButton,
  IonIcon,
  IonInputPasswordToggle,
} from '@ionic/vue';
import { alertCircle, arrowBack } from 'ionicons/icons';
import useVuelidate from '@vuelidate/core';
import { required, minLength, sameAs, helpers } from '@vuelidate/validators';
import { useValidation, withMsg } from '@/composables/useValidation';
import { useToast } from '@/composables/useToast';

const { handleApiError } = useValidation();
const { showSuccess } = useToast();

// 2. Controllers / Composables
import { useAuthController } from '@/controllers/authController';
const authController = useAuthController();

// 3. Components
import AuthCard from '@/components/AuthCard.vue';

// 4. Interface and types imports

// 5. Main (props, emits, etc.)
const router = useRouter();
const route = useRoute();

// 6. Vars and const
type RecoveryStep = 'identifier' | 'basic-sent' | 'basic-reset' | 'zke';
const step = ref<RecoveryStep>('identifier');
const identifier = ref('');
const seedPhrase = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const loginId = ref('');
const tokenValue = ref('');
const loading = ref(false);
const error = ref('');

// 7. Watch
// (none)

// 8. Computed
const rules = computed(() => {
  const base: Record<string, Record<string, unknown>> = {};
  if (step.value === 'identifier') {
    base.identifier = { required: withMsg('El identificador es requerido', required) };
  }
  if (step.value === 'basic-reset' || step.value === 'zke') {
    base.password = {
      required: withMsg('La contraseña es requerida', required),
      minLength: withMsg('Mínimo 12 caracteres', minLength(12)),
      upper: withMsg('Debe incluir al menos una mayúscula', helpers.regex(/[A-Z]/)),
      lower: withMsg('Debe incluir al menos una minúscula', helpers.regex(/[a-z]/)),
      number: withMsg('Debe incluir al menos un número', helpers.regex(/[0-9]/)),
      special: withMsg('Debe incluir un símbolo (@$!%*?&)', helpers.regex(/[@$!%*?&]/)),
    };
    base.passwordConfirmation = {
      sameAs: withMsg('Las contraseñas no coinciden', sameAs(password)),
    };
  }
  return base;
});

const v$ = useVuelidate(rules, { identifier, password, passwordConfirmation });

// 9. OnMounted
onMounted(() => {
  const token = route.params.token;
  if (token && typeof token === 'string' && token.length > 0) {
    tokenValue.value = token;
    step.value = 'basic-reset';
  }
});

// 10. Functions
const submitIdentifier = async (): Promise<void> => {
  const isValid = await v$.value.$validate();
  if (!isValid) return;

  const id = identifier.value.trim();
  loading.value = true;
  error.value = '';
  try {
    // Email identifiers always belong to basic (email-backed) users.
    if (id.includes('@')) {
      await authController.forgotPassword({ identifier: id });
      step.value = 'basic-sent';
      return;
    }

    // A username may belong to an advanced (ZKE) user whose plaintext email/user
    // are null. Compute the deterministic login_id and probe /crypto/blobs:
    // 200 -> advanced (seed phrase flow); 404 -> basic (email flow).
    const lid = await authController.computeLoginId({ username: id });
    loginId.value = lid;
    try {
      await authController.fetchCryptoBlobs({ loginId: lid });
      step.value = 'zke';
    } catch {
      await authController.forgotPassword({ identifier: id });
      step.value = 'basic-sent';
    }
  } catch (err) {
    error.value = handleApiError(err);
  } finally {
    loading.value = false;
  }
};

const submitReset = async (): Promise<void> => {
  const isValid = await v$.value.$validate();
  if (!isValid) return;

  loading.value = true;
  error.value = '';
  try {
    await authController.resetPassword({
      token: tokenValue.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    });
    showSuccess({ message: 'Contraseña actualizada. Iniciá sesión.' });
    router.push('/login');
  } catch (err) {
    error.value = handleApiError(err);
  } finally {
    loading.value = false;
  }
};

const submitZke = async (): Promise<void> => {
  const isValid = await v$.value.$validate();
  if (!isValid) return;

  loading.value = true;
  error.value = '';
  try {
    await authController.recoveryComplete({
      login_id: loginId.value,
      seed_phrase: seedPhrase.value,
      new_password: password.value,
    });
    showSuccess({ message: 'Contraseña actualizada. Iniciá sesión.' });
    router.push('/login');
  } catch (err) {
    error.value = handleApiError(err);
  } finally {
    loading.value = false;
  }
};

const goToLogin = (): void => {
  router.push('/login');
};
</script>

<style scoped>
.recovery-step {
  padding: 8px 0;
}
.recovery-text {
  color: var(--text-tertiary);
  font-size: 14px;
  line-height: 1.6;
  margin: 0 0 16px;
}
.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 8px 0;
}
.field-error {
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 4px 0;
}
.auth-card-footer {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
</style>
