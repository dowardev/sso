// SPDX-License-Identifier: MIT

import { describe, expect, it, vi, afterEach } from 'vitest';
import {
  buildProfileRedirectUrl,
  clearProfileCache,
  decodeProfileFragment,
  encodeProfileFragment,
  getCachedProfile,
  setCachedProfile,
} from './profileCipher';
import { shouldRefresh, syncProfileSilently } from './profileFreshness';

describe('profile cipher + freshness', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    clearProfileCache();
  });

  it('#profile= fragment round-trips unicode (accented, emoji)', () => {
    const profile = { name: 'Ada Lovelace', user: 'ada', email: 'adá@ejemplo.com', city: 'Méndez 🚀' };
    const encoded = encodeProfileFragment({ profile });
    const decoded = decodeProfileFragment({ encoded });
    expect(decoded).toEqual(profile);
  });

  it('buildProfileRedirectUrl appends the profile to the fragment, never the query', () => {
    const url = buildProfileRedirectUrl({
      redirectUri: 'https://app.example.com/auth/callback',
      urlParams: new URLSearchParams({ code: 'abc', state: 'xyz' }),
      profile: { name: 'Ada', user: 'ada', email: 'a@x.com' },
    });
    expect(url).toContain('?code=abc&state=xyz');
    expect(url).toContain('#profile=');
    // profile payload is in the fragment, not the query
    const [beforeHash, afterHash] = url.split('#');
    expect(beforeHash).not.toContain('Ada');
    expect(afterHash).toContain('profile=');
  });

  it('shouldRefresh compares profile_version correctly', async () => {
    await expect(shouldRefresh({ cachedVersion: null, remoteVersion: 3 })).resolves.toBe(true);
    await expect(shouldRefresh({ cachedVersion: 3, remoteVersion: 3 })).resolves.toBe(false);
    await expect(shouldRefresh({ cachedVersion: 2, remoteVersion: 3 })).resolves.toBe(true);
  });

  it('syncProfileSilently skips refresh without a resident DEK (fail-secure)', async () => {
    const refreshed = await syncProfileSilently({
      loginId: 'id',
      fetchBlobs: vi.fn(),
      hasResidentDek: async () => false,
      decryptAndCache: vi.fn(),
    });
    expect(refreshed).toBe(false);
  });

  it('syncProfileSilently decrypts only when remote version is newer', async () => {
    setCachedProfile({ profile: { name: 'old', user: 'u', email: 'e' }, version: 1 });
    const decryptAndCache = vi.fn();
    const refreshed = await syncProfileSilently({
      loginId: 'id',
      fetchBlobs: async () => ({
        encrypted_profile: { ciphertext: 'x', iv: 'y', authTag: 'z' },
        profile_version: 2,
      }),
      hasResidentDek: async () => true,
      decryptAndCache,
    });
    expect(refreshed).toBe(true);
    expect(decryptAndCache).toHaveBeenCalledWith({ blob: { ciphertext: 'x', iv: 'y', authTag: 'z' }, version: 2 });
  });

  it('syncProfileSilently does nothing when version is not newer', async () => {
    setCachedProfile({ profile: { name: 'x', user: 'u', email: 'e' }, version: 5 });
    const decryptAndCache = vi.fn();
    const refreshed = await syncProfileSilently({
      loginId: 'id',
      fetchBlobs: async () => ({
        encrypted_profile: { ciphertext: 'x', iv: 'y', authTag: 'z' },
        profile_version: 5,
      }),
      hasResidentDek: async () => true,
      decryptAndCache,
    });
    expect(refreshed).toBe(false);
    expect(decryptAndCache).not.toHaveBeenCalled();
  });

  it('setCachedProfile/getCachedProfile store the session profile', () => {
    setCachedProfile({ profile: { name: 'Ada', user: 'ada', email: 'a@x.com' }, version: 7 });
    expect(getCachedProfile()).toEqual({ name: 'Ada', user: 'ada', email: 'a@x.com' });
  });
});