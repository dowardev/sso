// SPDX-License-Identifier: MIT

import { afterAll, describe, expect, it, vi } from 'vitest';

interface Resp {
  type: string;
  payload: Record<string, unknown>;
  error?: string;
}

// Shim a minimal worker global before importing the worker module so its
// top-level `self.onmessage = async (...) =>` assignment captures a handler we
// can drive directly.
const sent: Resp[] = [];
const mockSelf = {
  onmessage: null as ((e: { data: Record<string, unknown> }) => Promise<void> | void) | null,
  postMessage: (m: Resp) => sent.push(m),
};
(globalThis as unknown as { self: unknown }).self = mockSelf;

async function loadWorker() {
  await import('./cryptoWorker');
  const handler = mockSelf.onmessage;
  if (!handler) throw new Error('worker handler not set');
  return (msg: Record<string, unknown>) => handler({ data: msg });
}

function makeProfile() {
  return { name: 'Ada', user: 'ada', email: 'ada@example.com' };
}

function b64(bytes: Uint8Array): string {
  let s = '';
  for (const b of bytes) s += String.fromCharCode(b);
  return btoa(s);
}

async function generateDek(): Promise<Uint8Array> {
  const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
  const raw = await crypto.subtle.exportKey('raw', key);
  return new Uint8Array(raw);
}

describe('crypto worker protocol (DEK residency)', () => {
  afterAll(() => {
    vi.restoreAllMocks();
    delete (globalThis as unknown as { self?: unknown }).self;
  });

  it('set-dek + encrypt + decrypt round-trips a profile without touching main thread', async () => {
    const handle = await loadWorker();

    const dek = await generateDek();
    await handle({ type: 'set-dek', payload: { dekBytes: b64(dek) } });
    sent.length = 0; // clear the 'ok' ack

    await handle({ type: 'encrypt-payload', payload: { profile: makeProfile() } });
    const enc = sent.at(-1)?.payload.blob as Record<string, string>;

    await handle({ type: 'decrypt-payload', payload: { blob: enc } });
    const dec = sent.at(-1)?.payload.profile as typeof makeProfile;

    expect(dec.name).toBe('Ada');
    expect(dec.email).toBe('ada@example.com');
  });

  it('refuses to decrypt when no DEK is set', async () => {
    const handle = await loadWorker();
    // ensure clean state
    await handle({ type: 'clear', payload: {} });

    await handle({
      type: 'decrypt-payload',
      payload: {
        blob: { ciphertext: 'xx', iv: 'xx', authTag: 'xx' },
      },
    });

    expect(sent.at(-1)?.type).toBe('error');
  });

  it('clear wipes the resident DEK', async () => {
    const handle = await loadWorker();
    const dek = await generateDek();
    await handle({ type: 'set-dek', payload: { dekBytes: b64(dek) } });
    sent.length = 0;

    await handle({ type: 'clear', payload: {} });
    expect(sent.at(-1)?.type).toBe('ok');

    await handle({
      type: 'decrypt-payload',
      payload: { blob: { ciphertext: 'xx', iv: 'xx', authTag: 'xx' } },
    });
    expect(sent.at(-1)?.type).toBe('error');
  });
});