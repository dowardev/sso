// SPDX-License-Identifier: MIT

/**
 * Crypto Service — v2 adapter over `@dowardev/sso-client`.
 *
 * The DEK now lives ONLY inside the isolated worker (never in the main-thread
 * heap), which is the whole point of the v2 XSS-exfiltration defense. The
 * intermediate keys Key_A / Key_R live in the main thread purely to compute
 * HMAC proofs (they are not the profile key).
 *
 * Methods that used to return/take a `dek` CryptoKey now operate on the
 * resident worker DEK instead.
 */

import {
  getCryptoWorkerClient,
  pbkdf2Bytes,
  hmacSign,
  bytesToBase64,
  base64ToBytes,
  randomBip39Mnemonic,
} from '@dowardev/sso-client';
import type {
  EncryptedBlob,
  WrappedKey,
  UserProfile,
  CryptoServiceAPI,
} from '@/types/crypto';

const KEY_LENGTH_BITS = 256;
const DEFAULT_ITERATIONS = 1_000_000;

// ─── In-memory session state (NOT the DEK — only intermediates + flags) ──────

let keyA: CryptoKey | null = null;
let isAdvanced = false;
let loginId: string | null = null;
let cachedProfile: UserProfile | null = null;
let cachedProfileVersion: number | null = null;

async function deriveIntermediateKey(params: {
  secret: string;
  salt: string;
  iterations?: number;
}): Promise<CryptoKey> {
  const raw = await pbkdf2Bytes({
    secret: params.secret,
    salt: base64ToBytes(params.salt),
    iterations: params.iterations ?? DEFAULT_ITERATIONS,
  });
  // extractable so it can be re-imported as an HMAC key for proofs.
  return crypto.subtle.importKey(
    'raw',
    raw as unknown as BufferSource,
    { name: 'AES-KW', length: KEY_LENGTH_BITS },
    true,
    ['wrapKey', 'unwrapKey'],
  ) as Promise<CryptoKey>;
}

async function exportKeyToB64(key: CryptoKey): Promise<string> {
  const raw = await crypto.subtle.exportKey('raw', key);
  return bytesToBase64(new Uint8Array(raw as ArrayBuffer));
}

const cryptoService: CryptoServiceAPI = {
  async deriveKeyA(params: { password: string; salt: string; iterations?: number }): Promise<CryptoKey> {
    const key = await deriveIntermediateKey({ secret: params.password, salt: params.salt, iterations: params.iterations });
    keyA = key;
    return key;
  },

  async generateDek(): Promise<void> {
    // Generate raw DEK bytes (transiently), install them in the worker, then
    // drop them from the main heap.
    const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: KEY_LENGTH_BITS }, true, ['encrypt', 'decrypt']);
    const raw = await crypto.subtle.exportKey('raw', key);
    await getCryptoWorkerClient().setDek({ dekBytes: new Uint8Array(raw as ArrayBuffer) });
  },

  async wrapDek(params: { wrappingKey: CryptoKey }): Promise<WrappedKey> {
    const wrapped = await getCryptoWorkerClient().wrapDek({
      wrappingKeyB64: await exportKeyToB64(params.wrappingKey),
    });
    return { wrapped, algorithm: 'AES-KW' };
  },

  async unwrapDek(params: { wrapped: string; unwrappingKey: CryptoKey }): Promise<void> {
    // The server returns wrapped_dek_* as a JSON string; parse to get the blob.
    const parsed = JSON.parse(params.wrapped) as WrappedKey;
    await getCryptoWorkerClient().unwrapDek({
      wrappedB64: parsed.wrapped,
      unwrappingKeyB64: await exportKeyToB64(params.unwrappingKey),
    });
  },

  async encryptProfile(params: { profile: UserProfile }): Promise<EncryptedBlob> {
    return getCryptoWorkerClient().encryptProfile({ profile: params.profile as never });
  },

  async decryptProfile(params: { blob: string }): Promise<UserProfile> {
    // Server returns encrypted_profile as a JSON string; parse to the blob.
    const blob = JSON.parse(params.blob) as EncryptedBlob;
    const profile = (await getCryptoWorkerClient().decryptProfile({
      blob,
    })) as unknown as UserProfile;
    cachedProfile = profile;
    return profile;
  },

  async computeAuthProof(params: { keyA: CryptoKey }): Promise<string> {
    return computeHmacProofInner({ key: params.keyA, context: 'auth' });
  },

  async generateSeedPhrase(): Promise<string> {
    return randomBip39Mnemonic();
  },

  async deriveKeyR(params: { seedPhrase: string; salt: string; iterations?: number }): Promise<CryptoKey> {
    return deriveIntermediateKey({ secret: params.seedPhrase, salt: params.salt, iterations: params.iterations });
  },

  clearKeys(): void {
    void getCryptoWorkerClient().clear();
    keyA = null;
    isAdvanced = false;
    loginId = null;
    cachedProfile = null;
    cachedProfileVersion = null;
  },


      /**
       * Drop only the resident DEK + decrypted profile (keep the auth state).
       * Used by the inactivity re-lock: the session stays logged in, but the
       * worker no longer holds the DEK (re-auth or a PRF gesture re-unlocks).
       */
      relock(): void {
        void getCryptoWorkerClient().clear();
        keyA = null;
        cachedProfile = null;
        cachedProfileVersion = null;
      },

      getKeyA(): CryptoKey | null {
        return keyA;
      },
  isAdvanced(): boolean {
    return isAdvanced;
  },

  getLoginId(): string | null {
    return loginId;
  },

  initializeAdvancedState(params: { loginId: string }): void {
    isAdvanced = true;
    loginId = params.loginId;
  },

  terminate(): void {
    getCryptoWorkerClient().terminate();
    clearCache();
  },

  async computeHmacProof(params: { key: CryptoKey; context: string }): Promise<string> {
    return computeHmacProofInner({ key: params.key, context: params.context });
  },

  getCachedProfile(): UserProfile | null {
    return cachedProfile;
  },

  getCachedProfileVersion(): number | null {
    return cachedProfileVersion;
  },

  setCachedProfile(params: { profile: UserProfile; version: number }): void {
    cachedProfile = params.profile;
    cachedProfileVersion = params.version;
  },
};

async function computeHmacProofInner(params: { key: CryptoKey; context: string }): Promise<string> {
  const raw = await crypto.subtle.exportKey('raw', params.key);
  const sig = await hmacSign({ keyBytes: new Uint8Array(raw as ArrayBuffer), data: params.context });
  return bytesToBase64(sig);
}

function clearCache(): void {
  cachedProfile = null;
  cachedProfileVersion = null;
  keyA = null;
  isAdvanced = false;
  loginId = null;
}

export default cryptoService;
export { cryptoService };