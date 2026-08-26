// SPDX-License-Identifier: MIT

import { describe, it, expect } from 'vitest';
import {
  aesGcmDecrypt,
  aesGcmEncrypt,
  bytesToBase64,
  generateDekKey,
  hkdfBytes,
  importAesKwKey,
  pbkdf2Bytes,
  unwrapKeyWith,
  wrapKeyWith,
} from './core';

describe('zke core crypto', () => {
  it('AES-GCM encrypt/decrypt round-trips the profile payload', async () => {
    const dek = await generateDekKey();
    const plain = new TextEncoder().encode(JSON.stringify({ name: 'Ada', user: 'ada', email: 'a@x.com' }));
    const iv = crypto.getRandomValues(new Uint8Array(12));

    const cipher = await aesGcmEncrypt({ key: dek, data: plain, iv });
    const decrypted = await aesGcmDecrypt({ key: dek, data: cipher, iv });

    expect(new TextDecoder().decode(decrypted)).toBe(new TextDecoder().decode(plain));
  });

  it('AES-GCM fails to decrypt with a wrong key (integrity)', async () => {
    const dek = await generateDekKey();
    const other = await generateDekKey();
    const plain = new TextEncoder().encode('secret');
    const iv = crypto.getRandomValues(new Uint8Array(12));

    const cipher = await aesGcmEncrypt({ key: dek, data: plain, iv });
    await expect(aesGcmDecrypt({ key: other, data: cipher, iv })).rejects.toThrow();
  });

  it('AES-KW wrap/unwrap of a DEK preserves key usability', async () => {
    const dek = await generateDekKey();
    const wrapping = await importAesKwKey(crypto.getRandomValues(new Uint8Array(32)));

    const wrapped = await wrapKeyWith({ key: dek, wrappingKey: wrapping });
    const unwrapped = await unwrapKeyWith({ wrapped, unwrappingKey: wrapping });

    // unwrapped key can still encrypt/decrypt (same DEK material)
    const plain = new TextEncoder().encode('profile');
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const cipher = await aesGcmEncrypt({ key: unwrapped, data: plain, iv });
    await expect(aesGcmDecrypt({ key: unwrapped, data: cipher, iv })).resolves.toBeTruthy();
  });

  it('HKDF is deterministic and length-correct', async () => {
    const salt = crypto.getRandomValues(new Uint8Array(32));
    const ikm = crypto.getRandomValues(new Uint8Array(32));

    const a = await hkdfBytes({ ikm, salt, info: 'SSO-DEK-WRAP', length: 32 });
    const b = await hkdfBytes({ ikm, salt, info: 'SSO-DEK-WRAP', length: 32 });

    expect(a).toHaveLength(32);
    expect(bytesToBase64(a)).toBe(bytesToBase64(b));
  });

  it('PBKDF2 gives different output for different passwords (salt-equal)', async () => {
    const salt = crypto.getRandomValues(new Uint8Array(32));
    const a = await pbkdf2Bytes({ secret: 'pass-1', salt });
    const b = await pbkdf2Bytes({ secret: 'pass-2', salt });
    expect(bytesToBase64(a)).not.toBe(bytesToBase64(b));
  });

  it('PBKDF2 output changes with salt (password-equal)', async () => {
    const s1 = crypto.getRandomValues(new Uint8Array(32));
    const s2 = crypto.getRandomValues(new Uint8Array(32));
    const a = await pbkdf2Bytes({ secret: 'same', salt: s1 });
    const b = await pbkdf2Bytes({ secret: 'same', salt: s2 });
    expect(bytesToBase64(a)).not.toBe(bytesToBase64(b));
  });
});