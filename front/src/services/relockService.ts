// SPDX-License-Identifier: MIT

/**
 * ZKE inactivity re-lock.
 *
 * Bounds the XSS window: the resident DEK (and the decrypted profile in
 * memory) are dropped when the tab is idle or goes to the background. The
 * session stays logged in; re-unlocking needs a password or a PRF gesture.
 */

import cryptoService from './cryptoService';

export interface RelockOptions {
  onRelock?: () => void;
  /** Drop the DEK after this many ms without user activity. Default 5 min. */
  idleMs?: number;
  /** While hidden for this long, drop the DEK. Default 30 s. */
  hiddenGraceMs?: number;
}

export function setupZkeRelock(params: RelockOptions = {}): () => void {
  const idleMs = params.idleMs ?? 5 * 60_000;
  const hiddenGraceMs = params.hiddenGraceMs ?? 30_000;
  const onRelock = params.onRelock;

  let idleTimer: ReturnType<typeof setTimeout> | null = null;
  let hiddenTimer: ReturnType<typeof setTimeout> | null = null;
  let startHiddenAt = 0;

  const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll'];

  function relock(): void {
    cryptoService.relock();
    onRelock?.();
  }

  function scheduleIdle(): void {
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(() => relock(), idleMs);
  }

  function resetIdle(): void {
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(() => relock(), idleMs);
  }

  function onVisibilityChange(): void {
    if (document.hidden) {
      startHiddenAt = Date.now();
      if (hiddenTimer) clearTimeout(hiddenTimer);
      hiddenTimer = setTimeout(() => relock(), hiddenGraceMs);
    } else {
      startHiddenAt = 0;
      if (hiddenTimer) clearTimeout(hiddenTimer);
      resetIdle();
    }
  }

  function onBeforeUnload(): void {
    // Best-effort: wipe the DEK + cached profile from memory on close.
    cryptoService.relock();
  }

  for (const evt of ACTIVITY_EVENTS) {
    window.addEventListener(evt, scheduleIdle, { passive: true });
  }
  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener('beforeunload', onBeforeUnload);

  scheduleIdle();

  return () => {
    for (const evt of ACTIVITY_EVENTS) {
      window.removeEventListener(evt, scheduleIdle);
    }
    document.removeEventListener('visibilitychange', onVisibilityChange);
    window.removeEventListener('beforeunload', onBeforeUnload);
    if (idleTimer) clearTimeout(idleTimer);
    if (hiddenTimer) clearTimeout(hiddenTimer);
  };
}