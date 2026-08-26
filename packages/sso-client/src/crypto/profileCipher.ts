// SPDX-License-Identifier: MIT

/**
 * Profile cipher + delivery helpers.
 *
 * The DEK stays resident in the worker; this module only asks the worker to
 * encrypt/decrypt the profile blob and caches the decrypted profile in memory
 * (tab-scoped). It also builds/parses the `#profile=` fragment used to hand
 * the decrypted profile to OAuth apps without touching server logs or the
 * query string.
 */

import { getCryptoWorkerClient } from './workerClient';
import type { EncryptedBlob, UserProfile } from '../types/crypto';

// ─── in-memory cached profile (tab-scoped) ───────────────────────────────────

let cachedProfile: UserProfile | null = null;
let cachedProfileVersion: number | null = null;

export function setCachedProfile(params: { profile: UserProfile; version: number }): void {
  cachedProfile = params.profile;
  cachedProfileVersion = params.version;
}

export function getCachedProfile(): UserProfile | null {
  return cachedProfile;
}

export function getCachedProfileVersion(): number | null {
  return cachedProfileVersion;
}

export function clearProfileCache(): void {
  cachedProfile = null;
  cachedProfileVersion = null;
}

// ─── cipher (via isolated worker) ────────────────────────────────────────────

export async function encryptProfile(params: { profile: UserProfile }): Promise<EncryptedBlob> {
  return getCryptoWorkerClient().encryptProfile(params.profile);
}

export async function decryptProfile(params: { blob: EncryptedBlob }): Promise<UserProfile> {
  const profile = await getCryptoWorkerClient().decryptProfile(params.blob);
  cachedProfile = profile;
  return profile;
}

// ─── #profile= fragment helpers (unicode-safe base64, pure/testable) ─────────

function utf8ToBase64(params: { value: string }): string {
  const bytes = new TextEncoder().encode(params.value);
  let binary = '';
  for (const b of bytes) binary += String.fromCharCode(b);
  return btoa(binary);
}

function base64ToUtf8(params: { b64: string }): string {
  const binary = atob(params.b64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return new TextDecoder().decode(bytes);
}

export function encodeProfileFragment(params: { profile: UserProfile }): string {
  return utf8ToBase64({ value: JSON.stringify(params.profile) });
}

export function decodeProfileFragment(params: { encoded: string }): UserProfile {
  return JSON.parse(base64ToUtf8({ b64: params.encoded })) as UserProfile;
}

/** Append the decrypted profile as a `#profile=` fragment (never in query). */
export function buildProfileRedirectUrl(params: {
  redirectUri: string;
  urlParams: URLSearchParams;
  profile: UserProfile | null;
}): string {
  let url = `${params.redirectUri}?${params.urlParams.toString()}`;
  if (params.profile) {
    url += `#profile=${encodeProfileFragment({ profile: params.profile })}`;
  }
  return url;
}