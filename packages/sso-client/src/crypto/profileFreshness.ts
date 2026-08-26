// SPDX-License-Identifier: MIT

/**
 * Profile freshness — silent cross-session refresh.
 *
 * The server bumps a profile_version counter when an advanced user's profile
 * changes (it knows that data changed, not what changed). With a DEK resident
 * in the worker (or restored via PRF), the client can re-download the blobs,
 * compare versions, and decrypt a newer profile without a password.
 *
 * If PRF is unsupported, no DEK is resident and the caller falls back to
 * password re-auth — this module returns false (no refresh happened).
 */

import { getCachedProfileVersion } from './profileCipher';
import type { EncryptedBlob } from '../types/crypto';

export interface ProfileBlobs {
  encrypted_profile: EncryptedBlob;
  profile_version: number;
}

export async function shouldRefresh(params: {
  cachedVersion: number | null;
  remoteVersion: number;
}): Promise<boolean> {
  return params.cachedVersion === null || params.remoteVersion > params.cachedVersion;
}

/**
 * Best-effort silent refresh: fetch blobs, compare versions, decrypt only when
 * newer. Returns true when the profile was refreshed. All I/O is injected so
 * callers (and tests) can provide real HTTP or fakes.
 */
export async function syncProfileSilently(params: {
  loginId: string;
  fetchBlobs: (params: { loginId: string }) => Promise<ProfileBlobs>;
  hasResidentDek: () => Promise<boolean>;
  decryptAndCache: (params: { blob: EncryptedBlob; version: number }) => Promise<void>;
}): Promise<boolean> {
  const hasDek = await params.hasResidentDek();
  if (!hasDek) {
    // Fail-secure: without a resident DEK (e.g. no PRF), we cannot silently
    // refresh — the caller must re-auth by password.
    return false;
  }

  try {
    const blobs = await params.fetchBlobs({ loginId: params.loginId });
    if (!(await shouldRefresh({ cachedVersion: getCachedProfileVersion(), remoteVersion: blobs.profile_version }))) {
      return false;
    }
    await params.decryptAndCache({ blob: blobs.encrypted_profile, version: blobs.profile_version });
    return true;
  } catch {
    return false;
  }
}