// SPDX-License-Identifier: MIT

/**
 * Worker client — Promise-based API over the isolated crypto worker.
 * The main thread holds raw DEK bytes ONLY transiently while sending set-dek;
 * from then on it operates via decrypt-payload / encrypt-payload.
 */

import type { EncryptedBlob, UserProfile } from '../types/crypto';

interface WorkerRequest {
  type: string;
  payload: Record<string, unknown>;
}

interface WorkerResponse {
  type: string;
  payload: Record<string, unknown>;
  error?: string;
}

let worker: Worker | null = null;
let messageId = 0;
const pending = new Map<
  number,
  { resolve: (value: WorkerResponse) => void; reject: (reason: Error) => void }
>();

function getWorker(): Worker {
  if (!worker) {
    worker = new Worker(new URL('./cryptoWorker.ts', import.meta.url), { type: 'module' });
    worker.onmessage = (event: MessageEvent<WorkerResponse>) => {
      const id = (event.data as { _requestId?: number })._requestId;
      if (id && pending.has(id)) {
        const { resolve } = pending.get(id)!;
        pending.delete(id);
        resolve(event.data);
      }
    };
    worker.onerror = (e) => {
      for (const [, h] of pending) h.reject(new Error(`Worker error: ${e.message}`));
      pending.clear();
    };
  }
  return worker;
}

function send(params: { req: WorkerRequest }): Promise<WorkerResponse> {
  return new Promise((resolve, reject) => {
    const req = params.req;
    const id = ++messageId;
    pending.set(id, { resolve, reject });
    const withId = req as WorkerRequest & { _requestId: number };
    withId._requestId = id;
    getWorker().postMessage(withId);
    setTimeout(() => {
      if (pending.has(id)) {
        pending.delete(id);
        reject(new Error('Crypto operation timed out'));
      }
    }, 30_000);
  });
}

async function call(params: { req: WorkerRequest }): Promise<WorkerResponse> {
  const res = await send({ req: params.req });
  if (res.error || res.type === 'error') {
    throw new Error(res.error ?? 'Unknown error');
  }
  return res;
}

export interface CryptoWorkerClient {
  setDek(params: { dekBytes: Uint8Array }): Promise<void>;
  decryptProfile(params: { blob: EncryptedBlob }): Promise<UserProfile>;
  encryptProfile(params: { profile: UserProfile }): Promise<EncryptedBlob>;
  wrapDek(params: { wrappingKeyB64: string }): Promise<string>;
  unwrapDek(params: { wrappedB64: string; unwrappingKeyB64: string }): Promise<void>;
  clear(): Promise<void>;
  terminate(): void;
}

class WorkerClient implements CryptoWorkerClient {
  async setDek(params: { dekBytes: Uint8Array }): Promise<void> {
    await call({ req: { type: 'set-dek', payload: { dekBytes: toB64({ bytes: params.dekBytes }) } }});
  }

  async decryptProfile(params: { blob: EncryptedBlob }): Promise<UserProfile> {
    const res = await call({ req: { type: 'decrypt-payload', payload: { blob: params.blob } }});
    return (res.payload as { profile: UserProfile }).profile;
  }

  async encryptProfile(params: { profile: UserProfile }): Promise<EncryptedBlob> {
    const res = await call({ req: { type: 'encrypt-payload', payload: { profile: params.profile } }});
    return (res.payload as { blob: EncryptedBlob }).blob;
  }

  async wrapDek(params: { wrappingKeyB64: string }): Promise<string> {
    const res = await call({ req: { type: 'wrap-dek', payload: { wrappingKeyB64: params.wrappingKeyB64 } }});
    return (res.payload as { wrapped: string }).wrapped;
  }

  async unwrapDek(params: { wrappedB64: string; unwrappingKeyB64: string }): Promise<void> {
    await call({ req: { type: 'unwrap-dek', payload: { wrappedB64: params.wrappedB64, unwrappingKeyB64: params.unwrappingKeyB64 } }});
  }

  async clear(): Promise<void> {
    await call({ req: { type: 'clear', payload: {} } });
  }

  terminate(): void {
    if (worker) {
      worker.terminate();
      worker = null;
    }
  }
}

function toB64(params: { bytes: Uint8Array }): string {
  let binary = '';
  for (let i = 0; i < params.bytes.length; i++) binary += String.fromCharCode(params.bytes[i]);
  return btoa(binary);
}

let client: WorkerClient | null = null;
export function getCryptoWorkerClient(): CryptoWorkerClient {
  if (!client) client = new WorkerClient();
  return client;
}

export function resetCryptoWorkerClient(): void {
  client?.terminate();
  client = null;
}