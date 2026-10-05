// SPDX-License-Identifier: MIT
import { randomUuid } from "../utils/randomUuid";

/**
 * Structured log emitter for DPoP events. The format mirrors the
 * server-side metric (`event=dpop_proof_verified alg=... result=...`) so
 * client and server lines aggregate in the same dashboard. The cutover
 * runbook (back/docs/DPOP_CUTOVER_RUNBOOK.md) relies on these tags.
 *
 * Pre-merge review #13: the client used to emit `dpop_event=... k=v k=v`
 * (k=v string format), which the server-side emits as a JSON context
 * array. The two diverged for pipelines that expect JSON (Loki /
 * Promtail, Datadog RUM). Now both sides emit a single JSON string per
 * line so the same parser handles both.
 */
function emitDpopEvent(event: string, fields: Record<string, unknown>): void {
  const payload = { event, ...fields };
  console.info(JSON.stringify(payload));
}

/**
 * JKT prefixes already reported for `dpop_legacy_key_retained` across
 * the page lifetime. Pre-merge review #F: the previous implementation
 * kept this set per `DpopService` instance, which only worked if the
 * caller used a singleton. Apps that lazily construct a fresh
 * `DpopService` per route / per request defeat the dedupe and inflate
 * the runbook's "absolute count of retained RSA keys" counter.
 * Module-level makes the dedupe robust regardless of caller strategy.
 */
const reportedLegacyJktPrefixes = new Set<string>();

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

      // LBDS-290 force-rotation telemetry: surface stored RSA keys so the
      // app can decide whether to call `forceRotateLegacyKeys()` on the
      // next logout / session-boundary. A RSA key here is a pre-LBDS-258
      // binding that has not rotated to ES256 yet. Pre-merge review #14:
      // deduplicate by jkt_prefix using a module-level Set so the dedupe
      // works regardless of caller strategy (singleton or per-route).
      if (stored.publicKeyJwk.kty === "RSA") {
        const jktPrefix = stored.jkt.slice(0, 8);
        if (!reportedLegacyJktPrefixes.has(jktPrefix)) {
          reportedLegacyJktPrefixes.add(jktPrefix);
          emitDpopEvent("dpop_legacy_key_retained", {
            alg: "RS256",
            jkt_prefix: jktPrefix,
          });
        }
      }

      return;
    }

    await this.generateKeyPair();
  }

  async generateKeyPair(): Promise<void> {
    // ES256 (ECDSA over P-256 with SHA-256): JWK ~120B, signature 64B,
    // generation is effectively free on WebCrypto. RS256 is still verified
    // server-side as the legacy path during the gradual migration.
    const keyPair = await crypto.subtle.generateKey(
      {
        name: "ECDSA",
        namedCurve: "P-256",
      },
      true,
      ["sign", "verify"]
    );

    this.privateKey = keyPair.privateKey;

    const exported = await crypto.subtle.exportKey("jwk", keyPair.publicKey);
    this.publicKeyJwk = {
      kty: exported.kty,
      crv: exported.crv,
      x: exported.x,
      y: exported.y,
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
    const kty = this.publicKeyJwk.kty;

    let alg: string;
    let jwkHeader: Record<string, unknown>;
    let signAlgorithm: string | EcdsaParams;

    if (kty === "EC") {
      alg = "ES256";
      jwkHeader = {
        kty: this.publicKeyJwk.kty,
        crv: this.publicKeyJwk.crv,
        x: this.publicKeyJwk.x,
        y: this.publicKeyJwk.y,
      };
      signAlgorithm = { name: "ECDSA", hash: "SHA-256" };
    } else if (kty === "RSA") {
      // Legacy RS256 path: pre-migration keys already on disk continue to
      // sign until the next clearKeys/initialize cycle rotates them.
      alg = "RS256";
      jwkHeader = {
        kty: this.publicKeyJwk.kty,
        n: this.publicKeyJwk.n,
        e: this.publicKeyJwk.e,
      };
      signAlgorithm = "RSASSA-PKCS1-v1_5";
    } else {
      throw new Error(`Unsupported DPoP key type: ${kty ?? "unknown"}`);
    }

    const header: Record<string, unknown> = {
      typ: "dpop+jwt",
      alg,
      jwk: jwkHeader,
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
      signAlgorithm,
      this.privateKey,
      encoder.encode(signInput)
    );

    // Browsers (Web Crypto spec) emit ECDSA signatures as ASN.1 DER; Node.js
    // emits IEEEP1363 raw R || S. JWT/DPoP requires the JOSE raw encoding,
    // so we normalize — only DER signatures need the conversion.
    const signatureBytes =
      kty === "EC"
        ? this.normalizeEcdsaSignature(new Uint8Array(signatureBuffer))
        : new Uint8Array(signatureBuffer);

    const encodedSignature = this.base64UrlEncode(signatureBytes);

    return `${signInput}.${encodedSignature}`;
  }

  async clearKeys(): Promise<void> {
    this.privateKey = null;
    this.publicKeyJwk = null;
    this.jkt = null;
    await this.deleteFromIndexedDB();
  }

  /**
   * Whether the currently-loaded key pair is a legacy RSA (RS256) one
   * carried over from a pre-LBDS-258 session. Use this to decide whether
   * the next logout (or a manual trigger) should call
   * `forceRotateLegacyKeys()` to retire the key early.
   *
   * Returns `false` for fresh sessions, for ES256 (post-migration) keys,
   * and for sessions that have not called `initialize()` yet.
   */
  hasLegacyRsaKey(): boolean {
    return this.publicKeyJwk?.kty === "RSA";
  }

  /**
   * LBDS-290 force-rotation helper. If the loaded key pair is RSA
   * (RS256, pre-LBDS-258), wipe it from IndexedDB and clear the
   * in-memory copy. The next `initialize()` (or a fresh login) will
   * generate a fresh ES256 pair. Returns `true` when a key was rotated,
   * `false` otherwise (no-op for ES256 or empty sessions).
   *
   * Safe to call at any point: the public API (`createDpopProof`)
   * throws if no key is loaded, so callers should re-initialize or
   * trigger a fresh login after invoking this.
   */
  async forceRotateLegacyKeys(): Promise<boolean> {
    if (!this.hasLegacyRsaKey()) {
      return false;
    }

    // Pre-merge review #E: `this.jkt` MUST be set when hasLegacyRsaKey()
    // returns true (initialize() sets both in lock-step). The non-null
    // assertion documents that contract and keeps the emitted JSON
    // schema stable for the runbook's dashboard queries.
    const rotatedJktPrefix = this.jkt!.slice(0, 8);

    await this.clearKeys();

    emitDpopEvent("dpop_legacy_key_rotated", {
      previous_alg: "RS256",
      previous_jkt_prefix: rotatedJktPrefix,
    });

    return true;
  }

  private async computeJwkThumbprint(jwk: JsonWebKey): Promise<string> {
    // RFC 7638 §3 — fields MUST be in the order specified, no whitespace.
    // EC: { crv, kty, x, y }; RSA: { e, kty, n }. JSON.stringify preserves
    // insertion order, so a literal with the canonical field order is the
    // contract here.
    let canonical: string;
    if (jwk.kty === "EC") {
      canonical = JSON.stringify({
        crv: jwk.crv,
        kty: jwk.kty,
        x: jwk.x,
        y: jwk.y,
      });
    } else {
      canonical = JSON.stringify({
        e: jwk.e,
        kty: jwk.kty,
        n: jwk.n,
      });
    }

    const encoder = new TextEncoder();
    const hashBuffer = await crypto.subtle.digest(
      "SHA-256",
      encoder.encode(canonical)
    );
    return this.base64UrlEncode(new Uint8Array(hashBuffer));
  }

  /**
   * Return the ECDSA signature in the JOSE raw R || S form (RFC 7518 §3.4).
   * Browsers return ASN.1 DER; Node.js returns IEEEP1363 already.
   *
   * Detection: DER for P-256 is always 70–72 bytes; raw IEEEP1363 is
   * exactly 64 bytes. A 64-byte signature whose first byte happens to be
   * 0x30 (the ASN.1 SEQUENCE tag) is statistically possible (1/256) and
   * would otherwise be misclassified as DER — we explicitly exclude the
   * 64-byte raw length from the DER branch.
   */
  private normalizeEcdsaSignature(sig: Uint8Array): Uint8Array {
    if (sig.length >= 8 && sig[0] === 0x30 && sig.length !== 64) {
      return this.derToJoseEcdsa(sig);
    }
    return sig;
  }

  /**
   * Convert an ASN.1 DER-encoded ECDSA signature to the JOSE raw R || S
   * encoding (RFC 7518 §3.4). Browsers return DER; JWT/DPoP expects the
   * 64-byte concatenation of r and s, each padded to the curve's point length.
   */
  private derToJoseEcdsa(der: Uint8Array): Uint8Array {
    if (der.length < 8 || der[0] !== 0x30) {
      throw new Error("Invalid DER ECDSA signature: missing SEQUENCE tag");
    }

    let offset = 2;
    if (der[1] & 0x80) {
      offset += der[1] & 0x7f;
    }

    if (der[offset] !== 0x02) {
      throw new Error("Invalid DER ECDSA signature: missing INTEGER tag for r");
    }
    offset++;
    let rLen = der[offset++];
    if (rLen & 0x80) {
      const numBytes = rLen & 0x7f;
      rLen = 0;
      for (let i = 0; i < numBytes; i++) {
        rLen = (rLen << 8) | der[offset + i];
      }
      offset += numBytes;
    }
    const rStart = offset;
    if (der[rStart] === 0x00 && rLen > 1) {
      offset++;
      rLen--;
    }
    const r = der.slice(offset, offset + rLen);
    offset += rLen;

    if (der[offset] !== 0x02) {
      throw new Error("Invalid DER ECDSA signature: missing INTEGER tag for s");
    }
    offset++;
    let sLen = der[offset++];
    if (sLen & 0x80) {
      const numBytes = sLen & 0x7f;
      sLen = 0;
      for (let i = 0; i < numBytes; i++) {
        sLen = (sLen << 8) | der[offset + i];
      }
      offset += numBytes;
    }
    const sStart = offset;
    if (der[sStart] === 0x00 && sLen > 1) {
      offset++;
      sLen--;
    }
    const s = der.slice(offset, offset + sLen);
    offset += sLen;

    // P-256 → 32 bytes per coordinate. r || s, big-endian, left-padded.
    const pointLength = 32;
    const out = new Uint8Array(pointLength * 2);
    out.set(r, pointLength - r.length);
    out.set(s, pointLength * 2 - s.length);
    return out;
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
            // Pick the WebCrypto import descriptor from the public JWK kty:
            // a stored RSA record keeps working (legacy rotation window),
            // an EC record imports as ECDSA P-256.
            const privateKey = await importPrivateKey(data);
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

async function importPrivateKey(data: {
  privateKeyJwk: JsonWebKey;
  publicKeyJwk: JsonWebKey;
}): Promise<CryptoKey> {
  if (data.publicKeyJwk.kty === "EC") {
    // Omit namedCurve from the algorithm: WebCrypto's "jwk" import reads
    // the curve from the JWK's `crv` field, so passing it twice is
    // redundant and would reject a future non-P-256 EC key. The server's
    // JwkToPemConverter / DpopVerifier still restrict ES256 proofs to
    // P-256, so a non-P-256 JWK here would round-trip fine on the client
    // but be rejected server-side — defense in depth.
    return crypto.subtle.importKey(
      "jwk",
      data.privateKeyJwk,
      { name: "ECDSA" },
      true,
      ["sign"]
    );
  }

  // Legacy RSA: keep verifying against RS256 until the next clearKeys /
  // initialize cycle rotates the key.
  return crypto.subtle.importKey(
    "jwk",
    data.privateKeyJwk,
    { name: "RSASSA-PKCS1-v1_5", hash: "SHA-256" },
    true,
    ["sign"]
  );
}