<!-- SPDX-License-Identifier: MIT -->
<template>
  <div class="section-header">
    <h3 class="section-title">Verificación en dos pasos (TOTP)</h3>
  </div>

  <div v-if="loading" class="loading-container" role="status" aria-live="polite">
    <ion-spinner name="lines"></ion-spinner>
    <p>Cargando estado TOTP...</p>
  </div>

  <div v-else-if="error" class="error-banner" role="alert">
    {{ error }}
  </div>

  <div v-else class="totp-content">
    <!-- Enrolled state -->
    <div v-if="status?.enabled" class="totp-enabled">
      <ion-card class="info-card">
        <ion-card-content>
          <p class="status-line">
            <ion-icon :icon="shieldCheckmarkOutline" color="success" />
            <span>TOTP habilitado. Al iniciar sesión, además de la contraseña se solicitará un código de {{ status.digits }} dígitos.</span>
          </p>
          <p v-if="status.confirmed_at" class="meta-line">
            Habilitado el {{ formatDate({ dateStr: status.confirmed_at }) }}.
          </p>
          <p class="meta-line">
            Códigos de respaldo sin usar:
            <strong>{{ status.backup_codes_remaining }}</strong>
          </p>
        </ion-card-content>
      </ion-card>

      <div class="action-row">
        <ion-button
          fill="outline"
          color="primary"
          :disabled="busy"
          @click="openRegenerateModal"
        >
          <ion-icon slot="start" :icon="refreshOutline" />
          Regenerar códigos de respaldo
        </ion-button>

        <ion-button
          fill="outline"
          color="danger"
          :disabled="busy"
          @click="openDisableModal"
        >
          <ion-icon slot="start" :icon="trash" />
          Deshabilitar TOTP
        </ion-button>
      </div>
    </div>

    <!-- Not enrolled: render the enrollment flow -->
    <div v-else-if="!enrollment.isPending.value" class="totp-disabled">
      <ion-card class="info-card">
        <ion-card-content>
          <p class="status-line">
            <ion-icon :icon="shieldOutline" color="medium" />
            <span>TOTP no está habilitado. Es una capa opcional de 2FA para usuarios que no pueden usar passkeys (por ejemplo, Firefox + Linux sin biometría).</span>
          </p>
        </ion-card-content>
      </ion-card>

      <ion-button
        expand="block"
        :disabled="busy"
        @click="startEnrollment"
      >
        <ion-icon slot="start" :icon="addOutline" />
        Habilitar TOTP
      </ion-button>
    </div>

    <!-- Pending enrollment: QR + confirm input -->
    <div v-else class="totp-pending">
      <ion-card class="info-card">
        <ion-card-content>
          <p>
            Escaneá este QR con tu app autenticadora (Google Authenticator, Authy, 1Password, Bitwarden, etc.):
          </p>
          <div v-if="qrDataUrl" class="qr-container">
            <img :src="qrDataUrl" alt="QR TOTP" class="qr-image" />
          </div>
          <p class="manual-entry">
            O ingresá manualmente la clave:
            <code class="secret-code">{{ enrollment.pending.value?.secret }}</code>
          </p>
          <p class="hint">
            La clave solo se muestra una vez. Si la perdés, deshabilitá TOTP y volvé a empezar.
          </p>
        </ion-card-content>
      </ion-card>

      <ion-item class="confirm-input">
        <ion-label position="stacked">Código de 6 dígitos</ion-label>
        <ion-input
          v-model="confirmCode"
          inputmode="numeric"
          pattern="[0-9]*"
          :maxlength="6"
          placeholder="000000"
          :disabled="busy"
        />
      </ion-item>

      <div class="action-row">
        <ion-button
          expand="block"
          :disabled="busy || confirmCode.length !== 6"
          @click="confirmEnrollment"
        >
          Confirmar y activar
        </ion-button>
        <ion-button
          expand="block"
          fill="outline"
          color="medium"
          :disabled="busy"
          @click="cancelEnrollment"
        >
          Cancelar
        </ion-button>
      </div>

      <div v-if="enrollment.error.value" class="error-banner" role="alert">
        {{ enrollment.error.value }}
      </div>
    </div>

    <!-- Backup codes display (after successful confirm) -->
    <div v-if="enrollment.backupCodes.value" class="backup-codes">
      <ion-card class="info-card">
        <ion-card-content>
          <h4 class="warning-title">
            <ion-icon :icon="warningOutline" color="warning" />
            Guardá estos códigos de respaldo en un lugar seguro
          </h4>
          <p>
            Cada código se usa una sola vez. Si perdés tu dispositivo TOTP, podés usar uno de estos códigos para iniciar sesión.
          </p>
          <ul class="codes-list">
            <li v-for="code in enrollment.backupCodes.value" :key="code" class="code-item">
              <code>{{ code }}</code>
            </li>
          </ul>
          <p class="danger-warning">
            <ion-icon :icon="eyeOffOutline" />
            Esta es la ÚNICA vez que vamos a mostrarte estos códigos en texto plano. Anotalos o descargá el archivo antes de cerrar.
          </p>
        </ion-card-content>
      </ion-card>

      <div class="action-row">
        <ion-button fill="outline" @click="downloadBackupCodes">
          <ion-icon slot="start" :icon="downloadOutline" />
          Descargar
        </ion-button>
        <ion-button fill="outline" @click="copyBackupCodes">
          <ion-icon slot="start" :icon="copyOutline" />
          Copiar al portapapeles
        </ion-button>
        <ion-button expand="block" color="primary" @click="acknowledgeBackupCodes">
          Ya los guardé
        </ion-button>
      </div>
    </div>
  </div>

  <!-- Disable confirmation modal -->
  <ion-modal :is-open="showDisableModal" @did-dismiss="showDisableModal = false">
    <ion-header>
      <ion-toolbar>
        <ion-title>Deshabilitar TOTP</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="showDisableModal = false">Cancelar</ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <p>Para deshabilitar TOTP necesitás confirmar tu contraseña actual.</p>
      <ion-item>
        <ion-label position="stacked">Contraseña</ion-label>
        <ion-input v-model="disablePassword" type="password" :disabled="busy" />
      </ion-item>
      <div v-if="modalError" class="error-banner" role="alert">{{ modalError }}</div>
      <ion-button expand="block" color="danger" :disabled="busy || !disablePassword" @click="confirmDisable">
        Deshabilitar
      </ion-button>
    </ion-content>
  </ion-modal>

  <!-- Regenerate backup codes modal -->
  <ion-modal :is-open="showRegenerateModal" @did-dismiss="showRegenerateModal = false">
    <ion-header>
      <ion-toolbar>
        <ion-title>Regenerar códigos de respaldo</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="showRegenerateModal = false">Cancelar</ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>
    <ion-content class="ion-padding">
      <p>Tus códigos anteriores serán invalidados. Confirmá con tu contraseña para generar un nuevo set.</p>
      <ion-item>
        <ion-label position="stacked">Contraseña</ion-label>
        <ion-input v-model="regeneratePassword" type="password" :disabled="busy" />
      </ion-item>
      <div v-if="modalError" class="error-banner" role="alert">{{ modalError }}</div>
      <ion-button expand="block" color="primary" :disabled="busy || !regeneratePassword" @click="confirmRegenerate">
        Regenerar
      </ion-button>
    </ion-content>
  </ion-modal>
</template>

<script setup lang="ts">
  // 1. Imports (ionic/vue/ionicons)
  import { onBeforeUnmount, onMounted, ref } from 'vue';
  import {
    IonCard,
    IonCardContent,
    IonIcon,
    IonButton,
    IonItem,
    IonLabel,
    IonInput,
    IonSpinner,
    IonModal,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonContent,
    toastController,
  } from '@ionic/vue';
  import {
    shieldCheckmarkOutline,
    shieldOutline,
    addOutline,
    refreshOutline,
    trash,
    warningOutline,
    eyeOffOutline,
    downloadOutline,
    copyOutline,
  } from 'ionicons/icons';
  import QRCode from 'qrcode';

  // 2. Controllers / Composables
  import { totpService, type TotpStatusResponse } from '@/services/totpService';
  import { useTotpEnrollment } from '@/composables/useTotpEnrollment';
  import { useAuthStore } from '@/stores/auth';
  import { cryptoService } from '@/services/cryptoService';
  import { api } from '@/services/api';

  // 3. Components
  // (none)

  // 4. Interface and types imports
  // (none — only inline refs)

  // 5. Main (props, emits, etc.)
  defineEmits<{
    changed: [];
  }>();

  // 6. Vars and const
  const status = ref<TotpStatusResponse | null>(null);
  const loading = ref(true);
  const busy = ref(false);
  const error = ref<string | null>(null);
  const qrDataUrl = ref<string | null>(null);
  const confirmCode = ref('');

  const enrollment = useTotpEnrollment({ service: totpService });

  const authStore = useAuthStore();
  // LBDS-8 review (F-09): QRCode.toDataURL is async; if the component
  // unmounts before the promise resolves, assigning to qrDataUrl.value
  // would mutate state on an unmounted ref (Vue warning, possible
  // memory leak). Track a mounted flag and skip the write after unmount.
  const mounted = ref(true);

  const showDisableModal = ref(false);
  const disablePassword = ref('');
  const showRegenerateModal = ref(false);
  const regeneratePassword = ref('');
  const modalError = ref<string | null>(null);

  // 7. Watch
  // (none — keep this section empty if no watchers)

  // 8. Computed
  // (none)

  // 9. OnMounted
  onMounted(async () => {
    mounted.value = true;
    await loadStatus();
  });

  onBeforeUnmount(() => {
    mounted.value = false;
  });

  // 10. Functions
  async function loadStatus() {
    loading.value = true;
    error.value = null;
    try {
      status.value = await totpService.status();
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Error al cargar el estado TOTP';
    } finally {
      loading.value = false;
    }
  }

  async function startEnrollment() {
    busy.value = true;
    error.value = null;
    try {
      await enrollment.start();
      if (enrollment.pending.value) {
        const dataUrl = await QRCode.toDataURL(enrollment.pending.value.otpauth_uri, {
          errorCorrectionLevel: 'M',
          margin: 2,
          width: 240,
        });
        // LBDS-8 review (F-09): don't write to a ref on an unmounted
        // component.
        if (mounted.value) {
          qrDataUrl.value = dataUrl;
        }
      }
    } catch (e) {
      if (mounted.value) {
        error.value = e instanceof Error ? e.message : 'Error al iniciar el enrollment';
      }
    } finally {
      if (mounted.value) {
        busy.value = false;
      }
    }
  }

  async function confirmEnrollment() {
    busy.value = true;
    error.value = null;
    const ok = await enrollment.confirm({ code: confirmCode.value });
    if (ok) {
      confirmCode.value = '';
      qrDataUrl.value = null;
      await loadStatus();
    } else {
      // enrollment.error is already set; mirror it for visibility.
      error.value = enrollment.error.value;
    }
    busy.value = false;
  }

  function cancelEnrollment() {
    enrollment.reset();
    qrDataUrl.value = null;
    confirmCode.value = '';
  }

  function acknowledgeBackupCodes() {
    enrollment.reset();
  }

  function openDisableModal() {
    disablePassword.value = '';
    modalError.value = null;
    busy.value = false; // reset busy defensively in case a prior op left it true (F-09 info)
    showDisableModal.value = true;
  }

  function openRegenerateModal() {
    regeneratePassword.value = '';
    modalError.value = null;
    busy.value = false;
    showRegenerateModal.value = true;
  }

  /**
   * Build the re-auth payload for disable/regenerate. Basic users send
   * the plaintext password; advanced (ZKE) users derive auth_proof
   * from the typed master password and the server-stored salt_a
   * (LBDS-8 review F-02).
   */
  async function buildReAuthPayload(params: { password: string }): Promise<{ password?: string; auth_proof?: string }> {
    if (authStore.user?.encryption_level !== 'advanced') {
      return { password: params.password };
    }

    // Fetch the user's crypto blobs (advanced users only) to read salt_a.
    // Cached on the auth store in the advanced login path; if we don't
    // have it, the user re-types and we recompute.
    const profile = await api.get<{ salt_a: string | null }>({ endpoint: '/user/profile' });
    if (!profile.salt_a) {
      throw new Error('No se pudo obtener la sal del usuario para derivar auth_proof.');
    }

    const keyA = await cryptoService.deriveKeyA({
      password: params.password,
      salt: profile.salt_a,
    });
    const authProof = await cryptoService.computeAuthProof({ keyA });
    return { auth_proof: authProof };
  }

  async function confirmDisable() {
    busy.value = true;
    modalError.value = null;
    try {
      const reAuth = await buildReAuthPayload({ password: disablePassword.value });
      await totpService.disable(reAuth);
      showDisableModal.value = false;
      await loadStatus();
    } catch (e) {
      modalError.value = e instanceof Error ? e.message : 'No se pudo deshabilitar TOTP';
    } finally {
      busy.value = false;
    }
  }

  async function confirmRegenerate() {
    busy.value = true;
    modalError.value = null;
    try {
      const reAuth = await buildReAuthPayload({ password: regeneratePassword.value });
      const response = await totpService.regenerateBackupCodes(reAuth);
      // LBDS-8 review (F-03): route the new codes through the
      // composable's setter instead of mutating the ref from outside.
      enrollment.setBackupCodes({ codes: response.backup_codes });
      showRegenerateModal.value = false;
      await loadStatus();
    } catch (e) {
      modalError.value = e instanceof Error ? e.message : 'No se pudo regenerar';
    } finally {
      busy.value = false;
    }
  }

  function downloadBackupCodes() {
    if (!enrollment.backupCodes.value) return;
    const text = enrollment.backupCodes.value.join('\n');
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `totp-backup-codes-${new Date().toISOString().slice(0, 10)}.txt`;
    a.click();
    URL.revokeObjectURL(url);
  }

  async function copyBackupCodes() {
    if (!enrollment.backupCodes.value) return;
    try {
      // LBDS-8 review (F-04): navigator.clipboard.writeText rejects in
      // non-secure contexts and on permission denial; surface the
      // failure instead of silently dropping it.
      await navigator.clipboard.writeText(enrollment.backupCodes.value.join('\n'));
      const toast = await toastController.create({
        message: 'Códigos copiados al portapapeles',
        duration: 2000,
      });
      await toast.present();
    } catch {
      const toast = await toastController.create({
        message: 'No se pudo copiar al portapapeles. Usá Descargar.',
        duration: 3000,
        color: 'warning',
      });
      await toast.present();
    }
  }

  function formatDate(params: { dateStr: string }): string {
    try {
      const date = new Date(params.dateStr);
      const pad = (n: number) => String(n).padStart(2, '0');
      return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    } catch {
      return params.dateStr;
    }
  }
</script>

<style scoped>
  .section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }
  .section-title {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
  }
  .loading-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 24px;
  }
  .error-banner {
    background: rgba(220, 53, 69, 0.1);
    border: 1px solid rgba(220, 53, 69, 0.3);
    border-radius: 6px;
    padding: 12px;
    margin: 12px 0;
    color: #dc3545;
  }
  .info-card {
    margin: 12px 0;
  }
  .status-line {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 0 0 8px 0;
  }
  .status-line ion-icon {
    flex-shrink: 0;
    margin-top: 2px;
  }
  .meta-line {
    margin: 4px 0;
    color: var(--ion-color-medium);
    font-size: 0.9em;
  }
  .action-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0;
  }
  .qr-container {
    display: flex;
    justify-content: center;
    padding: 16px;
    background: #ffffff;
    border-radius: 8px;
    margin: 12px 0;
  }
  .qr-image {
    width: 240px;
    height: 240px;
  }
  .manual-entry {
    text-align: center;
    margin: 12px 0;
  }
  .secret-code {
    display: inline-block;
    background: rgba(0, 0, 0, 0.05);
    padding: 4px 8px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 0.9em;
    user-select: all;
  }
  .hint {
    font-size: 0.85em;
    color: var(--ion-color-medium);
    margin: 8px 0;
  }
  .confirm-input {
    margin: 12px 0;
  }
  .warning-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 8px 0;
    font-size: 1rem;
  }
  .codes-list {
    list-style: none;
    padding: 0;
    margin: 12px 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }
  .code-item code {
    display: block;
    background: rgba(0, 0, 0, 0.05);
    padding: 8px;
    border-radius: 4px;
    font-family: monospace;
    text-align: center;
  }
  .danger-warning {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85em;
    color: var(--ion-color-warning-shade);
    margin-top: 8px;
  }
</style>