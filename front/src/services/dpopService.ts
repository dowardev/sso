// SPDX-License-Identifier: MIT
export default class DpopService {
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
    // ES256 (ECDSA over P-256 with SHA-256): JWK ~120B, signature 64B,
    // generation is effectively free on WebCrypto. RS256 is still verified
    // server-side as the legacy path during the gradual migration.
    const keyPair = await crypto.subtle.generateKey(
      {
        name: 'ECDSA',
        namedCurve: 'P-256',
      },
      true,
      ['sign', 'verify']
    );

    this.privateKey = keyPair.privateKey;

    const exported = await crypto.subtle.exportKey('jwk', keyPair.publicKey);

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

  async createDpopProof(params: {
    htm: string;
    htu: string;
    accessToken?: string;
  }): Promise<string> {
    const { htm, htu, accessToken } = params;
    if (!this.privateKey || !this.publicKeyJwk) {
      throw new Error('DPoP key pair not initialized');
    }

    if (!htm || !htu) {
      throw new Error('htm and htu are required for DPoP proof');
    }

    const iat = Math.floor(Date.now() / 1000);
    const jti = crypto.randomUUID();
    const encoder = new TextEncoder();
    const kty = this.publicKeyJwk.kty;

    let alg: string;
    let jwkHeader: Record<string, unknown>;
    let signAlgorithm: string | EcdsaParams;

    if (kty === 'EC') {
      alg = 'ES256';
      jwkHeader = {
        kty: this.publicKeyJwk.kty,
        crv: this.publicKeyJwk.crv,
        x: this.publicKeyJwk.x,
        y: this.publicKeyJwk.y,
      };
      signAlgorithm = { name: 'ECDSA', hash: 'SHA-256' };
    } else if (kty === 'RSA') {
      // Legacy RS256 path: pre-migration keys already on disk continue to
      // sign until the next clearKeys/initialize cycle rotates them.
      alg = 'RS256';
      jwkHeader = {
        kty: this.publicKeyJwk.kty,
        n: this.publicKeyJwk.n,
        e: this.publicKeyJwk.e,
      };
      signAlgorithm = 'RSASSA-PKCS1-v1_5';
    } else {
      throw new Error(`Unsupported DPoP key type: ${kty ?? 'unknown'}`);
    }

    const header: Record<string, unknown> = {
      typ: 'dpop+jwt',
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
        'SHA-256',
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
      kty === 'EC'
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

  private async computeJwkThumbprint(jwk: JsonWebKey): Promise<string> {
    // RFC 7638 §3 — fields MUST be in the order specified, no whitespace.
    // EC: { crv, kty, x, y }; RSA: { e, kty, n }. JSON.stringify preserves
    // insertion order, so a literal with the canonical field order is the
    // contract here.
    let canonical: string;
    if (jwk.kty === 'EC') {
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
      'SHA-256',
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
      throw new Error('Invalid DER ECDSA signature: missing SEQUENCE tag');
    }

    let offset = 2;
    if (der[1] & 0x80) {
      offset += der[1] & 0x7f;
    }

    if (der[offset] !== 0x02) {
      throw new Error('Invalid DER ECDSA signature: missing INTEGER tag for r');
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
      throw new Error('Invalid DER ECDSA signature: missing INTEGER tag for s');
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
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary)
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=+$/, '');
  }

  private async saveToIndexedDB(): Promise<void> {
    if (!this.privateKey || !this.publicKeyJwk || !this.jkt) return;

    const jwk = await crypto.subtle.exportKey('jwk', this.privateKey);

    const data = {
      privateKeyJwk: jwk,
      publicKeyJwk: this.publicKeyJwk,
      jkt: this.jkt,
    };

    return new Promise((resolve, reject) => {
      const request = indexedDB.open('dpop_keys', 1);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains('keys')) {
          db.createObjectStore('keys');
        }
      };
      request.onsuccess = () => {
        const db = request.result;
        const tx = db.transaction('keys', 'readwrite');
        const store = tx.objectStore('keys');
        store.put(data, 'dpop');
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
      };
      request.onerror = () => reject(request.error);
    });
  }

  private async loadFromIndexedDB(): Promise<{
    privateKey: CryptoKey;
    publicKeyJwk: JsonWebKey;
    jkt: string;
  } | null> {
    return new Promise((resolve, _reject) => {
      const request = indexedDB.open('dpop_keys', 1);
      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains('keys')) {
          db.createObjectStore('keys');
        }
      };
      request.onsuccess = async () => {
        const db = request.result;
        if (!db.objectStoreNames.contains('keys')) {
          resolve(null);
          return;
        }
        const tx = db.transaction('keys', 'readonly');
        const store = tx.objectStore('keys');
        const getReq = store.get('dpop');
        getReq.onsuccess = async () => {
          const data = getReq.result;
          if (!data) {
            resolve(null);
            return;
          }

          try {
            const privateKey = await this.importStoredPrivateKey(data);
            resolve({
              privateKey,
              publicKeyJwk: data.publicKeyJwk,
              jkt: data.jkt,
            });
          } catch {
            resolve(null);
          }
        };
        getReq.onerror = () => resolve(null);
      };
      request.onerror = () => resolve(null);
    });
  }

  private async importStoredPrivateKey(data: {
    privateKeyJwk: JsonWebKey;
    publicKeyJwk: JsonWebKey;
  }): Promise<CryptoKey> {
    // Pick the WebCrypto import descriptor from the public JWK kty:
    // a stored RSA record keeps working (legacy rotation window), an EC
    // record imports as ECDSA (the curve is read from the JWK's `crv`
    // — passing namedCurve twice would lock the import to P-256 and
    // reject any other curve the JWK might carry).
    if (data.publicKeyJwk.kty === 'EC') {
      return crypto.subtle.importKey(
        'jwk',
        data.privateKeyJwk,
        { name: 'ECDSA' },
        true,
        ['sign']
      );
    }

    return crypto.subtle.importKey(
      'jwk',
      data.privateKeyJwk,
      { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
      true,
      ['sign']
    );
  }

  private async deleteFromIndexedDB(): Promise<void> {
    return new Promise((resolve) => {
      const request = indexedDB.deleteDatabase('dpop_keys');
      request.onsuccess = () => resolve();
      request.onerror = () => resolve();
    });
  }
}

export const dpopService = new DpopService();