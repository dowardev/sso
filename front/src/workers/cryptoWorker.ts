// SPDX-License-Identifier: MIT

/**
 * Crypto Web Worker for Zero-Knowledge Encryption
 *
 * Runs in isolated thread to:
 * - Keep main thread responsive during PBKDF2 (1M+ iterations)
 * - Isolate cryptographic keys from main thread memory (XSS protection)
 * - Provide Web Crypto API operations without blocking UI
 */

/// <reference lib="webworker" />

import { generateMnemonic } from '@scure/bip39';
import { wordlist } from '@scure/bip39/wordlists/english.js';
import type {
  CryptoWorkerMessage,
  CryptoWorkerResponse,
  EncryptedBlob,
  WrappedKey,
  UserProfile,
} from '@/types/crypto';

// ─── Constants ────────────────────────────────────────────────────────────────

const PBKDF2_ITERATIONS = 1_000_000;
const KEY_LENGTH_BITS = 256;
const AES_GCM_IV_LENGTH = 12; // 96 bits
const HMAC_HASH = 'SHA-256';
const PBKDF2_HASH = 'SHA-256';
const AES_KW_ALGORITHM = 'AES-KW';
const AES_GCM_ALGORITHM = 'AES-GCM';

// ─── Helper Functions ────────────────────────────────────────────────────────

function base64ToBuffer(params: { b64: string }): ArrayBuffer {
  const { b64 } = params;
  const binary = atob(b64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}

function bufferToBase64(params: { buffer: ArrayBuffer }): string {
  const { buffer } = params;
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.byteLength; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary);
}

function concatBuffers(params: { buffers: ArrayBuffer[] }): ArrayBuffer {
  const { buffers } = params;
  const totalLength = buffers.reduce((sum, buf) => sum + buf.byteLength, 0);
  const result = new Uint8Array(totalLength);
  let offset = 0;
  for (const buf of buffers) {
    result.set(new Uint8Array(buf), offset);
    offset += buf.byteLength;
  }
  return result.buffer;
}

// ─── Crypto Operations ────────────────────────────────────────────────────────

/**
 * Derive a key from a secret via PBKDF2.
 *
 * The derived key is extractable because the same Key_A material must be
 * reused as an HMAC key for auth proofs / seed verification: Web Crypto keys
 * are algorithm-bound, so we derive once for AES-KW and re-import the raw
 * bytes as an HMAC key inside computeAuthProof.
 */
async function deriveKeyPBKDF2(params: {
  password: string;
  saltB64: string;
  iterations?: number;
}): Promise<CryptoKey> {
  const { password, saltB64, iterations = PBKDF2_ITERATIONS } = params;

  const encoder = new TextEncoder();
  const passwordBuffer = encoder.encode(password);
  const saltBuffer = base64ToBuffer({ b64: saltB64 });

  const baseKey = await crypto.subtle.importKey(
    'raw',
    passwordBuffer,
    { name: 'PBKDF2' },
    false,
    ['deriveKey'],
  );

  return crypto.subtle.deriveKey(
    {
      name: 'PBKDF2',
      salt: saltBuffer,
      iterations,
      hash: PBKDF2_HASH,
    },
    baseKey,
    { name: AES_KW_ALGORITHM, length: KEY_LENGTH_BITS },
    true, // extractable — required for HMAC re-import in computeAuthProof
    ['wrapKey', 'unwrapKey'],
  );
}

async function generateRandomDEK(): Promise<CryptoKey> {
  // Extractable is required: AES-KW wrapKey('raw', ...) needs the raw DEK bytes.
  return crypto.subtle.generateKey({ name: AES_GCM_ALGORITHM, length: KEY_LENGTH_BITS }, true, [
    'encrypt',
    'decrypt',
  ]);
}

async function importHmacKeyFromWrappingKey(params: { key: CryptoKey }): Promise<CryptoKey> {
  const raw = await crypto.subtle.exportKey('raw', params.key);
  return crypto.subtle.importKey('raw', raw, { name: 'HMAC', hash: HMAC_HASH }, false, ['sign']);
}

async function wrapDek(params: { dek: CryptoKey; wrappingKey: CryptoKey }): Promise<WrappedKey> {
  const wrapped = await crypto.subtle.wrapKey('raw', params.dek, params.wrappingKey, {
    name: AES_KW_ALGORITHM,
  });
  return {
    wrapped: bufferToBase64({ buffer: wrapped }),
    algorithm: AES_KW_ALGORITHM,
  };
}

async function unwrapDek(params: {
  wrapped: WrappedKey;
  unwrappingKey: CryptoKey;
}): Promise<CryptoKey> {
  const wrappedBuffer = base64ToBuffer({ b64: params.wrapped.wrapped });
  return crypto.subtle.unwrapKey(
    'raw',
    wrappedBuffer,
    params.unwrappingKey,
    { name: AES_KW_ALGORITHM },
    { name: AES_GCM_ALGORITHM, length: KEY_LENGTH_BITS },
    true, // extractable — needed to re-wrap the DEK on rotation/recovery
    ['encrypt', 'decrypt'],
  );
}

async function encryptProfile(params: {
  dek: CryptoKey;
  profile: UserProfile;
}): Promise<EncryptedBlob> {
  const encoder = new TextEncoder();
  const data = encoder.encode(JSON.stringify(params.profile));
  const iv = crypto.getRandomValues(new Uint8Array(AES_GCM_IV_LENGTH));

  const ciphertextWithTag = await crypto.subtle.encrypt({ name: AES_GCM_ALGORITHM, iv }, params.dek, data);

  // AES-GCM output = ciphertext || authTag (last 16 bytes)
  const full = new Uint8Array(ciphertextWithTag);
  const tagLength = 16;
  const ciphertext = full.slice(0, full.length - tagLength);
  const authTag = full.slice(full.length - tagLength);

  return {
    ciphertext: bufferToBase64({ buffer: ciphertext.buffer }),
    iv: bufferToBase64({ buffer: iv.buffer }),
    authTag: bufferToBase64({ buffer: authTag.buffer }),
  };
}

async function decryptProfile(params: { dek: CryptoKey; blob: EncryptedBlob }): Promise<UserProfile> {
  const ciphertext = base64ToBuffer({ b64: params.blob.ciphertext });
  const iv = base64ToBuffer({ b64: params.blob.iv });
  const authTag = base64ToBuffer({ b64: params.blob.authTag });

  const joined = concatBuffers({ buffers: [ciphertext, authTag] });

  const decrypted = await crypto.subtle.decrypt({ name: AES_GCM_ALGORITHM, iv }, params.dek, joined);

  const decoder = new TextDecoder();
  return JSON.parse(decoder.decode(decrypted)) as UserProfile;
}

async function computeHmacProof(params: { key: CryptoKey; context: string }): Promise<string> {
  const hmacKey = await importHmacKeyFromWrappingKey({ key: params.key });
  const data = new TextEncoder().encode(params.context);
  const signature = await crypto.subtle.sign('HMAC', hmacKey, data);
  return bufferToBase64({ buffer: signature });
}

function generateSeedPhrase24Words(): string {
  // BIP39 with full 2048-word English list and checksum, 256-bit entropy = 24 words.
  // @scure/bip39 uses crypto.getRandomValues (CSPRNG) internally.
  return generateMnemonic(wordlist, 256);
}

// ─── Message Handler ────────────────────────────────────────────────────────

self.onmessage = async (event: MessageEvent<CryptoWorkerMessage>) => {
  const message = event.data;
  let response: CryptoWorkerResponse;

  try {
    switch (message.type) {
      case 'derive-key-a':
      case 'derive-key-r': {
        const secret = message.type === 'derive-key-a' ? message.payload.password : message.payload.seedPhrase;
        const key = await deriveKeyPBKDF2({
          password: secret,
          saltB64: message.payload.salt,
          iterations: message.payload.iterations,
        });
        response = { type: 'key-derived', payload: { key } };
        break;
      }

      case 'generate-dek': {
        const dek = await generateRandomDEK();
        response = { type: 'dek-generated', payload: { dek } };
        break;
      }

      case 'wrap-dek': {
        const wrapped = await wrapDek({
          dek: message.payload.dek,
          wrappingKey: message.payload.wrappingKey,
        });
        response = { type: 'dek-wrapped', payload: wrapped };
        break;
      }

      case 'unwrap-dek': {
        const dek = await unwrapDek({
          wrapped: message.payload.wrapped,
          unwrappingKey: message.payload.unwrappingKey,
        });
        response = { type: 'dek-unwrapped', payload: { dek } };
        break;
      }

      case 'encrypt-profile': {
        const blob = await encryptProfile({
          dek: message.payload.dek,
          profile: message.payload.profile,
        });
        response = { type: 'profile-encrypted', payload: blob };
        break;
      }

      case 'decrypt-profile': {
        const profile = await decryptProfile({
          dek: message.payload.dek,
          blob: message.payload.blob,
        });
        response = { type: 'profile-decrypted', payload: profile };
        break;
      }

      case 'compute-auth-proof': {
        const proof = await computeHmacProof({
          key: message.payload.keyA,
          context: message.payload.context,
        });
        response = { type: 'auth-proof-computed', payload: { proof } };
        break;
      }

      case 'generate-seed-phrase': {
        const phrase = generateSeedPhrase24Words();
        response = { type: 'seed-phrase-generated', payload: { phrase } };
        break;
      }

      default: {
        response = {
          type: 'error',
          payload: { message: 'Unknown message type', code: 'UNKNOWN_MESSAGE' },
        };
      }
    }
  } catch (error) {
    response = {
      type: 'error',
      payload: {
        message: error instanceof Error ? error.message : 'Unknown error',
        code: 'CRYPTO_OPERATION_FAILED',
      },
    };
  }

  self.postMessage(response);
};
