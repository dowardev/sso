// SPDX-License-Identifier: MIT

/**
 * Crypto Service — High-level API for Zero-Knowledge Encryption
 *
 * Manages Web Worker lifecycle and provides a clean Promise-based API
 * over the worker's message-passing interface.
 *
 * Keys (DEK, Key_A) are kept in memory only (tab-scoped) and
 * cleared on logout, tab close, or explicit clearKeys().
 */

import type {
  CryptoWorkerMessage,
  CryptoWorkerResponse,
  EncryptedBlob,
  WrappedKey,
  UserProfile,
  CryptoServiceAPI,
  AdvancedAuthState,
} from '@/types/crypto';

// ─── Worker Management ────────────────────────────────────────────────────────

let worker: Worker | null = null;
let messageId = 0;
const pendingRequests = new Map<
  number,
  { resolve: (value: CryptoWorkerResponse) => void; reject: (reason: Error) => void }
>();

function getWorker(): Worker {
  if (!worker) {
    // Vite will handle worker import and code-splitting
    worker = new Worker(new URL('@/workers/cryptoWorker.ts', import.meta.url), { type: 'module' });

    worker.onmessage = (event: MessageEvent<CryptoWorkerResponse>) => {
      const response = event.data;
      const id = (response as { _requestId?: number })._requestId;
      if (id && pendingRequests.has(id)) {
        const { resolve } = pendingRequests.get(id)!;
        pendingRequests.delete(id);
        resolve(response);
      }
    };

    worker.onerror = (error) => {
      console.error('[CryptoService] Worker error:', error);
      for (const [, { reject }] of pendingRequests) {
        reject(new Error(`Worker error: ${error.message}`));
      }
      pendingRequests.clear();
    };
  }
  return worker;
}

function terminateWorker(): void {
  if (worker) {
    worker.terminate();
    worker = null;
    for (const [, { reject }] of pendingRequests) {
      reject(new Error('Worker terminated'));
    }
    pendingRequests.clear();
  }
}

function sendMessage(params: { message: CryptoWorkerMessage }): Promise<CryptoWorkerResponse> {
  return new Promise((resolve, reject) => {
    const id = ++messageId;
    const workerInstance = getWorker();

    pendingRequests.set(id, { resolve, reject });

    const outgoing = params.message as CryptoWorkerMessage & { _requestId: number };
    outgoing._requestId = id;

    workerInstance.postMessage(outgoing);

    // Timeout safety (30s covers PBKDF2 with 1M iterations)
    setTimeout(() => {
      if (pendingRequests.has(id)) {
        pendingRequests.delete(id);
        reject(new Error('Crypto operation timeout'));
      }
    }, 30_000);
  });
}

function unwrapResponse(params: {
  response: CryptoWorkerResponse;
  expectedType: CryptoWorkerResponse['type'];
}): CryptoWorkerResponse {
  const { response, expectedType } = params;
  if (response.type === 'error') {
    throw new Error(response.payload.message);
  }
  if (response.type !== expectedType) {
    throw new Error(`Unexpected response type: expected ${expectedType}, got ${response.type}`);
  }
  return response;
}

// ─── In-Memory Key State (Tab-Scoped) ────────────────────────────────────────

const keyState: AdvancedAuthState = {
  dek: null,
  keyA: null,
  isAdvanced: false,
  loginId: null,
};

/**
 * Decrypted profile cache (tab-scoped, memory only).
 * Populated on every successful decryptProfile; consumed to deliver the
 * consented profile to OAuth apps at callback time while the DEK is hot.
 */
let cachedProfile: UserProfile | null = null;
let cachedProfileVersion: number | null = null;

// ─── High-Level Crypto API ────────────────────────────────────────────────────

const cryptoService: CryptoServiceAPI = {
  async deriveKeyA(params: { password: string; salt: string; iterations?: number }): Promise<CryptoKey> {
    const response = await sendMessage({
      message: {
        type: 'derive-key-a',
        payload: {
          password: params.password,
          salt: params.salt,
          iterations: params.iterations ?? 1_000_000,
        },
      },
    });

    const unwrapped = unwrapResponse({ response, expectedType: 'key-derived' });
    const keyA = (unwrapped as Extract<CryptoWorkerResponse, { type: 'key-derived' }>).payload.key;
    keyState.keyA = keyA;
    return keyA;
  },

  async generateDek(): Promise<CryptoKey> {
    const response = await sendMessage({ message: { type: 'generate-dek' } });

    const unwrapped = unwrapResponse({ response, expectedType: 'dek-generated' });
    const dek = (unwrapped as Extract<CryptoWorkerResponse, { type: 'dek-generated' }>).payload.dek;
    keyState.dek = dek;
    return dek;
  },

  async wrapDek(params: { dek: CryptoKey; wrappingKey: CryptoKey }): Promise<WrappedKey> {
    const response = await sendMessage({
      message: {
        type: 'wrap-dek',
        payload: { dek: params.dek, wrappingKey: params.wrappingKey },
      },
    });

    const unwrapped = unwrapResponse({ response, expectedType: 'dek-wrapped' });
    return (unwrapped as Extract<CryptoWorkerResponse, { type: 'dek-wrapped' }>).payload;
  },

  async unwrapDek(params: { wrapped: WrappedKey; unwrappingKey: CryptoKey }): Promise<CryptoKey> {
    const response = await sendMessage({
      message: {
        type: 'unwrap-dek',
        payload: { wrapped: params.wrapped, unwrappingKey: params.unwrappingKey },
      },
    });

    const unwrapped = unwrapResponse({ response, expectedType: 'dek-unwrapped' });
    const dek = (unwrapped as Extract<CryptoWorkerResponse, { type: 'dek-unwrapped' }>).payload.dek;
    keyState.dek = dek;
    return dek;
  },

  async encryptProfile(params: { dek: CryptoKey; profile: UserProfile }): Promise<EncryptedBlob> {
    const response = await sendMessage({
      message: {
        type: 'encrypt-profile',
        payload: { dek: params.dek, profile: params.profile },
      },
    });

    const unwrapped = unwrapResponse({ response, expectedType: 'profile-encrypted' });
    return (unwrapped as Extract<CryptoWorkerResponse, { type: 'profile-encrypted' }>).payload;
  },

  async decryptProfile(params: { dek: CryptoKey; blob: EncryptedBlob }): Promise<UserProfile> {
    const response = await sendMessage({
      message: {
        type: 'decrypt-profile',
        payload: { dek: params.dek, blob: params.blob },
      },
    });

    const unwrapped = unwrapResponse({ response, expectedType: 'profile-decrypted' });
    const profile = (unwrapped as Extract<CryptoWorkerResponse, { type: 'profile-decrypted' }>).payload;
    cachedProfile = profile;
    return profile;
  },

  async computeAuthProof(params: { keyA: CryptoKey }): Promise<string> {
    const response = await sendMessage({
      message: {
        type: 'compute-auth-proof',
        payload: { keyA: params.keyA, context: 'auth' },
      },
    });

    const unwrapped = unwrapResponse({ response, expectedType: 'auth-proof-computed' });
    return (unwrapped as Extract<CryptoWorkerResponse, { type: 'auth-proof-computed' }>).payload.proof;
  },

  async generateSeedPhrase(): Promise<string> {
    const response = await sendMessage({ message: { type: 'generate-seed-phrase' } });

    const unwrapped = unwrapResponse({ response, expectedType: 'seed-phrase-generated' });
    return (unwrapped as Extract<CryptoWorkerResponse, { type: 'seed-phrase-generated' }>).payload.phrase;
  },

  async deriveKeyR(params: { seedPhrase: string; salt: string; iterations?: number }): Promise<CryptoKey> {
    const response = await sendMessage({
      message: {
        type: 'derive-key-r',
        payload: {
          seedPhrase: params.seedPhrase,
          salt: params.salt,
          iterations: params.iterations ?? 1_000_000,
        },
      },
    });

    const unwrapped = unwrapResponse({ response, expectedType: 'key-derived' });
    return (unwrapped as Extract<CryptoWorkerResponse, { type: 'key-derived' }>).payload.key;
  },

  async computeHmacProof(params: { key: CryptoKey; context: string }): Promise<string> {
    const response = await sendMessage({
      message: {
        type: 'compute-auth-proof',
        payload: { keyA: params.key, context: params.context },
      },
    });

    const unwrapped = unwrapResponse({ response, expectedType: 'auth-proof-computed' });
    return (unwrapped as Extract<CryptoWorkerResponse, { type: 'auth-proof-computed' }>).payload.proof;
  },

  clearKeys(): void {
    keyState.dek = null;
    keyState.keyA = null;
    keyState.isAdvanced = false;
    keyState.loginId = null;
    cachedProfile = null;
    cachedProfileVersion = null;
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

  getDek(): CryptoKey | null {
    return keyState.dek;
  },

  getKeyA(): CryptoKey | null {
    return keyState.keyA;
  },

  isAdvanced(): boolean {
    return keyState.isAdvanced;
  },

  getLoginId(): string | null {
    return keyState.loginId;
  },

  initializeAdvancedState(params: { loginId: string }): void {
    keyState.isAdvanced = true;
    keyState.loginId = params.loginId;
  },

  terminate(): void {
    terminateWorker();
  },
};

export default cryptoService;
export { cryptoService, getWorker, terminateWorker };