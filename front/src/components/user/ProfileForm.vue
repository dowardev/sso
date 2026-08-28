<!-- SPDX-License-Identifier: MIT -->
<template>
  <div class="profile-form-container">
    <!-- Información del Perfil -->
    <ion-card class="form-section">
      <ion-card-content>
        <h3 class="section-title">Información Personal</h3>
      
      <form class="profile-form" @submit.prevent="saveProfile">
        <div v-if="profileError" class="error-message">
          <ion-icon :icon="alertCircle" size="small"></ion-icon>
          {{ profileError }}
        </div>

        <!-- Advanced encryption notice -->
        <div v-if="initialUser && initialUser.encryption_level === 'advanced'" class="advanced-notice">
          <ion-icon :icon="lockClosed" color="primary" size="small"></ion-icon>
          <div>
            <strong>Perfil cifrado con conocimiento cero</strong>
            <p>Tu nombre, usuario y email están cifrados en este dispositivo. Para modificarlos, usa la aplicación cliente que descifra localmente.</p>
          </div>
        </div>

        <ion-item v-if="!isAdvancedUser" class="form-item" lines="none">
          <ion-label position="stacked">Nombre Completo *</ion-label>
          <ion-input
            v-model="form.name"
            autocomplete="name"
            aria-describedby="prof-name-error"
            :aria-invalid="v$.name.$dirty ? v$.name.$invalid : undefined"
            @ion-blur="v$.name.$touch()"
          />
        </ion-item>
        <div v-if="v$.name.$error" id="prof-name-error" class="field-error">
          {{ v$.name.$errors[0].$message }}
        </div>

        <ion-item v-if="!isAdvancedUser" class="form-item" lines="none">
          <ion-label position="stacked">Nombre de Usuario *</ion-label>
          <ion-input
            v-model="form.user"
            autocomplete="username"
            aria-describedby="prof-user-error"
            :aria-invalid="v$.user.$dirty ? v$.user.$invalid : undefined"
            @ion-blur="v$.user.$touch()"
          />
        </ion-item>
        <div v-if="v$.user.$error" id="prof-user-error" class="field-error">
          {{ v$.user.$errors[0].$message }}
        </div>

        <ion-item v-if="!isAdvancedUser" class="form-item" lines="none">
          <ion-label position="stacked">Correo Electrónico *</ion-label>
          <ion-input
            v-model="form.email"
            type="email"
            autocomplete="email"
            aria-describedby="prof-email-error"
            :aria-invalid="v$.email.$dirty ? v$.email.$invalid : undefined"
            @ion-blur="v$.email.$touch()"
          />
        </ion-item>
        <div v-if="v$.email.$error" id="prof-email-error" class="field-error">
          {{ v$.email.$errors[0].$message }}
        </div>

        <!-- Advanced user: show encrypted profile info -->
        <div v-if="isAdvancedUser" class="encrypted-info">
          <ion-item class="form-item" lines="none">
            <ion-label position="stacked">Login ID</ion-label>
            <ion-input
              :value="initialUser?.login_id || ''"
              readonly
              autocomplete="off"
            />
          </ion-item>
          <ion-item class="form-item" lines="none">
            <ion-label position="stacked">Salt A (Key_A derivation)</ion-label>
            <ion-input
              :value="initialUser?.salt_a || ''"
              readonly
              autocomplete="off"
            />
          </ion-item>
          <ion-item class="form-item" lines="none">
            <ion-label position="stacked">Wrapped DEK A</ion-label>
            <ion-input
              :value="initialUser?.wrapped_dek_a || ''"
              readonly
              autocomplete="off"
            />
          </ion-item>
        </div>

        <div v-if="!isAdvancedUser" class="ion-padding-top">
          <ion-button
            type="submit"
            expand="block"
            class="gradient-btn"
            :disabled="savingProfile"
          >
            <ion-spinner v-if="savingProfile" slot="start" name="lines"></ion-spinner>
            <span v-else>Guardar Cambios</span>
          </ion-button>
        </div>
      </form>
      </ion-card-content>
    </ion-card>

    <!-- Upgrade to Advanced Encryption (basic users only) -->
    <ion-card v-if="!isAdvancedUser" class="form-section">
      <ion-card-content>
        <h3 class="section-title">Cifrado Avanzado</h3>
        <p class="upgrade-description">
          Protege tu cuenta con cifrado de conocimiento cero: tu contraseña nunca sale de este
          dispositivo y tu perfil se cifra localmente. Se te mostrará una frase semilla de
          recuperación (24 palabras) que deberás guardar en un lugar seguro.
        </p>

        <form @submit.prevent="handleUpgrade">
          <div v-if="upgradeError" class="error-message">
            <ion-icon :icon="alertCircle" size="small"></ion-icon>
            {{ upgradeError }}
          </div>

          <div class="upgrade-requirements">
            <p><strong>Requisitos:</strong></p>
            <ul>
              <li>Tu contraseña actual (mínimo 12 caracteres recomendado)</li>
              <li>Guardar la frase semilla que se generará</li>
            </ul>
            <p class="warning-text">
              ⚠️ Esta acción es irreversible y cerrará todas tus sesiones.
                </p>
            <p class="warning-text">
              ⚠️ Al activarlo iniciás sesión solo con tu <strong>nombre de usuario</strong>, no con tu correo: el correo queda cifrado en tu dispositivo y el servidor ya no lo guarda.
            </p>
          </div>

          <ion-item class="form-item" lines="none">
            <ion-label position="stacked">Contraseña Actual *</ion-label>
            <ion-input
              v-model="upgradeForm.current_password"
              type="password"
              placeholder="Confirma tu contraseña para continuar"
              autocomplete="current-password"
            >
              <ion-input-password-toggle slot="end" />
            </ion-input>
          </ion-item>

          <div class="ion-padding-top">
            <ion-button
              type="submit"
              expand="block"
              color="primary"
              :disabled="upgrading || upgradeForm.current_password.length === 0"
            >
              <ion-spinner v-if="upgrading" slot="start" name="lines"></ion-spinner>
              <span v-if="upgrading">Generando claves...</span>
              <span v-else>Actualizar a Cifrado Avanzado</span>
            </ion-button>
          </div>
        </form>
      </ion-card-content>
    </ion-card>

    <!-- Seed Phrase Modal -->
    <ion-modal
      :is-open="showSeedModal"
      :breakpoints="[0, 1]"
      @did-dismiss="showSeedModal = false"
    >
      <ion-header class="ion-no-border" mode="ios">
        <ion-toolbar>
          <ion-title>Tu Frase Semilla</ion-title>
        </ion-toolbar>
      </ion-header>
      <ion-content class="ion-padding">
        <div class="seed-content">
          <div class="seed-warning">
            <ion-icon :icon="warning" color="warning" size="large"></ion-icon>
            <div>
              <h3>¡IMPORTANTE! Guarda esta frase en un lugar seguro</h3>
              <p>Esta es la <strong>única forma</strong> de recuperar tu cuenta si olvidas tu contraseña. Nadie puede recuperarla por ti.</p>
              <ul>
                <li>Escríbela en papel y guárdala segura</li>
                <li>No la guardes en capturas ni archivos digitales</li>
                <li>No la compartas con nadie</li>
              </ul>
            </div>
          </div>

          <div v-if="seedPhrase" class="seed-phrase">
            <div v-for="(word, index) in seedPhrase.split(' ')" :key="index" class="seed-word">
              <span class="seed-index">{{ index + 1 }}.</span>
              <span class="seed-text">{{ word }}</span>
            </div>
          </div>

          <div class="seed-actions">
            <ion-button fill="outline" expand="block" @click="copySeedPhrase">
              <ion-icon slot="start" :icon="copy"></ion-icon>
              Copiar al portapapeles
            </ion-button>
            <ion-button expand="block" color="success" @click="confirmSeedSaved">
              Ya la guardé, continuar
            </ion-button>
          </div>
        </div>
      </ion-content>
    </ion-modal>

    <!-- Cambio de Contraseña -->
    <ion-card class="form-section">
      <ion-card-content>
        <h3 class="section-title">Cambiar Contraseña</h3>
      
      <form class="profile-form" @submit.prevent="changePassword">
        <div v-if="passwordError" class="error-message">
          <ion-icon :icon="alertCircle" size="small"></ion-icon>
          {{ passwordError }}
        </div>

        <ion-item class="form-item" lines="none">
          <ion-label position="stacked">Contraseña Actual *</ion-label>
          <ion-input
            v-model="passwordForm.current_password"
            type="password"
            autocomplete="current-password"
            aria-describedby="prof-current-password-error"
            :aria-invalid="v$Password.current_password.$dirty ? v$Password.current_password.$invalid : undefined"
            @ion-blur="v$Password.current_password.$touch()"
          >
            <ion-input-password-toggle slot="end" />
          </ion-input>
        </ion-item>
        <div v-if="v$Password.current_password.$error" id="prof-current-password-error" class="field-error">
          {{ v$Password.current_password.$errors[0].$message }}
        </div>

        <ion-item class="form-item" lines="none">
          <ion-label position="stacked">Nueva Contraseña *</ion-label>
          <ion-input
            v-model="passwordForm.new_password"
            type="password"
            autocomplete="new-password"
            aria-describedby="prof-new-password-error"
            :aria-invalid="v$Password.new_password.$dirty ? v$Password.new_password.$invalid : undefined"
            @ion-blur="v$Password.new_password.$touch()"
          >
            <ion-input-password-toggle slot="end" />
          </ion-input>
        </ion-item>
        <div v-if="v$Password.new_password.$error" id="prof-new-password-error" class="field-error">
          {{ v$Password.new_password.$errors[0].$message }}
        </div>

        <ion-item class="form-item" lines="none">
          <ion-label position="stacked">Confirmar Nueva Contraseña *</ion-label>
          <ion-input
            v-model="passwordForm.new_password_confirmation"
            type="password"
            autocomplete="new-password"
            aria-describedby="prof-new-password-confirm-error"
            :aria-invalid="v$Password.new_password_confirmation.$dirty ? v$Password.new_password_confirmation.$invalid : undefined"
            @ion-blur="v$Password.new_password_confirmation.$touch()"
          >
            <ion-input-password-toggle slot="end" />
          </ion-input>
        </ion-item>
        <div v-if="v$Password.new_password_confirmation.$error" id="prof-new-password-confirm-error" class="field-error">
          {{ v$Password.new_password_confirmation.$errors[0].$message }}
        </div>

        <div class="ion-padding-top">
          <ion-button
            type="submit"
            expand="block"
            class="gradient-btn"
            :disabled="changingPassword"
          >
            <ion-spinner v-if="changingPassword" slot="start" name="lines"></ion-spinner>
            <span v-else>Actualizar Contraseña</span>
          </ion-button>
        </div>
      </form>
      </ion-card-content>
    </ion-card>

    <!-- Eliminar Cuenta -->
    <ion-card class="form-section danger-zone">
      <ion-card-content>
        <h3 class="section-title danger-title">Zona de Peligro</h3>
      <p class="danger-description">Una vez eliminada, la cuenta no se puede recuperar.</p>
      <ion-button
        expand="block"
        color="danger"
        fill="outline"
        @click="showDeleteModal = true"
      >
        <ion-icon slot="start" :icon="trashOutline" aria-hidden="true" />
        Eliminar Cuenta
      </ion-button>
      </ion-card-content>
    </ion-card>

    <!-- Delete Account Modal -->
    <ion-modal
      :is-open="showDeleteModal"
      :breakpoints="[0, 0.5]"
      initial-breakpoint="0.5"
      @did-dismiss="showDeleteModal = false"
    >
      <ion-header class="ion-no-border" mode="ios">
        <ion-toolbar>
          <ion-buttons slot="start">
            <ion-button @click="showDeleteModal = false">
              <ion-icon :icon="closeOutline"></ion-icon>
            </ion-button>
          </ion-buttons>
          <ion-title>Eliminar Cuenta</ion-title>
        </ion-toolbar>
      </ion-header>
      <ion-content class="ion-padding">
        <div class="delete-modal-content">
          <p class="delete-warning">
            Esta acción es <strong>permanente e irreversible</strong>. Se eliminarán todos tus datos, sesiones y autorizaciones.
          </p>

          <div v-if="deleteError" class="error-message">
            <ion-icon :icon="alertCircle" size="small"></ion-icon>
            {{ deleteError }}
          </div>

          <ion-item class="form-item" lines="none">
            <ion-label position="stacked">Escribe <strong>ELIMINAR</strong> para confirmar</ion-label>
            <ion-input
              v-model="deleteForm.confirmation"
              placeholder="ELIMINAR"
            />
          </ion-item>

          <ion-item class="form-item" lines="none">
            <ion-label position="stacked">Contraseña Actual *</ion-label>
            <ion-input
              v-model="deleteForm.password"
              type="password"
              placeholder="Tu contraseña"
            >
              <ion-input-password-toggle slot="end" />
            </ion-input>
          </ion-item>

          <div class="ion-padding-top">
            <ion-button
              expand="block"
              color="danger"
              :disabled="deletingAccount || deleteForm.confirmation !== 'ELIMINAR'"
              @click="handleDeleteAccount"
            >
              <ion-spinner v-if="deletingAccount" slot="start" name="lines"></ion-spinner>
              <span v-else>Eliminar mi cuenta</span>
            </ion-button>
          </div>
        </div>
      </ion-content>
    </ion-modal>
  </div>
</template>

<script setup lang="ts">
// 1. Imports (ionic/vue/ionicons)
import { ref, computed, onMounted } from 'vue';
import {
  IonCard,
  IonCardContent,
  IonItem,
  IonInput,
  IonButton,
  IonSpinner,
  IonInputPasswordToggle,
  IonIcon,
  IonModal,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButtons,
  IonLabel,
  IonContent,
} from '@ionic/vue';
import { alertCircle, trashOutline, closeOutline, lockClosed, warning, copy } from 'ionicons/icons';
import { useVuelidate } from '@vuelidate/core';
import { required, minLength, sameAs } from '@vuelidate/validators';
import { withMsg, useValidation } from '@/composables/useValidation';
import { useToast } from '@/composables/useToast';
import type { User, EncryptionLevel } from '@/types';

const { handleApiError } = useValidation();
const { showSuccess, showError } = useToast();

// 2. Controllers
import { useAuthController } from '@/controllers/authController';

const authController = useAuthController();

// 3. Components
// (none)

// 4. Interface and types imports
interface UserProfileFormProps extends Omit<User, 'name' | 'user' | 'email' | 'login_id'> {
  name: string | null;
  user: string | null;
  email: string | null;
  login_id: string | null;
  encryption_level: EncryptionLevel;
  salt_a?: string | null;
  wrapped_dek_a?: string | null;
  encrypted_profile?: string | null;
}

// 5. Main (props, emits, etc.)
const emit = defineEmits<{
  saved: [];
}>();

const props = defineProps<{
  initialUser: UserProfileFormProps | null;
}>();

// 6. Vars and const
const form = ref({
  name: '',
  user: '',
  email: '',
});

const passwordForm = ref({
  current_password: '',
  new_password: '',
  new_password_confirmation: '',
});

const savingProfile = ref(false);
const changingPassword = ref(false);
const profileError = ref('');
const passwordError = ref('');

const showDeleteModal = ref(false);
const deleteForm = ref({ confirmation: '', password: '' });
const deletingAccount = ref(false);
const deleteError = ref('');
// Upgrade to advanced encryption state (Phase 4)
const upgrading = ref(false);
const upgradeError = ref('');
const showSeedModal = ref(false);
const seedPhrase = ref('');
const upgradeForm = ref({ current_password: '' });

// 7. Watch
// (reactive via onMounted only, no watcher needed since initialUser is set once)

// 8. Computed
const isAdvancedUser = computed(() => props.initialUser?.encryption_level === 'advanced');

const rules = computed(() => ({
  name: { required: withMsg('El nombre es requerido', required) },
  user: {
    required: withMsg('El nombre de usuario es requerido', required),
    minLength: withMsg('El nombre de usuario debe tener al menos 3 caracteres', minLength(3)),
  },
  email: {
    required: withMsg('El correo electrónico es requerido', required),
    email: withMsg('El correo electrónico no es válido', required),
  },
}));

const passwordRules = computed(() => ({
  current_password: { required: withMsg('La contraseña actual es requerida', required) },
  new_password: {
    required: withMsg('La nueva contraseña es requerida', required),
    minLength: withMsg('La contraseña debe tener al menos 6 caracteres', minLength(6)),
  },
  new_password_confirmation: {
    required: withMsg('La confirmación de contraseña es requerida', required),
    sameAs: sameAs('new_password'),
  },
}));

const v$ = useVuelidate(rules, form);
const v$Password = useVuelidate(passwordRules, passwordForm);

// 9. OnMounted
onMounted(() => {
  updateFormFromProps();
});

// 10. Functions
const updateFormFromProps = (): void => {
  if (props.initialUser) {
    form.value = {
      name: props.initialUser.name || '',
      user: props.initialUser.user || '',
      email: props.initialUser.email || '',
    };
  }
};

const saveProfile = async (): Promise<void> => {
  // For advanced users, profile is updated via encrypted blobs in the client
  if (isAdvancedUser.value) {
    profileError.value = 'El perfil cifrado se actualiza desde el cliente local';
    return;
  }

  const isValid = await v$.value.$validate();
  if (!isValid) return;

  savingProfile.value = true;
  profileError.value = '';

  try {
    await authController.updateUserProfile({
      name: form.value.name,
      user: form.value.user,
      email: form.value.email,
    });
    emit('saved');
  } catch (err) {
    profileError.value = handleApiError(err);
  } finally {
    savingProfile.value = false;
  }
};

const changePassword = async (): Promise<void> => {
  const isValid = await v$Password.value.$validate();
  if (!isValid) return;

  changingPassword.value = true;
  passwordError.value = '';

  try {
    await authController.updateUserPassword({
      current_password: passwordForm.value.current_password,
      new_password: passwordForm.value.new_password,
      new_password_confirmation: passwordForm.value.new_password_confirmation,
    });

    passwordForm.value = {
      current_password: '',
      new_password: '',
      new_password_confirmation: '',
    };
    showSuccess({ message: 'Contraseña actualizada correctamente' });
  } catch (err) {
    passwordError.value = handleApiError(err);
  } finally {
    changingPassword.value = false;
  }
};

const handleDeleteAccount = async (): Promise<void> => {
  if (deleteForm.value.confirmation !== 'ELIMINAR') return;

  deletingAccount.value = true;
  deleteError.value = '';

  try {
    await authController.deleteAccount({
      password: deleteForm.value.password,
      confirmation: deleteForm.value.confirmation,
    });

    showDeleteModal.value = false;
    showSuccess({ message: 'Cuenta eliminada correctamente' });

    setTimeout(() => {
      authController.logout();
    }, 1500);
  } catch (err) {
    deleteError.value = handleApiError(err);
  } finally {
    deletingAccount.value = false;
  }
};

      /**
       * Upgrade basic user to advanced zero-knowledge encryption.
       * Generates all crypto material client-side and shows seed phrase.
       */
      const handleUpgrade = async (): Promise<void> => {
        if (!upgradeForm.value.current_password) {
          upgradeError.value = 'Debes ingresar tu contraseña actual';
          return;
        }

        upgrading.value = true;
        upgradeError.value = '';

        try {
          const result = await authController.upgradeToAdvanced({
            currentPassword: upgradeForm.value.current_password,
          });

          // Show seed phrase modal (one-time display)
          seedPhrase.value = result.seedPhrase;
          showSeedModal.value = true;

          // Clear form
          upgradeForm.value.current_password = '';

          // Notify parent to refresh profile (user is now advanced)
          emit('saved');
        } catch (err) {
          upgradeError.value = handleApiError(err);
        } finally {
          upgrading.value = false;
        }
      };

      const copySeedPhrase = async (): Promise<void> => {
        try {
          await navigator.clipboard.writeText(seedPhrase.value);
          showSuccess({ message: 'Frase semilla copiada al portapapeles' });
        } catch {
          showError({ message: 'No se pudo copiar. Selecciona y copia manualmente.' });
        }
      };

      const confirmSeedSaved = (): void => {
        showSeedModal.value = false;
        seedPhrase.value = '';
        showSuccess({ message: 'Cuenta actualizada a cifrado avanzado' });
      };
  </script>

<style scoped>
.profile-form-container {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.form-section {
  --background: var(--glass-bg);
  box-shadow: none;
  backdrop-filter: var(--glass-blur);
  -webkit-backdrop-filter: var(--glass-blur);
  border: 1px solid var(--glass-border);
  border-radius: var(--card-border-radius);
  margin: 0;
}

.form-section ion-card-content {
  --padding: 20px;
}

.section-title {
  margin: 0 0 16px 0;
  font-size: 14px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: var(--text-tertiary);
}

.profile-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.form-item {
  --background: transparent;
  --padding-start: 0;
  --padding-end: 0;
  --inner-padding-end: 0;
}

.form-item ion-input {
  --padding-start: 0;
}

.field-error {
  font-size: 12px;
  color: var(--ion-color-danger);
  margin-top: -8px;
  margin-bottom: 4px;
}

.error-message {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ion-color-danger);
  font-size: 13px;
  padding: 12px;
  background: rgba(var(--ion-color-danger-rgb), 0.1);
  border-radius: 4px;
}

.danger-zone {
  border: 1px solid rgba(var(--ion-color-danger-rgb), 0.3);
}

.danger-title {
  color: var(--ion-color-danger);
}

.danger-description {
  font-size: 13px;
  color: var(--text-secondary);
  margin: -8px 0 16px;
}

.delete-modal-content {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.delete-warning {
  font-size: 14px;
  color: var(--text-primary);
  line-height: 1.5;
}

.advanced-notice {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px;
  background: rgba(var(--ion-color-primary-rgb), 0.1);
  border-radius: 8px;
  margin-bottom: 16px;
}

.advanced-notice strong {
  color: var(--ion-color-primary);
  display: block;
  margin-bottom: 4px;
}

.advanced-notice p {
  margin: 0;
  font-size: 13px;
  color: var(--text-secondary);
}

.encrypted-info {
  margin-bottom: 16px;
}

.encrypted-info ion-item {
  --background: var(--ion-color-light);
}

.encrypted-info ion-input {
  --color: var(--text-tertiary);
}

@media (max-width: 374px) {
  .form-section ion-card-content {
    --padding: 16px;
  }
}
</style>