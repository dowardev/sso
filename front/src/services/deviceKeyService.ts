// SPDX-License-Identifier: MIT

/**
 * Device Key Service — silent DEK persistence per device
 *
 * On a fresh advanced login, the DEK is re-wrapped with a device-local
 * non-extractable AES-KW key and both are stored in IndexedDB. Later
 * sessions on the same device can unwrap the DEK silently (no password),
 * enabling profile refresh on page reload, on a schedule, or via a
 * manual button.
 *
 * Security model:
 * - The device key is NON-extractable: JS code (including XSS) can invoke
 *   unwrapKey through WebCrypto but cannot read its raw bytes.
 * - The wrapped DEK is useless outside this device/origin.
 * - Logout MUST call clearDeviceKeys() to wipe everything.
 */

// ─── IndexedDB Helpers ────────────────────────────────────────────────────────

const DB_NAME = 'zke_device_keys';
const STORE = 'keys';

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE);
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function idbGet(params: { key: string }): Promise<unknown> {
  return new Promise((resolve, reject) => {
    openDb().then((db) => {
      const tx = db.transaction(STORE, 'readonly');
      const req = tx.objectStore(STORE).get(params.key);
      req.onsuccess = () => {
        db.close();
        resolve(req.result ?? null);
      };
      req.onerror = () => {
        db.close();
        reject(req.error);
      };
    }).catch(reject);
  });
}

function idbPut(params: { key: string; value: unknown }): Promise<void> {
  return new Promise((resolve, reject) => {
    openDb().then((db) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).put(params.value, params.key);
      tx.oncomplete = () => {
        db.close();
        resolve();
      };
      tx.onerror = () => {
        db.close();
        reject(tx.error);
      };
    }).catch(reject);
  });
}

function idbDelete(params: { key: string }): Promise<void> {
  return new Promise((resolve, reject) => {
    openDb().then((db) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).delete(params.key);
      tx.oncomplete = () => {
        db.close();
        resolve();
      };
      tx.onerror = () => {
        db.close();
        reject(tx.error);
      };
    }).catch(reject);
  });
}

// ─── Device Key Management ────────────────────────────────────────────────────

const DEVICE_KEY = 'device_key';
const WRAPPED_DEK = 'wrapped_dek';
const DEVICE_KEY_ALGORITHM = 'AES-KW';
const DEVICE_KEY_LENGTH = 256;

/**
 * Return the device-local wrapping key, generating it once if absent.
 * The key is non-extractable: it can wrap/unwrap via WebCrypto but its
 * raw bytes can never be read back.
 */
async function getOrCreateDeviceKey(): Promise<CryptoKey> {
  const existing = (await idbGet({ key: DEVICE_KEY })) as CryptoKey | null;
  if (existing) {
    return existing;
  }

  const key = await crypto.subtle.generateKey(
    { name: DEVICE_KEY_ALGORITHM, length: DEVICE_KEY_LENGTH },
    false, // non-extractable — raw bytes never leave WebCrypto
    ['wrapKey', 'unwrapKey'],
  );

  await idbPut({ key: DEVICE_KEY, value: key });
  return key;
}

// ─── Public API ───────────────────────────────────────────────────────────────

export const deviceKeyService = {
  /**
   * Persist the DEK wrapped with this device's local key.
   * Call after every fresh advanced login / registration / upgrade.
   */
  async persistDek(params: { dek: CryptoKey }): Promise<void> {
    const deviceKey = await getOrCreateDeviceKey();
    const wrapped = await crypto.subtle.wrapKey('raw', params.dek, deviceKey, {
      name: DEVICE_KEY_ALGORITHM,
    });
    await idbPut({ key: WRAPPED_DEK, value: wrapped });
  },

  /**
   * Silently recover the DEK on this device (no password).
   * Returns null when there is no persisted DEK (fresh browser,
   * cleared storage, or after logout).
   */
  async restoreDek(): Promise<CryptoKey | null> {
    const deviceKey = (await idbGet({ key: DEVICE_KEY })) as CryptoKey | null;
    const wrapped = (await idbGet({ key: WRAPPED_DEK })) as ArrayBuffer | null;

    if (!deviceKey || !wrapped) {
      return null;
    }

    try {
      return await crypto.subtle.unwrapKey(
        'raw',
        wrapped,
        deviceKey,
        { name: DEVICE_KEY_ALGORITHM },
        { name: 'AES-GCM', length: 256 },
        true, // extractable — needed to re-wrap on password rotation
        ['encrypt', 'decrypt'],
      );
    } catch {
      // Corrupted or incompatible stored state — treat as absent.
      await this.clear();
      return null;
    }
  },

  /**
   * Whether a device-persisted DEK exists for silent restore.
   */
  async hasPersistedDek(): Promise<boolean> {
    const wrapped = (await idbGet({ key: WRAPPED_DEK })) as ArrayBuffer | null;
    return wrapped !== null;
  },

  /**
   * Wipe all device key material. Call on logout.
   */
  async clear(): Promise<void> {
    await idbDelete({ key: WRAPPED_DEK });
    await idbDelete({ key: DEVICE_KEY });
  },
};

export default deviceKeyService;