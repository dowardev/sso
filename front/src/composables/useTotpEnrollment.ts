// SPDX-License-Identifier: MIT

/**
 * TOTP enrollment state composable.
 *
 * Holds the in-flight enrollment (secret + otpauth URI + QR data-URL) and
 * the resulting backup codes the user must save. Centralizes the load
 * state and error reporting so the settings UI does not duplicate the
 * retry / loading / error handling logic.
 *
 * Does NOT call the API directly — it receives a {@link TotpService}-like
 * object so it stays trivial to mock in Vitest.
 */

import { computed, ref } from 'vue';
import type {
  TotpEnrollConfirmResponse,
  TotpEnrollStartResponse,
} from '@/services/totpService';

export interface TotpEnrollmentService {
  startEnrollment(): Promise<TotpEnrollStartResponse>;
  confirmEnrollment(params: { code: string }): Promise<TotpEnrollConfirmResponse>;
}

export function useTotpEnrollment(params: { service: TotpEnrollmentService }) {
  const { service } = params;

  const loading = ref(false);
  const error = ref<string | null>(null);
  const pending = ref<TotpEnrollStartResponse | null>(null);
  const backupCodes = ref<string[] | null>(null);

  const isPending = computed(() => pending.value !== null);

  function reset(): void {
    error.value = null;
    pending.value = null;
    backupCodes.value = null;
  }

  /**
   * Show a fresh set of backup codes to the user without re-enrolling.
   * Used by the regenerate flow (totp/backup-codes/regenerate) so the
   * settings UI can route the new codes through the same "save your
   * codes" step that the enrollment flow uses, without poking the
   * composable's private state from outside (LBDS-8 review F-03).
   */
  function setBackupCodes(params: { codes: string[] }): void {
    backupCodes.value = params.codes;
    pending.value = null;
    error.value = null;
  }

  async function start(): Promise<void> {
    // LBDS-8 review (F-08): a rapid double-click on "Habilitar TOTP"
    // would otherwise fire two startEnrollment requests in flight; the
    // second response overwrites the first. Guard against re-entry.
    if (loading.value) {
      return;
    }
    loading.value = true;
    error.value = null;
    try {
      pending.value = await service.startEnrollment();
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e);
    } finally {
      loading.value = false;
    }
  }

  async function confirm(params: { code: string }): Promise<boolean> {
    if (!pending.value) {
      error.value = 'No hay enrollment en curso. Iniciá uno primero.';
      return false;
    }

    if (loading.value) {
      return false;
    }
    loading.value = true;
    error.value = null;
    try {
      const response = await service.confirmEnrollment(params);
      backupCodes.value = response.backup_codes;
      pending.value = null; // confirm consumed the pending state
      return true;
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e);
      return false;
    } finally {
      loading.value = false;
    }
  }

  return {
    loading,
    error,
    pending,
    backupCodes,
    isPending,
    start,
    confirm,
    setBackupCodes,
    reset,
  };
}