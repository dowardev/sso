// SPDX-License-Identifier: MIT
/**
 * Vitest setup for the frontend suite.
 *
 * Node >= 24 ships a global `localStorage` whose getter returns `undefined`
 * (and warns) unless the runtime is started with `--localstorage-file`.
 * Vitest's jsdom environment does not replace that global, so every test that
 * touches `localStorage` fails on the LTS node.
 *
 * The setup below keeps a working storage in the test environment: it reuses
 * the jsdom implementation when it is usable and falls back to a minimal
 * in-memory `Storage` otherwise. This keeps the suite green on `node = "lts"`
 * (see `mise.toml`) without extra runtime flags.
 */
function isUsable(storage: unknown): boolean {
  if (!storage) return false;

  try {
    (storage as Storage).getItem('__probe__');
    return true;
  } catch {
    return false;
  }
}

function createMemoryStorage(): Storage {
  const entries = new Map<string, string>();

  return {
    get length(): number {
      return entries.size;
    },
    key(index: number): string | null {
      return Array.from(entries.keys())[index] ?? null;
    },
    getItem(key: string): string | null {
      return entries.has(key) ? (entries.get(key) as string) : null;
    },
    setItem(key: string, value: string): void {
      entries.set(key, String(value));
    },
    removeItem(key: string): void {
      entries.delete(key);
    },
    clear(): void {
      entries.clear();
    },
  };
}

function installStorage(name: 'localStorage' | 'sessionStorage'): void {
  const current = (globalThis as Record<string, unknown>)[name];
  if (isUsable(current)) return;

  const view = globalThis.document?.defaultView as unknown as
    | Record<string, unknown>
    | undefined;
  const fromJsdom = view?.[name];

  Object.defineProperty(globalThis, name, {
    value: isUsable(fromJsdom) ? fromJsdom : createMemoryStorage(),
    configurable: true,
    writable: true,
  });
}

installStorage('localStorage');
installStorage('sessionStorage');
