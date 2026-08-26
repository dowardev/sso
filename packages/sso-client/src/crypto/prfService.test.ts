// SPDX-License-Identifier: MIT

import { describe, expect, it, vi, afterEach } from 'vitest';
import { bytesToBase64 } from './core';
import { detectPrfSupport, derivePrfKey, persistPrfWrappedDek, prfStore } from './prfService';

describe('prf service', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete (globalThis as unknown as { nav?: unknown }).nav;
  });

  it('derivePrfKey is deterministic and 32 bytes (HKDF with SSO-DEK-WRAP info)', async () => {
    const salt = crypto.getRandomValues(new Uint8Array(32));
    const prfOutput = crypto.getRandomValues(new Uint8Array(32));
    const a = await derivePrfKey({ prfOutput, salt });
    const b = await derivePrfKey({ prfOutput, salt });
    expect(a).toHaveLength(32);
    expect(bytesToBase64(a)).toBe(bytesToBase64(b));
  });

  it('k_prf changes when the prf output changes (device-bound)', async () => {
    const salt = crypto.getRandomValues(new Uint8Array(32));
    const a = await derivePrfKey({ prfOutput: crypto.getRandomValues(new Uint8Array(32)), salt });
    const b = await derivePrfKey({ prfOutput: crypto.getRandomValues(new Uint8Array(32)), salt });
    expect(bytesToBase64(a)).not.toBe(bytesToBase64(b));
  });

  it('detectPrfSupport reports unsupported when navigator.credentials is missing', () => {
    // simulate no WebAuthn
    const nav = {
      credentials: undefined,
    };
    vi.stubGlobal('navigator', nav);
    expect(detectPrfSupport().supported).toBe(false);
    expect(detectPrfSupport().reason).toBe('no-credentials');
  });

  it('fail-secure: persistPrfWrappedDek does NOT store when PRF unsupported', async () => {
    const nav = { credentials: { create: undefined, get: undefined } };
    vi.stubGlobal('navigator', nav);

    const putSpy = vi.spyOn(prfStore, 'putEntry');
    const result = await persistPrfWrappedDek({
      dekBytes: crypto.getRandomValues(new Uint8Array(32)),
      prfOutput: crypto.getRandomValues(new Uint8Array(32)),
      credentialId: 'cred-1',
    });

    expect(result).toBeNull();
    expect(putSpy).not.toHaveBeenCalled();
  });
});