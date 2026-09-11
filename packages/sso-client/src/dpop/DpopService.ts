// SPDX-License-Identifier: MIT
import { randomUuid } from "../utils/randomUuid";

export class DpopService {
  private privateKey: CryptoKey | null = null;
  private publicKeyJwk: JsonWebKey | null = null;
  private jkt: string | null = null;

  async initialize(): Promise<void> {
    const stored = await this.loadFromIndexedDB();
    if (stored) {
      this.privateKey = stored.privateKey;
      this.publicKeyJwk = stored.publicKeyJwk;
      this.jkt = stored.jkt;
      return;
    }

    await this.generateKeyPair();
  }

  async generateKeyPair(): Promise<void> {
    const keyPair = await crypto.subtle.generateKey(
      {
        name: "RSASSA-PKCS1-v1_5",
        modulusLength: 2048,
        publicExponent: new Uint8Array([1, 0, 1]),
        hash: "SHA-256",
      },
      true,
      ["sign", "verify"]
    );

    this.privateKey = keyPair.privateKey;

    this.publicKeyJwk = await crypto.subtle.exportKey("jwk", keyPair.publicKey);

    this.publicKeyJwk = {
      kty: "RSA",
      n: this.publicKeyJwk.n,
      e: this.publicKeyJwk.e,
    };

    this.jkt = await this.computeJwkThumbprint(this.publicKeyJwk);

    await this.saveToIndexedDB();
  }

  getPublicKeyJwk(): JsonWebKey | null {
    return this.publicKeyJwk;
  }

  getJwkThumbprint(): string | null {
    return this.jkt;
  }

  async createDpopProof(
    htm: string,
    htu: string,
    accessToken?: string
  ): Promise<string> {
    if (!this.privateKey || !this.publicKeyJwk) {
      throw new Error("DPoP key pair not initialized");
    }

    if (!htm || !htu) {
      throw new Error("htm and htu are required for DPoP proof");
    }

    const iat = Math.floor(Date.now() / 1000);
    const jti = randomUuid();
    const encoder = new TextEncoder();

    const header: Record<string, unknown> = {
      typ: "dpop+jwt",
      alg: "RS256",
      jwk: {
        kty: this.publicKeyJwk.kty,
        n: this.publicKeyJwk.n,
        e: this.publicKeyJwk.e,
      },
    };

    const payload: Record<string, unknown> = {
      htm,
      htu,
      iat,
      jti,
    };

    if (accessToken) {
      const hashBuffer = await crypto.subtle.digest(
        "SHA-256",
        encoder.encode(accessToken)
      );
      payload.ath = this.base64UrlEncode(new Uint8Array(hashBuffer));
    }

    const encodedHeader = this.base64UrlEncode(
      encoder.encode(JSON.stringify(header))
    );
    const encodedPayload = this.base64UrlEncode(
      encoder.encode(JSON.stringify(payload))
    );

    const signInput = `${encodedHeader}.${encodedPayload}`;

    const signatureBuffer = await crypto.subtle.sign(
      "RSASSA-PKCS1-v1_5",
      this.privateKey,
      encoder.encode(signInput)
    );

    const encodedSignature = this.base64UrlEncode(
      new Uint8Array(signatureBuffer)
    );

    return `${signInput}.${encodedSignature}`;
  }

  async clearKeys(): Promise<void> {
    this.privateKey = null;
    this.publicKeyJwk = null;
    this.jkt = null;
    await this.deleteFromIndexedDB();
  }

  private async computeJwkThumbprint(jwk: JsonWebKey): Promise<string> {
    const canonical = JSON.stringify({
      kty: jwk.kty,
      n: jwk.n,
      e: jwk.e,
    });

    const encoder = new TextEncoder();
    const hashBuffer = await crypto.subtle.digest(
      "SHA-256",
      encoder.encode(canonical)
    );
    return this.base64UrlEncode(new Uint8Array(hashBuffer));
  }

  private base64UrlEncode(data: Uint8Array | ArrayBuffer): string {
    const bytes = data instanceof ArrayBuffer ? new Uint8Array(data) : data;
    let binary = "";
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary)
      .replace(/\+/g, "-")
      .replace(/\//g, "_")
      .replace(/=+$/, "");
  }

  private async saveToIndexedDB(): Promise<void> {
    if (!this.privateKey || !this.publicKeyJwk || !this.jkt) return;

    const jwk = await crypto.subtle.exportKey("jwk", this.privateKey);

    const data = {
      privateKeyJwk: jwk,
      publicKeyJwk: this.publicKeyJwk,
      jkt: this.jkt,
    };

    return new Promise((resolve, reject) => {
      const request = indexedDB.open("dpop_keys", 1);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains("keys")) {
          db.createObjectStore("keys");
        }
      };
      request.onsuccess = () => {
        const db = request.result;
        const tx = db.transaction("keys", "readwrite");
        const store = tx.objectStore("keys");
        store.put(data, "dpop");
        // Always close: a lingering connection blocks a later
        // deleteDatabase (logout clearKeys) forever.
        tx.oncomplete = () => {
          db.close();
          resolve();
        };
        tx.onerror = () => {
          db.close();
          reject(tx.error);
        };
      };
      request.onerror = () => reject(request.error);
    });
  }

  private async loadFromIndexedDB(): Promise<
    { privateKey: CryptoKey; publicKeyJwk: JsonWebKey; jkt: string } | null
  > {
    return new Promise((resolve) => {
      const request = indexedDB.open("dpop_keys", 1);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains("keys")) {
          db.createObjectStore("keys");
        }
      };
      request.onsuccess = async () => {
        const db = request.result;
        if (!db.objectStoreNames.contains("keys")) {
          db.close();
          resolve(null);
          return;
        }
        // A concurrent version upgrade (another tab) must not leave this
        // connection pinning the old schema open.
        db.onversionchange = () => db.close();
        let settled = false;
        const finish = (
          value: { privateKey: CryptoKey; publicKeyJwk: JsonWebKey; jkt: string } | null
        ): void => {
          if (settled) return;
          settled = true;
          db.close();
          resolve(value);
        };
        const tx = db.transaction("keys", "readonly");
        // Without these, a transaction that aborts (or an error outside the
        // request callbacks) never settles the promise and init() hangs.
        tx.onabort = () => finish(null);
        tx.onerror = () => finish(null);
        const store = tx.objectStore("keys");
        const getReq = store.get("dpop");
        getReq.onsuccess = async () => {
          const data = getReq.result;
          if (!data) {
            finish(null);
            return;
          }
          try {
            const privateKey = await crypto.subtle.importKey(
              "jwk",
              data.privateKeyJwk,
              { name: "RSASSA-PKCS1-v1_5", hash: "SHA-256" },
              true,
              ["sign"]
            );
            finish({
              privateKey,
              publicKeyJwk: data.publicKeyJwk,
              jkt: data.jkt,
            });
          } catch {
            finish(null);
          }
        };
        getReq.onerror = () => finish(null);
      };
      request.onerror = () => resolve(null);
      // An upgrade blocked by another tab's open handle never fires
      // onsuccess; never leave init() waiting on it.
      request.onblocked = () => resolve(null);
    });
  }

  private async deleteFromIndexedDB(): Promise<void> {
    // Deleting the record inside a short-lived transaction, instead of
    // deleteDatabase(), is what makes logout reliable: deleteDatabase() fires
    // onblocked and never completes while another connection holds the
    // database open, so the key pair silently survived into the next init().
    // A row delete cannot be blocked by other open connections.
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
          resolve();
          return;
        }
        db.onversionchange = () => db.close();
        const tx = db.transaction("keys", "readwrite");
        tx.objectStore("keys").delete("dpop");
        tx.oncomplete = () => {
          db.close();
          resolve();
        };
        tx.onerror = () => {
          db.close();
          resolve();
        };
        tx.onabort = () => {
          db.close();
          resolve();
        };
      };
      request.onerror = () => resolve();
      request.onblocked = () => resolve();
    });
  }
}
