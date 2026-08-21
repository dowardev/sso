// SPDX-License-Identifier: MIT
import { describe, it, expect, beforeEach } from 'vitest';
import {
  saveEmailVerifyOrigin,
  loadEmailVerifyOrigin,
  clearEmailVerifyOrigin,
} from '../emailVerifyOrigin';

const ORIGIN_KEY = 'email_verify_origin';

describe('emailVerifyOrigin', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('saves and loads the admin origin', () => {
    saveEmailVerifyOrigin('admin');
    expect(loadEmailVerifyOrigin()).toBe('admin');
  });

  it('saves and loads the public origin', () => {
    saveEmailVerifyOrigin('public');
    expect(loadEmailVerifyOrigin()).toBe('public');
  });

  it('overwrites a previous origin', () => {
    saveEmailVerifyOrigin('admin');
    saveEmailVerifyOrigin('public');
    expect(loadEmailVerifyOrigin()).toBe('public');
  });

  it('returns null when no origin is stored', () => {
    expect(loadEmailVerifyOrigin()).toBeNull();
  });

  it('returns null for an unknown stored value', () => {
    localStorage.setItem(ORIGIN_KEY, 'other');
    expect(loadEmailVerifyOrigin()).toBeNull();
  });

  it('clears the stored origin', () => {
    saveEmailVerifyOrigin('admin');
    clearEmailVerifyOrigin();
    expect(localStorage.getItem(ORIGIN_KEY)).toBeNull();
    expect(loadEmailVerifyOrigin()).toBeNull();
  });
});