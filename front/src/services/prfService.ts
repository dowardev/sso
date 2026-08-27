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
  detectPrfSupport,
  derivePrfKey,
  prfStore,
  persistPrfWrappedDek,
  type PrfCeremonyResult,
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

/** Persist the PRF-wrapped DEK for the given credential (fail-secure). */
export async function enrollDekWithPrf(params: {
  dekBytes: Uint8Array;
  prf: PrfCeremonyResult;
}): Promise<boolean> {
  const support = detectPrfSupport();
  if (!support.supported) return false;

  const entry = await persistPrfWrappedDek({
    dekBytes: params.dekBytes,
    prfOutput: params.prf.prfOutput,
    credentialId: params.prf.credentialId,
  });
  return entry !== null;
}

/** Silent restore: PRF gesture -> K_PRF -> unwrap resident DEK in the worker. */
export async function restoreDekSilently(params: {
  buildRequest: (req: {
    allowCredentials: Array<{ type: string; id: ArrayBuffer; transports?: string[] }>;
    userVerification: string;
    extensions: { prf: { eval: { first: ArrayBuffer } } };
  }) => unknown;
}): Promise<boolean> {
  const support = detectPrfSupport();
  if (!support.supported) return false;

  const entries = await prfStore.listEntries();
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

  const wrapped: WrappedKey = { wrapped: entry.wrappedDek, algorithm: 'AES-KW' };
  await cryptoService.unwrapDek({ wrapped, unwrappingKey: kPrfKey });

  // Drop K_PRF bytes from the main heap.
  kPrf.fill(0);
  return true;
}

export function hasPrfEntries(): Promise<boolean> {
  return prfStore.listEntries().then((e) => e.length > 0);
}

export function clearPrfEntries(): Promise<void> {
  return prfStore.clear();
}