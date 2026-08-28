// SPDX-License-Identifier: MIT

/**
 * Pure WebCrypto primitives for the zero-knowledge layer. Framework-agnostic;
 * usable from both the main thread and inside the crypto worker.
 */

export const PBKDF2_ITERATIONS = 1_000_000;
export const KEY_LENGTH_BITS = 256;
export const AES_GCM_IV_LENGTH = 12;
export const HMAC_HASH = 'SHA-256';
export const PBKDF2_HASH = 'SHA-256';
export const AES_KW_ALGORITHM = 'AES-KW';
export const AES_GCM_ALGORITHM = 'AES-GCM';
export const PRF_DEK_INFO = 'SSO-DEK-WRAP'; // HKDF info for K_PRF derivation

// TS 5.x types Uint8Array with the generic ArrayBufferLike; cast to BufferSource
// at the crypto.subtle boundary avoids SharedArrayBuffer-variance friction.
function asBuf(u: Uint8Array | ArrayBuffer): BufferSource {
  return u as unknown as BufferSource;
}

// ─── bytes helpers ───────────────────────────────────────────────────────────

export function bytesToBase64(buffer: ArrayBuffer | Uint8Array): string {
  const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.byteLength; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary);
}

export function base64ToBytes(b64: string): Uint8Array {
  const binary = atob(b64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes;
}

export function bytesToBase64Url(bytes: Uint8Array): string {
  return btoa(String.fromCharCode(...bytes))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/g, '');
}

export function base64UrlToBytes(s: string): Uint8Array {
  const b64 = s.replace(/-/g, '+').replace(/_/g, '/');
  const pad = b64.length % 4 === 0 ? b64 : b64 + '='.repeat(4 - (b64.length % 4));
  return base64ToBytes(pad);
}

export function concatBytes(buffers: Uint8Array[]): Uint8Array {
  const total = buffers.reduce((sum, b) => sum + b.length, 0);
  const out = new Uint8Array(total);
  let offset = 0;
  for (const buf of buffers) {
    out.set(buf, offset);
    offset += buf.length;
  }
  return out;
}

// ─── key derivation ──────────────────────────────────────────────────────────

/** PBKDF2 -> raw bytes (used for passwords / seed phrases). */
export async function pbkdf2Bytes(params: {
  secret: string;
  salt: Uint8Array;
  iterations?: number;
  length?: number;
}): Promise<Uint8Array> {
  const { secret, salt, iterations = PBKDF2_ITERATIONS, length = 32 } = params;
  const baseKey = await crypto.subtle.importKey(
    'raw',
    asBuf(new TextEncoder().encode(secret)),
    { name: 'PBKDF2' },
    false,
    ['deriveBits'],
  );
  const bits = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: asBuf(salt), iterations, hash: PBKDF2_HASH },
    baseKey,
    length * 8,
  );
  return new Uint8Array(bits);
}

/** HKDF-SHA256 -> raw bytes of the given length. */
export async function hkdfBytes(params: {
  ikm: Uint8Array;
  salt: Uint8Array;
  info: string;
  length?: number;
}): Promise<Uint8Array> {
  const { ikm, salt, info, length = 32 } = params;
  const baseKey = await crypto.subtle.importKey('raw', asBuf(ikm), { name: 'HKDF' }, false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits(
    { name: 'HKDF', hash: 'SHA-256', salt: asBuf(salt), info: new TextEncoder().encode(info) },
    baseKey,
    length * 8,
  );
  return new Uint8Array(bits);
}

/** Import a raw AES-KW wrapping key (e.g. Key_A, Key_R, or K_PRF). */
export async function importAesKwKey(raw: Uint8Array): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', asBuf(raw), { name: AES_KW_ALGORITHM, length: KEY_LENGTH_BITS }, false, [
    'wrapKey',
    'unwrapKey',
  ]);
}

// ─── AES-KW wrap / unwrap (DEK wrapping) ─────────────────────────────────────

/** Wrap a DEK (AES-GCM key) under a symmetric key with AES-KW. */
export async function wrapKeyWith(params: { key: CryptoKey; wrappingKey: CryptoKey }): Promise<Uint8Array> {
  const wrapped = await crypto.subtle.wrapKey('raw', params.key, params.wrappingKey, {
    name: AES_KW_ALGORITHM,
  });
  return new Uint8Array(wrapped);
}

/** Unwrap a DEK from AES-KW bytes under a symmetric key. */
export async function unwrapKeyWith(params: {
  wrapped: Uint8Array;
  unwrappingKey: CryptoKey;
  extractable?: boolean;
}): Promise<CryptoKey> {
  return crypto.subtle.unwrapKey(
    'raw',
    asBuf(params.wrapped),
    params.unwrappingKey,
    { name: AES_KW_ALGORITHM },
    { name: AES_GCM_ALGORITHM, length: KEY_LENGTH_BITS },
    params.extractable ?? true,
    ['encrypt', 'decrypt'],
  );
}

// ─── AES-GCM encrypt / decrypt (profile) ─────────────────────────────────────

export function generateIv(): Uint8Array {
  return crypto.getRandomValues(new Uint8Array(AES_GCM_IV_LENGTH));
}

export async function aesGcmEncrypt(params: {
  key: CryptoKey;
  data: Uint8Array;
  iv: Uint8Array;
}): Promise<Uint8Array> {
  return new Uint8Array(await crypto.subtle.encrypt({ name: AES_GCM_ALGORITHM, iv: asBuf(params.iv) }, params.key, asBuf(params.data)));
}

export async function aesGcmDecrypt(params: {
  key: CryptoKey;
  data: Uint8Array;
  iv: Uint8Array;
}): Promise<Uint8Array> {
  return new Uint8Array(
    await crypto.subtle.decrypt({ name: AES_GCM_ALGORITHM, iv: asBuf(params.iv) }, params.key, asBuf(params.data)),
  );
}

export async function randomBytes(n: number): Promise<Uint8Array> {
  return crypto.getRandomValues(new Uint8Array(n));
}

export async function generateDekKey(extractable = true): Promise<CryptoKey> {
  return crypto.subtle.generateKey({ name: AES_GCM_ALGORITHM, length: KEY_LENGTH_BITS }, extractable, [
    'encrypt',
    'decrypt',
  ]);
}

/** HMAC-SHA256 over context, from raw key bytes. */
export async function hmacSign(params: { keyBytes: Uint8Array; data: string }): Promise<Uint8Array> {
  const key = await crypto.subtle.importKey('raw', asBuf(params.keyBytes), { name: 'HMAC', hash: HMAC_HASH }, false, [
    'sign',
  ]);
  return new Uint8Array(await crypto.subtle.sign('HMAC', key, asBuf(new TextEncoder().encode(params.data))));
}

export async function randomBip39Mnemonic(): Promise<string> {
  const bip39 = await import('@scure/bip39');
  const { wordlist } = await import('@scure/bip39/wordlists/english.js');
  return bip39.generateMnemonic(wordlist, 256);
}