// SPDX-License-Identifier: MIT

/// <reference lib="webworker" />

/**
 * ZKE Crypto Web Worker — v2 isolation.
 *
 * The DEK lives ONLY here for the lifetime of a session. The main thread
 * never receives the DEK handle; it sends SET_DEK once (raw bytes) and then
 * asks DECRYPT_PAYLOAD / ENCRYPT_PAYLOAD against the resident DEK. An XSS on
 * the main thread can request a decrypt, but it cannot read the DEK bytes out
 * (no export path exists), which defends against key exfiltration to a third
 * party.
 *
 * Protocol:
 *   {type:'set-dek', payload:{dekBytes}}            → {type:'ok'}
 *   {type:'decrypt-payload', payload:{blob}}        → {type:'payload-decrypted', payload:{profile}}
 *   {type:'encrypt-payload', payload:{profile}}     → {type:'payload-encrypted', payload:{blob}}
 *   {type:'clear'}                                  → {type:'ok'}  (wipes resident DEK)
 *   {type:'wrap-dek', payload:{wrappingKeyB64}}     → {type:'dek-wrapped', payload:{wrapped}}
 *   {type:'unwrap-dek', payload:{wrappedB64, unwrappingKeyB64}} → {type:'dek-unwrapped'} (keeps DEK resident)
 */

import {
  AES_GCM_ALGORITHM,
  KEY_LENGTH_BITS,
  aesGcmDecrypt,
  aesGcmEncrypt,
  base64ToBytes,
  bytesToBase64,
  concatBytes,
  importAesKwKey,
  unwrapKeyWith,
  wrapKeyWith,
} from './core';

interface WorkerRequest {
  type: string;
  payload: Record<string, unknown>;
}

interface WorkerResponse {
  type: string;
  payload: Record<string, unknown>;
  ok?: boolean;
  error?: string;
}

let dek: CryptoKey | null = null;

function isEncryptedBlob(v: unknown): boolean {
  return (
    !!v &&
    typeof v === 'object' &&
    typeof (v as { ciphertext?: string }).ciphertext === 'string' &&
    typeof (v as { iv?: string }).iv === 'string' &&
    typeof (v as { authTag?: string }).authTag === 'string'
  );
}

interface BlobLike {
  ciphertext: string;
  iv: string;
  authTag: string;
}

self.onmessage = async (event: MessageEvent<WorkerRequest>) => {
  const msg = event.data;
  let res: WorkerResponse;

  try {
    switch (msg.type) {
      case 'set-dek': {
        const bytes = base64ToBytes(msg.payload.dekBytes as string);
        dek = await crypto.subtle.importKey(
        'raw',
        bytes as unknown as BufferSource,
        { name: AES_GCM_ALGORITHM, length: KEY_LENGTH_BITS },
        false,
        ['encrypt', 'decrypt'],
      );
        res = { type: 'ok', payload: {} };
        break;
      }

      case 'decrypt-payload': {
        if (!dek) throw new Error('DEK no configurado');
        if (!isEncryptedBlob(msg.payload.blob)) throw new Error('Blob inválido');
        const blob = msg.payload.blob as unknown as BlobLike;
        const ciphertext = base64ToBytes(blob.ciphertext);
        const iv = base64ToBytes(blob.iv);
        const authTag = base64ToBytes(blob.authTag);
        const plain = await aesGcmDecrypt({ key: dek, data: concatBytes([ciphertext, authTag]), iv });
        const profile = JSON.parse(new TextDecoder().decode(plain));
        res = { type: 'payload-decrypted', payload: { profile } };
        break;
      }

      case 'encrypt-payload': {
        if (!dek) throw new Error('DEK no configurado');
        const plain = new TextEncoder().encode(JSON.stringify(msg.payload.profile));
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const full = new Uint8Array(await aesGcmEncrypt({ key: dek, data: plain, iv }));
        const tagLength = 16;
        res = {
          type: 'payload-encrypted',
          payload: {
            blob: {
              ciphertext: bytesToBase64(full.slice(0, full.length - tagLength)),
              iv: bytesToBase64(iv),
              authTag: bytesToBase64(full.slice(full.length - tagLength)),
            },
          },
        };
        break;
      }

      case 'wrap-dek': {
        if (!dek) throw new Error('DEK no configurado');
        const wrappingKey = await importAesKwKey(base64ToBytes(msg.payload.wrappingKeyB64 as string));
        const wrapped = await wrapKeyWith({ key: dek, wrappingKey });
        res = { type: 'dek-wrapped', payload: { wrapped: bytesToBase64(wrapped) } };
        break;
      }

      case 'unwrap-dek': {
        // Re-unwrap the DEK and set it as the resident key (no export back).
        const wrapped = base64ToBytes(msg.payload.wrappedB64 as string);
        const unwrappingKey = await importAesKwKey(base64ToBytes(msg.payload.unwrappingKeyB64 as string));
        dek = await unwrapKeyWith({ wrapped, unwrappingKey });
        res = { type: 'dek-set', payload: {} };
        break;
      }

      case 'clear': {
        dek = null;
        res = { type: 'ok', payload: {} };
        break;
      }

      default:
        res = { type: 'error', payload: {}, error: `Unknown message type: ${msg.type}` };
    }
  } catch (error) {
    res = { type: 'error', payload: {}, error: error instanceof Error ? error.message : 'Unknown error' };
  }

  self.postMessage({ ...res, _requestId: (msg as { _requestId?: number })._requestId });
};