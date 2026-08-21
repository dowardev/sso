// SPDX-License-Identifier: MIT

export type EmailVerifyOrigin = 'admin' | 'public';

const ORIGIN_KEY = 'email_verify_origin';

export function saveEmailVerifyOrigin(origin: EmailVerifyOrigin): void {
  localStorage.setItem(ORIGIN_KEY, origin);
}

export function loadEmailVerifyOrigin(): EmailVerifyOrigin | null {
  const value = localStorage.getItem(ORIGIN_KEY);
  return value === 'admin' || value === 'public' ? value : null;
}

export function clearEmailVerifyOrigin(): void {
  localStorage.removeItem(ORIGIN_KEY);
}