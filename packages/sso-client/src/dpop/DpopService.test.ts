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

async function writeStoredDpopRecord(data: {
  privateKeyJwk: JsonWebKey;
  publicKeyJwk: JsonWebKey;
  jkt: string;
}): Promise<void> {
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
      tx.objectStore("keys").put(data, "dpop");
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

// Decodes a DPoP proof so the assertions can read the protected header.
function decodeDpopJwt(token: string): {
  header: Record<string, unknown>;
  payload: Record<string, unknown>;
} {
  const [encodedHeader, encodedPayload] = token.split(".");
  const decoder = (s: string) =>
    JSON.parse(
      atob(s.replace(/-/g, "+").replace(/_/g, "/").replace(/=+$/, ""))
    );
  return {
    header: decoder(encodedHeader),
    payload: decoder(encodedPayload),
  };
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

describe("DpopService ECDSA P-256 / ES256 generation", () => {
  beforeEach(() => {
    vi.stubGlobal("indexedDB", new IDBFactory());
  });

  it("generates an EC P-256 public JWK with the expected shape", async () => {
    const service = new DpopService();
    await service.generateKeyPair();

    const jwk = service.getPublicKeyJwk();
    expect(jwk).not.toBeNull();
    expect(jwk?.kty).toBe("EC");
    expect(jwk?.crv).toBe("P-256");
    expect(typeof jwk?.x).toBe("string");
    expect(typeof jwk?.y).toBe("string");
    // 32 bytes → 43 base64url chars (no padding). x/y are big-endian, unsigned,
    // so a leading 0x00 is never present either.
    expect(jwk?.x?.length).toBe(43);
    expect(jwk?.y?.length).toBe(43);
    // RSA-only fields must not leak into an EC JWK.
    expect(jwk?.n).toBeUndefined();
    expect(jwk?.e).toBeUndefined();
  });

  it("computes the JWK thumbprint per RFC 7638 §3.2 (EC fields, exact order)", async () => {
    const service = new DpopService();
    await service.generateKeyPair();

    const jwk = service.getPublicKeyJwk();
    const jkt = service.getJwkThumbprint();
    expect(jkt).toBeTruthy();

    // Recompute the thumbprint independently from the same fields in the
    // canonical RFC 7638 order and assert byte-for-byte equality.
    const canonical = JSON.stringify({
      crv: jwk?.crv,
      kty: jwk?.kty,
      x: jwk?.x,
      y: jwk?.y,
    });
    const hash = await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(canonical)
    );
    const expected = btoa(String.fromCharCode(...new Uint8Array(hash)))
      .replace(/\+/g, "-")
      .replace(/\//g, "_")
      .replace(/=+$/, "");
    expect(jkt).toBe(expected);
  });

  it("creates an ES256 DPoP proof with a 64-byte R||S signature and EC header JWK", async () => {
    const service = new DpopService();
    await service.generateKeyPair();

    const proof = await service.createDpopProof("POST", "https://example.test/token");
    const { header } = decodeDpopJwt(proof);

    expect(header.typ).toBe("dpop+jwt");
    expect(header.alg).toBe("ES256");
    expect(header.jwk).toEqual({
      kty: "EC",
      crv: "P-256",
      x: service.getPublicKeyJwk()?.x,
      y: service.getPublicKeyJwk()?.y,
    });

    const signaturePart = proof.split(".")[2];
    const signatureBytes = Uint8Array.from(
      atob(signaturePart.replace(/-/g, "+").replace(/_/g, "/")),
      (c) => c.charCodeAt(0)
    );
    // ES256 = raw R || S, 32 bytes each → 64 bytes total. WebCrypto returns
    // ASN.1 DER (~70 bytes); the service is responsible for the conversion.
    expect(signatureBytes.length).toBe(64);
  });

  it("produces a DPoP proof whose signature the matching public key verifies", async () => {
    const service = new DpopService();
    await service.generateKeyPair();

    const publicKey = await crypto.subtle.importKey(
      "jwk",
      service.getPublicKeyJwk() as JsonWebKey,
      { name: "ECDSA", namedCurve: "P-256" },
      false,
      ["verify"]
    );

    const proof = await service.createDpopProof("POST", "https://example.test/token");
    const [encodedHeader, encodedPayload, encodedSignature] = proof.split(".");
    const signInput = `${encodedHeader}.${encodedPayload}`;
    const signatureBytes = Uint8Array.from(
      atob(encodedSignature.replace(/-/g, "+").replace(/_/g, "/")),
      (c) => c.charCodeAt(0)
    );

    // The proof carries raw R || S (the JOSE form). Browsers' WebCrypto
    // verify accepts the raw form too when you give it 64 bytes for P-256.
    const ok = await crypto.subtle.verify(
      { name: "ECDSA", hash: "SHA-256" },
      publicKey,
      signatureBytes,
      new TextEncoder().encode(signInput)
    );
    expect(ok).toBe(true);
  });

  it("generates an EC P-256 key on first ever init and on post-clearKeys init", async () => {
    // No stored record → generateKeyPair() runs and must emit EC P-256.
    const fresh = new DpopService();
    await fresh.initialize();
    expect(fresh.getPublicKeyJwk()?.kty).toBe("EC");
    expect(fresh.getPublicKeyJwk()?.crv).toBe("P-256");

    // clearKeys wipes the record; the next initialize() must rotate to a
    // fresh EC key, never silently resurrect the previous one.
    await fresh.clearKeys();
    await fresh.initialize();
    expect(fresh.getPublicKeyJwk()?.kty).toBe("EC");
    expect(fresh.getPublicKeyJwk()?.crv).toBe("P-256");
  });

  it("treats a 64-byte signature starting with 0x30 as raw IEEEP1363, not DER (LBDS-258 review C-1)", () => {
    // C-1: the original heuristic `sig[0] === 0x30` misclassified a
    // 64-byte raw signature when R happened to start with 0x30 (the ASN.1
    // SEQUENCE tag), happening with probability 1/256. The fix excludes
    // the 64-byte raw length from the DER branch.
    const service = new DpopService();
    const sig = new Uint8Array(64);
    sig[0] = 0x30;
    for (let i = 1; i < 64; i++) {
      sig[i] = (i * 7) & 0xff;
    }

    // Bracket access to the private method is the standard way to unit-test
    // a private method without changing its visibility.
    const normalized = (
      service as unknown as {
        normalizeEcdsaSignature: (s: Uint8Array) => Uint8Array;
      }
    ).normalizeEcdsaSignature(sig);

    expect(normalized).toBe(sig);
    expect(normalized.length).toBe(64);
    expect(normalized[0]).toBe(0x30);
  });

  it("still runs DER conversion for a real 70-byte DER signature (LBDS-258 review C-1)", () => {
    // C-1 sibling: the heuristic must still convert genuine DER input. A
    // minimal valid DER for P-256 is ~70 bytes and starts with 0x30.
    const service = new DpopService();
    const sig = new Uint8Array(70);
    sig[0] = 0x30; // SEQUENCE
    sig[1] = 0x46; // length (70)
    sig[2] = 0x02; // INTEGER (r)
    sig[3] = 0x20; // r length (32)
    // r[0..31] left as zero — exercise only that the path runs, not the math
    sig[36] = 0x02; // INTEGER (s)
    sig[37] = 0x20; // s length (32)

    const normalized = (
      service as unknown as {
        normalizeEcdsaSignature: (s: Uint8Array) => Uint8Array;
      }
    ).normalizeEcdsaSignature(sig);

    // The conversion produces 64 bytes (R || S, each padded to 32 bytes).
    expect(normalized.length).toBe(64);
  });

  it("keeps loading a legacy RSA key without throwing (gradual rotation window)", async () => {
    // Documents the deliberate legacy behaviour: a stored RSA record must
    // continue to sign proofs so the migration does not strand users with a
    // binding tied to a key they cannot regenerate without logout.
    const rsaKeyPair = await crypto.subtle.generateKey(
      {
        name: "RSASSA-PKCS1-v1_5",
        modulusLength: 2048,
        publicExponent: new Uint8Array([1, 0, 1]),
        hash: "SHA-256",
      },
      true,
      ["sign", "verify"]
    );
    const privateJwk = await crypto.subtle.exportKey("jwk", rsaKeyPair.privateKey);
    const publicJwk = await crypto.subtle.exportKey("jwk", rsaKeyPair.publicKey);
    const legacyPublicJwk = {
      kty: "RSA",
      n: publicJwk.n,
      e: publicJwk.e,
    };
    const canonical = JSON.stringify({
      e: legacyPublicJwk.e,
      kty: legacyPublicJwk.kty,
      n: legacyPublicJwk.n,
    });
    const hash = await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(canonical)
    );
    const legacyJkt = btoa(String.fromCharCode(...new Uint8Array(hash)))
      .replace(/\+/g, "-")
      .replace(/\//g, "_")
      .replace(/=+$/, "");
    await writeStoredDpopRecord({
      privateKeyJwk: privateJwk,
      publicKeyJwk: legacyPublicJwk,
      jkt: legacyJkt,
    });

    const service = new DpopService();
    await service.initialize();

    expect(service.getPublicKeyJwk()?.kty).toBe("RSA");

    const proof = await service.createDpopProof("POST", "https://example.test/token");
    const { header } = decodeDpopJwt(proof);
    expect(header.alg).toBe("RS256");
  });
});