// SPDX-License-Identifier: MIT
import "fake-indexeddb/auto";
import { IDBFactory } from "fake-indexeddb";
import { describe, it, expect, vi, beforeEach } from "vitest";
import { DpopService } from "./DpopService";

// Reads the raw stored record so the assertions do not depend on the service
// under test telling the truth about what it persisted.
function readStoredDpopRecord(): Promise<unknown> {
  return new Promise((resolve) => {
    const request = indexedDB.open("dpop_keys", 1);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains("keys")) {
        db.createObjectStore("keys");
      }
    };
    request.onsuccess = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains("keys")) {
        db.close();
        resolve(null);
        return;
      }
      const tx = db.transaction("keys", "readonly");
      const getReq = tx.objectStore("keys").get("dpop");
      getReq.onsuccess = () => {
        const value = getReq.result ?? null;
        db.close();
        resolve(value);
      };
      getReq.onerror = () => {
        db.close();
        resolve(null);
      };
    };
    request.onerror = () => resolve(null);
  });
}

function holdDatabaseOpen(): Promise<IDBDatabase> {
  return new Promise((resolve) => {
    const request = indexedDB.open("dpop_keys", 1);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains("keys")) {
        db.createObjectStore("keys");
      }
    };
    request.onsuccess = () => resolve(request.result);
  });
}

describe("DpopService key lifecycle", () => {
  beforeEach(() => {
    vi.stubGlobal("indexedDB", new IDBFactory());
  });

  it("removes the stored key pair on clearKeys", async () => {
    const service = new DpopService();
    await service.initialize();

    expect(await readStoredDpopRecord()).toBeTruthy();

    await service.clearKeys();

    expect(await readStoredDpopRecord()).toBeNull();
  });

  it("does not hang on clearKeys while another connection holds the database open", async () => {
    const service = new DpopService();
    await service.initialize();

    // A second connection (another tab, or a handle leaked by a previous
    // session) is exactly what made deleteDatabase() fire onblocked and never
    // settle, leaving the key pair alive and logout stuck.
    const squatter = await holdDatabaseOpen();

    const outcome = await Promise.race([
      service.clearKeys().then(() => "cleared"),
      new Promise((resolve) => setTimeout(() => resolve("hung"), 3000)),
    ]);

    expect(outcome).toBe("cleared");
    expect(await readStoredDpopRecord()).toBeNull();

    squatter.close();
  });

  it("regenerates a usable key pair after clearKeys", async () => {
    const service = new DpopService();
    await service.initialize();
    const firstThumbprint = service.getJwkThumbprint();

    await service.clearKeys();
    await service.initialize();

    expect(service.getJwkThumbprint()).toBeTruthy();
    expect(service.getJwkThumbprint()).not.toBe(firstThumbprint);
  });
});
