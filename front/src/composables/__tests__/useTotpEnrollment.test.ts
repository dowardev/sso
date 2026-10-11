// SPDX-License-Identifier: MIT
import { describe, it, expect, vi } from 'vitest';
import { useTotpEnrollment } from '@/composables/useTotpEnrollment';
import type {
  TotpEnrollConfirmResponse,
  TotpEnrollStartResponse,
} from '@/services/totpService';

function makeService(params: {
  start?: () => Promise<TotpEnrollStartResponse>;
  confirm?: () => Promise<TotpEnrollConfirmResponse>;
}) {
  return {
    startEnrollment: params.start ?? (async () => ({
      secret: 'JBSWY3DPEHPK3PXP',
      otpauth_uri: 'otpauth://totp/Test:user?secret=JBSWY3DPEHPK3PXP',
      digits: 6,
      period: 30,
      algorithm: 'SHA1',
    })),
    confirmEnrollment: params.confirm ?? (async () => ({
      message: 'ok',
      backup_codes: ['AAAAAA0001', 'AAAAAA0002'],
      backup_codes_count: 2,
    })),
  };
}

describe('useTotpEnrollment', () => {
  it('start() stores the pending enrollment and resets previous error', async () => {
    const service = makeService({});
    const { pending, error, loading, start } = useTotpEnrollment({ service });

    error.value = 'previous error';
    await start();

    expect(loading.value).toBe(false);
    expect(error.value).toBeNull();
    expect(pending.value?.secret).toBe('JBSWY3DPEHPK3PXP');
  });

  it('start() surfaces errors from the service without throwing', async () => {
    const service = makeService({
      start: async () => { throw new Error('network down'); },
    });
    const { pending, error, start } = useTotpEnrollment({ service });

    await start();

    expect(pending.value).toBeNull();
    expect(error.value).toBe('network down');
  });

  it('confirm() returns true on success and captures backup codes', async () => {
    const service = makeService({});
    const { pending, backupCodes, error, start, confirm } = useTotpEnrollment({ service });

    await start();
    const ok = await confirm({ code: '123456' });

    expect(ok).toBe(true);
    expect(error.value).toBeNull();
    expect(backupCodes.value).toEqual(['AAAAAA0001', 'AAAAAA0002']);
    // Successful confirm consumes the pending state so the modal can move
    // to the "save your codes" step.
    expect(pending.value).toBeNull();
  });

  it('confirm() returns false on error and keeps pending state', async () => {
    const service = makeService({
      confirm: async () => { throw new Error('wrong code'); },
    });
    const { pending, error, backupCodes, start, confirm } = useTotpEnrollment({ service });

    await start();
    const ok = await confirm({ code: '000000' });

    expect(ok).toBe(false);
    expect(error.value).toBe('wrong code');
    expect(backupCodes.value).toBeNull();
    expect(pending.value).not.toBeNull();
  });

  it('confirm() without a pending enrollment fails fast and never calls the service', async () => {
    const confirmSpy = vi.fn();
    const service = makeService({
      confirm: async () => {
        confirmSpy();
        return { message: 'ok', backup_codes: [], backup_codes_count: 0 };
      },
    });
    const { pending, error, confirm } = useTotpEnrollment({ service });

    const ok = await confirm({ code: '123456' });

    expect(ok).toBe(false);
    expect(error.value).toMatch(/No hay enrollment en curso/);
    expect(confirmSpy).not.toHaveBeenCalled();
    expect(pending.value).toBeNull();
  });

  it('reset() clears all state', async () => {
    const service = makeService({});
    const { pending, error, backupCodes, start, confirm, reset } = useTotpEnrollment({ service });

    await start();
    await confirm({ code: '123456' });
    reset();

    expect(pending.value).toBeNull();
    expect(backupCodes.value).toBeNull();
    expect(error.value).toBeNull();
  });

  it('isPending mirrors the pending ref', async () => {
    const service = makeService({});
    const { isPending, start } = useTotpEnrollment({ service });

    expect(isPending.value).toBe(false);
    await start();
    expect(isPending.value).toBe(true);
  });

  it('start() is a no-op when already loading (LBDS-8 review F-08)', async () => {
    // F-08: a rapid double-click on "Habilitar TOTP" must not fire two
    // concurrent startEnrollment requests. The composable's loading
    // guard collapses the second call to a no-op.
    let calls = 0;
    let resolveFirst!: (v: { secret: string; otpauth_uri: string; digits: number; period: number; algorithm: string }) => void;
    const service = {
      startEnrollment: () => {
        calls++;
        return new Promise<{ secret: string; otpauth_uri: string; digits: number; period: number; algorithm: string }>((resolve) => {
          if (calls === 1) resolveFirst = resolve;
          else resolve({
            secret: 'JBSWY3DPEHPK3PXP',
            otpauth_uri: 'otpauth://totp/x',
            digits: 6,
            period: 30,
            algorithm: 'SHA1',
          });
        });
      },
      confirmEnrollment: async () => ({ message: 'ok', backup_codes: [], backup_codes_count: 0 }),
    };
    const { start, loading } = useTotpEnrollment({ service });

    const first = start();
    const second = start(); // should not call startEnrollment a second time
    expect(loading.value).toBe(true);

    resolveFirst({ secret: 'SECRET', otpauth_uri: 'otpauth://totp/x', digits: 6, period: 30, algorithm: 'SHA1' });
    await first;
    await second;

    expect(calls).toBe(1);
  });

  it('start() surfaces non-Error throw values as strings (LBDS-8 review F-10)', async () => {
    // Some libraries reject with a string or an object; we coerce
    // anything that isn't an Error into its String form.
    const service = makeService({
      start: async () => { throw 'raw string error'; },
    });
    const { error, start } = useTotpEnrollment({ service });
    await start();
    expect(error.value).toBe('raw string error');
  });

  it('confirm() rejects non-numeric or wrong-length codes (LBDS-8 review F-10)', async () => {
    // The composable returns false and surfaces an error when the
    // pending state is missing (its only client-side gate). Tests for
    // shape validation live in the backend (TotpServiceTest), so here
    // we only cover the composable's own preconditions.
    const service = makeService({});
    const { confirm, error } = useTotpEnrollment({ service });

    const ok = await confirm({ code: '12' });
    expect(ok).toBe(false);
    expect(error.value).toMatch(/No hay enrollment en curso/);
  });

  it('setBackupCodes updates state without mutating private refs (LBDS-8 review F-03)', async () => {
    const service = makeService({});
    const { setBackupCodes, backupCodes, error, pending } = useTotpEnrollment({ service });

    setBackupCodes({ codes: ['AAAA', 'BBBB'] });
    expect(backupCodes.value).toEqual(['AAAA', 'BBBB']);
    expect(error.value).toBeNull();
    expect(pending.value).toBeNull();
  });
});