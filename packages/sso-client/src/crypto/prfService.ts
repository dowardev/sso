// SPDX-License-Identifier: MIT

/**
 * WebAuthn PRF (Per-Relying-Party Pseudo-Random Function) service.
 *
 * The PRF extension derives a device-bound secret via the user's authenticator
 * (gated by a biometric/PIN gesture). We wrap the ZKE DEK with K_PRF =
 * HKDF(prfOutput, salt, "SSO-DEK-WRAP") and persist only the wrapped DEK +
 * salt per credentialId, so a later restore needs a fresh gesture but no
 * password (silent profile refresh).
 *
 * Fail-secure: if PRF is unsupported, nothing is persisted — the caller must
 * fall back to password re-auth.
 */

import { base64ToBytes, bytesToBase64, bytesToBase64Url, hkdfBytes, PRF_DEK_INFO } from './core';
import type { PrfWrappedDek } from '../types/crypto';

function asBuf(params: { u: Uint8Array | ArrayBuffer }): BufferSource {
  return params.u as unknown as BufferSource;
}

// ─── feature detection ───────────────────────────────────────────────────────

export interface PrfSupport {
  supported: boolean;
  reason?: 'no-credentials' | 'no-prf' | null;
}

/** True only when the browser exposes WebAuthn credential creation/get. */
export function detectPrfSupport(): PrfSupport {
  if (typeof navigator === 'undefined' || !('credentials' in navigator)) {
    return { supported: false, reason: 'no-credentials' };
  }
  const pk = (navigator as unknown as {
    credentials: { create?: unknown; get?: unknown };
  }).credentials;
  const hasCredMgmt = !!pk && typeof pk.create === 'function' && typeof pk.get === 'function';
  const hasPublicKeyCred = typeof globalThis.PublicKeyCredential !== 'undefined';
  if (!hasCredMgmt) {
    return { supported: false, reason: 'no-credentials' };
  }
  if (!hasPublicKeyCred) {
    return { supported: false, reason: 'no-prf' };
  }
  // Authoritative check happens at ceremony time: a credential created with
  // the prf extension that returns no prf result means the device doesn't
  // expose a PRF secret.
  return { supported: true };
}

// ─── K_PRF derivation (testable) ─────────────────────────────────────────────

/** Derive the DEK-wrapping key from the authenticator's PRF output + random salt. */
export async function derivePrfKey(params: { prfOutput: Uint8Array; salt: Uint8Array }): Promise<Uint8Array> {
  return hkdfBytes({ ikm: params.prfOutput, salt: params.salt, info: PRF_DEK_INFO, length: 32 });
}

// ─── PRF ceremony (main thread; real browser) ────────────────────────────────

export interface PrfCeremonyResult {
  credentialId: string;
  prfOutput: Uint8Array;
}

/**
 * Register a credential that enables PRF. Calls navigator.credentials.create
 * with the prf extension enabled. Returns null when the authenticator didn't
 * expose a PRF secret (legacy credential) so the caller can fail secure.
 */
export async function createPrfCredential(params: {
  buildRequest: (ext: { prf: { enable: boolean } }) => CredentialCreationOptions;
}): Promise<PrfCeremonyResult | null> {
  const raw = params.buildRequest({ prf: { enable: true } });
  const credential = await navigator.credentials.create(raw);
  if (!credential) throw new Error('Credential creation failed');

  const ext = credential as PublicKeyCredential & {
    getClientExtensionResults?: () => { prf?: { results?: { first?: ArrayBuffer } } };
  };
  const prf = ext.getClientExtensionResults?.().prf?.results?.first;
  if (!prf) return null; // authenticator did not yield PRF (legacy credential)

  return {
    credentialId: abToB64Url({ ab: (credential as unknown as { rawId: ArrayBuffer }).rawId }),
    prfOutput: abToU8({ src: prf as ArrayBuffer | ArrayBufferView }),
  };
}

/**
 * Authenticate (get) a PRF-enabled credential. Uses userVerification:required
 * so the OS forces a gesture; with the prf.eval salt the authenticator returns
 * the same secret it would have for that credential.
 */
export async function getPrfOutput(params: {
  credentialIds: string[];
  salt: Uint8Array;
  buildRequest: (req: {
    allowCredentials: Array<{ type: string; id: ArrayBuffer; transports?: string[] }>;
    userVerification: string;
    extensions: { prf: { eval: { first: ArrayBuffer } } };
  }) => unknown;
}): Promise<PrfCeremonyResult> {
  const request = params.buildRequest({
    allowCredentials: params.credentialIds.map((c) => ({
      type: 'public-key',
      id: base64UrlToArrayBuffer({ s: c }),
    })),
    userVerification: 'required',
    extensions: { prf: { eval: { first: toArrayBuffer({ bytes: params.salt }) } } },
  });

  const credential = await navigator.credentials.get(request as CredentialRequestOptions);
  if (!credential) throw new Error('Credential authentication failed');

  const ext = credential as PublicKeyCredential & {
    getClientExtensionResults?: () => { prf?: { results?: { first?: ArrayBuffer } } };
  };
  const prf = ext.getClientExtensionResults?.().prf?.results?.first;
  if (!prf) throw new Error('The authenticator returned no PRF secret');

  return {
    credentialId: abToB64Url({ ab: (credential as unknown as { rawId: ArrayBuffer }).rawId }),
    prfOutput: abToU8({ src: prf as ArrayBuffer | ArrayBufferView }),
  };
}

// ─── IndexedDB store (multi-passkey, fail-secure) ────────────────────────────

const DB_NAME = 'zke_prf_keys';
const STORE = 'dek';

interface PrfStore {
  getEntry(params: { credentialId: string }): Promise<PrfWrappedDek | null>;
  putEntry(params: { entry: PrfWrappedDek }): Promise<void>;
  listEntries(): Promise<PrfWrappedDek[]>;
  removeEntry(params: { credentialId: string }): Promise<void>;
  clear(): Promise<void>;
}

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      if (!req.result.objectStoreNames.contains(STORE)) {
        req.result.createObjectStore(STORE, { keyPath: 'credentialId' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function withStore<T>(params: { fn: (store: IDBObjectStore) => IDBRequest<T> }): Promise<T> {
  const db = await openDb();
  return new Promise<T>((resolve, reject) => {
    const tx = db.transaction(STORE, 'readwrite');
    const req = params.fn(tx.objectStore(STORE));
    req.onsuccess = () => {
      db.close();
      resolve(req.result);
    };
    req.onerror = () => {
      db.close();
      reject(req.error);
    };
  });
}

export const prfStore: PrfStore = {
  async getEntry(params: { credentialId: string }): Promise<PrfWrappedDek | null> {
    return (await withStore({ fn: (s) => s.get(params.credentialId) })) ?? null;
  },
  async putEntry(params: { entry: PrfWrappedDek }): Promise<void> {
    await withStore({ fn: (s) => s.put(params.entry) });
  },
  async listEntries(): Promise<PrfWrappedDek[]> {
    return withStore({ fn: (s) => s.getAll() });
  },
  async removeEntry(params: { credentialId: string }): Promise<void> {
    await withStore({ fn: (s) => s.delete(params.credentialId) });
  },
  async clear(): Promise<void> {
    await withStore({ fn: (s) => s.clear() });
  },
};

// ─── orchestration helper (fail-secure) ──────────────────────────────────────

/** Wrap the DEK with K_PRF and persist it for a credential (fail-secure). */
export async function persistPrfWrappedDek(params: {
  dekBytes: Uint8Array;
  prfOutput: Uint8Array;
  credentialId: string;
}): Promise<PrfWrappedDek | null> {
  const support = detectPrfSupport();
  if (!support.supported) {
    // Fail-secure: do NOT persist anything.
    return null;
  }

  const salt = crypto.getRandomValues(new Uint8Array(32));
  const kPrf = await derivePrfKey({ prfOutput: params.prfOutput, salt });
  const wrappingKey = await crypto.subtle.importKey('raw', asBuf({ u: kPrf }), { name: 'AES-KW', length: 256 }, false, [
    'wrapKey',
  ]);
  const wrapped = new Uint8Array(
    await crypto.subtle.wrapKey('raw', await importDek({ bytes: params.dekBytes }), wrappingKey, { name: 'AES-KW' }),
  );

  const entry: PrfWrappedDek = {
    wrappedDek: bytesToBase64(wrapped),
    salt: bytesToBase64(salt),
    credentialId: params.credentialId,
  };

  await prfStore.putEntry({ entry });
  return entry;
}

async function importDek(params: { bytes: Uint8Array }): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', asBuf({ u: params.bytes }), { name: 'AES-GCM', length: 256 }, true, [
    'encrypt',
    'decrypt',
  ]);
}

export function dekBytesFromBase64(params: { b64: string }): Uint8Array {
  return base64ToBytes(params.b64);
}

export function prfKeyToBase64(params: { kPrf: Uint8Array }): string {
  return bytesToBase64(params.kPrf);
}

// ─── array buffer helpers ────────────────────────────────────────────────────

function toArrayBuffer(params: { bytes: Uint8Array }): ArrayBuffer {
  return params.bytes.buffer.slice(
    params.bytes.byteOffset,
    params.bytes.byteOffset + params.bytes.byteLength,
  ) as ArrayBuffer;
}

function base64UrlToArrayBuffer(params: { s: string }): ArrayBuffer {
  const b64 = params.s.replace(/-/g, '+').replace(/_/g, '/');
  const padded = b64 + '='.repeat((4 - (b64.length % 4)) % 4);
  const bin = atob(padded);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out.buffer;
}

function abToU8(params: { src: ArrayBuffer | ArrayBufferView | Uint8Array }): Uint8Array {
  const src = params.src;
  if (src instanceof Uint8Array) return src;
  if (ArrayBuffer.isView(src)) {
    return new Uint8Array(src.buffer.slice(src.byteOffset, src.byteOffset + src.byteLength));
  }
  return new Uint8Array((src as ArrayBuffer).slice(0));
}

function abToB64Url(params: { ab: ArrayBuffer }): string {
  return bytesToBase64Url(abToU8({ src: params.ab }));
}