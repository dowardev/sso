// SPDX-License-Identifier: MIT
import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import {
  saveOauthPending,
  loadOauthPending,
  clearOauthPending,
  buildOauthLoginQuery,
  refreshOauthPendingTtl,
} from '../oauthPending';

const OAUTH_PENDING_KEY = 'oauth_pending';
const TTL_MS = 30 * 60 * 1000;

function makeParams(overrides: Record<string, string> = {}) {
  return {
    client_id: 'client-123',
    redirect_uri: 'https://app.example.com/callback',
    state: 'state-abc',
    response_type: 'code',
    code_challenge: 'challenge',
    code_challenge_method: 'S256',
    code_verifier: 'verifier',
    ...overrides,
  };
}

describe('oauthPending', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('saves and loads the OAuth context within TTL', () => {
    saveOauthPending(makeParams());
    const loaded = loadOauthPending();
    expect(loaded?.client_id).toBe('client-123');
    expect(loaded?.code_verifier).toBe('verifier');
  });

  it('returns null when the context is expired', () => {
    saveOauthPending(makeParams());
    vi.advanceTimersByTime(TTL_MS + 1);
    expect(loadOauthPending()).toBeNull();
  });

  it('clears the context', () => {
    saveOauthPending(makeParams());
    clearOauthPending();
    expect(localStorage.getItem(OAUTH_PENDING_KEY)).toBeNull();
    expect(loadOauthPending()).toBeNull();
  });

  it('refreshOauthPendingTtl extends the deadline', () => {
    saveOauthPending(makeParams());
    vi.advanceTimersByTime(TTL_MS - 1000);
    refreshOauthPendingTtl();
    vi.advanceTimersByTime(2000);
    expect(loadOauthPending()).not.toBeNull();
  });

  it('buildOauthLoginQuery returns the full query shape', () => {
    const query = buildOauthLoginQuery(makeParams());
    expect(query).toEqual({
      client_id: 'client-123',
      redirect_uri: 'https://app.example.com/callback',
      state: 'state-abc',
      response_type: 'code',
      code_challenge: 'challenge',
      code_challenge_method: 'S256',
      code_verifier: 'verifier',
    });
  });

  it('buildOauthLoginQuery omits empty optional fields', () => {
    const query = buildOauthLoginQuery(makeParams({ state: '', code_verifier: '' }));
    expect(query.state).toBeUndefined();
    expect(query.code_verifier).toBeUndefined();
  });
});