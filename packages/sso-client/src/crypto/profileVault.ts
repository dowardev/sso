// SPDX-License-Identifier: MIT
import type { UserProfile } from "../types/crypto";

const DB_NAME = "sso_client_vault";
const DB_VERSION = 1;
const STORE = "kv";
const KEY_RECORD_ID = "profileKey";
const PROFILE_RECORD_ID = "profile";
const IV_BYTES = 12;

interface StoredProfile {
  iv: number[];
  cipher: number[];
}

/**
 * In-memory promise for the non-extractable AES-GCM vault key. Created exactly
 * once per tab; reused for every encrypt/decrypt so cross-reference within the
 * tab is consistent. Reset to null on logout so a fresh key is minted next time.
 */
let keyPromise: Promise<CryptoKey> | null = null;

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE);
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function reqDone<T>(req: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function txDone(tx: IDBTransaction): Promise<void> {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error);
  });
}

async function getKey(): Promise<CryptoKey> {
  if (!keyPromise) {
    keyPromise = (async () => {
      let db = await openDb();
      try {
        const existing = await reqDone<CryptoKey | undefined>(
          db.transaction(STORE, "readonly").objectStore(STORE).get(KEY_RECORD_ID)
        );
        if (existing) return existing;
      } finally {
        db.close();
      }

      const key = await crypto.subtle.generateKey(
        { name: "AES-GCM", length: 256 },
        false,
        ["encrypt", "decrypt"]
      );

      db = await openDb();
      try {
        await reqDone(
          db.transaction(STORE, "readwrite").objectStore(STORE).put(key, KEY_RECORD_ID)
        );
      } finally {
        db.close();
      }
      return key;
    })();
  }
  return keyPromise;
}

/** Encrypt and persist the verified profile at rest. */
export async function persistProfileAtRest(profile: UserProfile): Promise<void> {
  const key = await getKey();
  const iv = crypto.getRandomValues(new Uint8Array(IV_BYTES));
  const plain = new TextEncoder().encode(JSON.stringify(profile));
  const cipherBuffer = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, plain);
  const record: StoredProfile = {
    iv: Array.from(iv),
    cipher: Array.from(new Uint8Array(cipherBuffer)),
  };

  const db = await openDb();
  try {
    await reqDone(
      db.transaction(STORE, "readwrite").objectStore(STORE).put(record, PROFILE_RECORD_ID)
    );
  } finally {
    db.close();
  }
}

/** Load and decrypt the persisted profile, or null on absence / any failure. */
export async function loadProfileAtRest(): Promise<UserProfile | null> {
  try {
    const db = await openDb();
    let record: StoredProfile | undefined;
    try {
      record = await reqDone<StoredProfile | undefined>(
        db.transaction(STORE, "readonly").objectStore(STORE).get(PROFILE_RECORD_ID)
      );
    } finally {
      db.close();
    }

    if (!record || !Array.isArray(record.cipher) || !Array.isArray(record.iv)) {
      return null;
    }

    const key = await getKey();
    const plainBuffer = await crypto.subtle.decrypt(
      { name: "AES-GCM", iv: new Uint8Array(record.iv) },
      key,
      new Uint8Array(record.cipher)
    );
    const text = new TextDecoder().decode(plainBuffer);
    return JSON.parse(text) as UserProfile;
  } catch {
    return null;
  }
}

/** Delete the persisted profile + vault key and reset the in-memory key. */
export async function clearProfileAtRest(): Promise<void> {
  keyPromise = null;
  try {
    const db = await openDb();
    try {
      const tx = db.transaction(STORE, "readwrite");
      const store = tx.objectStore(STORE);
      store.delete(KEY_RECORD_ID);
      store.delete(PROFILE_RECORD_ID);
      await txDone(tx);
    } finally {
      db.close();
    }
  } catch {
    // best-effort cleanup
  }
}
