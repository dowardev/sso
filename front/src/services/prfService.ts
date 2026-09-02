// SPDX-License-Identifier: MIT

/**
 * Frontend PRF bridge — enroll/restore the ZKE DEK with a WebAuthn passkey.
 *
 * The DEK stays resident in the worker (never in main-thread state). To enable
 * silent cross-session refresh, we wrap the RESIDENT DEK with K_PRF (via the
 * worker's wrap/unwrap) and persist only {wrappedDek, salt, credentialId}.
 * All PRF primitives come from @dowardev/sso-client.
 */

import {
  bytesToBase64,
  detectPrfSupport,
  derivePrfKey,
  prfStore,
} from '@dowardev/sso-client';
import cryptoService from './cryptoService';
import type { WrappedKey } from '@/types/crypto';

function b64ToBytes(b64: string): Uint8Array {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

async function importAesKwFromBytes(bytes: Uint8Array): Promise<CryptoKey> {
  return crypto.subtle.importKey(
    'raw',
    bytes as unknown as BufferSource,
    { name: 'AES-KW', length: 256 },
    false,
    ['wrapKey', 'unwrapKey'],
  ) as Promise<CryptoKey>;
}

/** Enroll the worker-resident DEK wrapped with K_PRF for a PRF-capable credential. */
export async function enrollPrfForCredential(params: {
  credentialId: string;
}): Promise<boolean> {
  const support = detectPrfSupport();
  if (!support.supported) return false;

  // Fresh random salt persisted alongside the wrapped DEK (b64 format matches
  // restoreDekSilently, which decodes it via b64ToBytes(entry.salt)).
  const salt = crypto.getRandomValues(new Uint8Array(32));
  const saltB64 = bytesToBase64(salt);

  // One biometric gesture to derive the PRF secret for this credential.
  const { getPrfOutput } = await import('@dowardev/sso-client');
  const result = await getPrfOutput({
    credentialIds: [params.credentialId],
    salt,
    buildRequest: (req) => req,
  });

  const kPrf = await derivePrfKey({ prfOutput: result.prfOutput, salt });
  const kPrfKey = await importAesKwFromBytes(kPrf);

  // Wrap the RESIDENT DEK in the worker (never read raw DEK bytes here) — the
  // worker returns only the wrapped blob.
  const wrapped = await cryptoService.wrapDek({ wrappingKey: kPrfKey });

  await prfStore.putEntry({
    entry: {
      wrappedDek: wrapped.wrapped,
      salt: saltB64,
      credentialId: result.credentialId,
    },
  });

  // Drop K_PRF bytes from the main heap.
  kPrf.fill(0);
  return true;
}

/** Silent restore: PRF gesture -> K_PRF -> unwrap resident DEK in the worker. */
export async function restoreDekSilently(params: {
  buildRequest: (req: {
    allowCredentials: Array<{ type: string; id: ArrayBuffer; transports?: string[] }>;
    userVerification: string;
    extensions: { prf: { eval: { first: ArrayBuffer } } };
  }) => unknown;
  credentialId?: string;
}): Promise<boolean> {
  const support = detectPrfSupport();
  if (!support.supported) return false;

  // Scope the PRF lookup to the credential that actually authenticated on this
  // login (when known) so a multi-account browser never unwraps the DEK with
  // another account's PRF entry.
  const credentialId = params.credentialId
    ? normalizePrfCredentialId({ id: params.credentialId })
    : null;
  const entries = credentialId
    ? (await prfStore.listEntries()).filter((e) => normalizePrfCredentialId({ id: e.credentialId }) === credentialId)
    : await prfStore.listEntries();
  if (entries.length === 0) return false;

  const { getPrfOutput } = await import('@dowardev/sso-client');
  const result = await getPrfOutput({
    credentialIds: entries.map((e) => e.credentialId),
    salt: b64ToBytes(entries[0].salt),
    buildRequest: params.buildRequest,
  });

  const entry = entries.find((e) => e.credentialId === result.credentialId) ?? entries[0];

  // Derive K_PRF and unwrap the resident DEK in the worker (K_PRF bytes are
  // transient in main-thread during this ceremony only).
  const saltBytes = b64ToBytes(entry.salt);
  const kPrf = await derivePrfKey({ prfOutput: result.prfOutput, salt: saltBytes });
  const kPrfKey = await importAesKwFromBytes(kPrf);

  await cryptoService.unwrapDek({
    wrapped: JSON.stringify({ wrapped: entry.wrappedDek, algorithm: 'AES-KW' }),
    unwrappingKey: kPrfKey,
  });

  // Drop K_PRF bytes from the main heap.
  kPrf.fill(0);
  return true;
}

export async function hasPrfEntries(params?: {
  credentialId?: string;
}): Promise<boolean> {
  const entries = await prfStore.listEntries();
  if (entries.length === 0) return false;
  if (!params?.credentialId) return true;
  const id = normalizePrfCredentialId({ id: params.credentialId });
  return entries.some((e) => normalizePrfCredentialId({ id: e.credentialId }) === id);
}

/** Normalize a credential id to base64url so lookups match regardless of the
 * format the server (standard base64) or the PRF store (base64url) used. */
function normalizePrfCredentialId(params: { id: string }): string {
  return params.id
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
}

/** Remove a PRF entry. The server returns standard base64 ids while the store
 * keys are base64url; try both so either format is covered (missing key no-op). */
export async function removePrfEntry(params: {
  credentialId: string;
}): Promise<void> {
  const { credentialId } = params;
  await prfStore.removeEntry({ credentialId });
  const urlEncoded = normalizePrfCredentialId({ id: credentialId });
  if (urlEncoded !== credentialId) {
    await prfStore.removeEntry({ credentialId: urlEncoded });
  }
}

export function clearPrfEntries(): Promise<void> {
  return prfStore.clear();
}
