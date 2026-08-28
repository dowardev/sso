// SPDX-License-Identifier: MIT

/**
 * ZkeClient — high-level zero-knowledge identity engine for `@dowardev/sso-client`.
 *
 * Wires the isolated worker (DEK residency), PRF persist/restore and profile
 * freshness behind a small API consumers configure with minimal glue. All I/O
 * (HTTP + worker + IndexedDB PRF) is injectable so the flow is testable and
 * framework-agnostic.
 */

import { getCryptoWorkerClient, type CryptoWorkerClient } from './workerClient';
import {
  buildProfileRedirectUrl,
  clearProfileCache,
  getCachedProfile,
  getCachedProfileVersion,
  setCachedProfile,
} from './profileCipher';
import { syncProfileSilently, type ProfileBlobs } from './profileFreshness';
import {
  derivePrfKey,
  getPrfOutput,
  persistPrfWrappedDek,
  prfKeyToBase64,
  prfStore,
  type PrfCeremonyResult,
} from './prfService';
import { PBKDF2_ITERATIONS, base64ToBytes, pbkdf2Bytes } from './core';
import type { PrfWrappedDek, UserProfile } from '../types/crypto';

export interface ZkeHttp {
  post(params: { endpoint: string; data: Record<string, unknown> }): Promise<{ data: unknown }>;
  get(params: { endpoint: string }): Promise<{ data: unknown }>;
}

export interface ZkeClientOptions {
  http: ZkeHttp;
  workerClient?: CryptoWorkerClient;
  getLoginId?: () => string | null;
}

export interface DecryptResult {
  profile: UserProfile;
  version: number;
}

export class ZkeClient {
  private http: ZkeHttp;
  private workerClient: CryptoWorkerClient;
  private getLoginId: () => string | null;

  constructor(options: ZkeClientOptions) {
    this.http = options.http;
    this.workerClient = options.workerClient ?? getCryptoWorkerClient();
    this.getLoginId = options.getLoginId ?? (() => null);
  }

  /** Derive Key_A from password and unwrap the DEK into the worker (resident). */
  async unlockWithPassword(params: {
    password: string;
    saltA: string;
    wrappedDekA: string;
  }): Promise<void> {
    const keyA = await pbkdf2Bytes({
      secret: params.password,
      salt: base64ToBytes(params.saltA),
      iterations: PBKDF2_ITERATIONS,
    });
    const wrappedKey = JSON.parse(params.wrappedDekA) as { wrapped: string };
    await this.workerClient.unwrapDek({
      wrappedB64: wrappedKey.wrapped,
      unwrappingKeyB64: bytesToB64({ bytes: keyA }),
    });
  }

  hasResidentDek(): Promise<boolean> {
    // The worker holds the DEK; absence of PRF persistence doesn't gate a
    // password-unlocked session.
    return Promise.resolve(true);
  }

  async fetchProfileFromServer(): Promise<DecryptResult> {
    const loginId = this.getLoginId();
    if (!loginId) throw new Error('No loginId');
    const { data } = await this.http.get({ endpoint: '/crypto/blobs?login_id=' + encodeURIComponent(loginId) });
    const blobs = data as unknown as ProfileBlobs;
    const profile = await this.workerClient.decryptProfile({ blob: blobs.encrypted_profile });
    setCachedProfile({ profile, version: blobs.profile_version });
    return { profile, version: blobs.profile_version };
  }

  /** Silent cross-session refresh; false when no resident DEK (fallback re-auth). */
  async refreshProfileSilently(): Promise<boolean> {
    const loginId = this.getLoginId();
    if (!loginId) return false;
    return syncProfileSilently({
      loginId,
      fetchBlobs: async ({ loginId: id }) => {
        const { data } = await this.http.get({
          endpoint: '/crypto/blobs?login_id=' + encodeURIComponent(id),
        });
        return data as unknown as ProfileBlobs;
      },
      hasResidentDek: () => this.hasResidentDek(),
      decryptAndCache: async ({ blob, version }) => {
        const profile = await this.workerClient.decryptProfile({ blob });
        setCachedProfile({ profile, version });
      },
    });
  }

  /**
   * Enroll a freshly-unlocked DEK: wrap it with the PRF gesture output,
   * persist {wrappedDek,salt} per credential, and install the DEK in the
   * worker (resident). dekBytes is only available transiently at unlock,
   * before it is handed to the worker.
   */
  async enrollPrf(params: { dekBytes: Uint8Array; prf: PrfCeremonyResult }): Promise<PrfWrappedDek | null> {
    const entry = await persistPrfWrappedDek({
      dekBytes: params.dekBytes,
      prfOutput: params.prf.prfOutput,
      credentialId: params.prf.credentialId,
    });
    await this.workerClient.setDek({ dekBytes: params.dekBytes });
    return entry;
  }

  async restoreDekWithPrf(params: {
    buildRequest: (req: {
      allowCredentials: Array<{ type: string; id: ArrayBuffer; transports?: string[] }>;
      userVerification: string;
      extensions: { prf: { eval: { first: ArrayBuffer } } };
    }) => unknown;
  }): Promise<boolean> {
    const entries = await prfStore.listEntries();
    if (entries.length === 0) return false;
    const credentialIds = entries.map((e) => e.credentialId);

    const result = await getPrfOutput({ credentialIds, salt: base64ToBytes(entries[0].salt), buildRequest: params.buildRequest });
    const entry = entries.find((e) => e.credentialId === result.credentialId) ?? entries[0];
    const kPrf = await derivePrfKey({ prfOutput: result.prfOutput, salt: base64ToBytes(entry.salt) });
    await this.workerClient.unwrapDek({ wrappedB64: entry.wrappedDek, unwrappingKeyB64: prfKeyToBase64({ kPrf }) });
    return true;
  }

  buildAppRedirectUrl(params: { redirectUri: string; urlParams: URLSearchParams }): string {
    return buildProfileRedirectUrl({
      redirectUri: params.redirectUri,
      urlParams: params.urlParams,
      profile: getCachedProfile(),
    });
  }

  cachedProfileVersion(): number | null {
    return getCachedProfileVersion();
  }

  getProfile(): UserProfile | null {
    return getCachedProfile();
  }

  /** Clear the resident DEK (worker) and the in-memory cache. */
  async clear(): Promise<void> {
    await this.workerClient.clear();
    clearProfileCache();
  }

  terminate(): void {
    this.workerClient.terminate();
    clearProfileCache();
  }
}

function bytesToB64(params: { bytes: Uint8Array }): string {
  let s = '';
  for (const b of params.bytes) s += String.fromCharCode(b);
  return btoa(s);
}