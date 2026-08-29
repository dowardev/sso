// SPDX-License-Identifier: MIT
import type { UserProfile } from "../types/crypto";

interface JwksCacheEntry {
  keys: Jwk[];
  expiresAt: number;
}

interface Jwk {
  kid?: string;
  kty?: string;
  n?: string;
  e?: string;
  alg?: string;
  use?: string;
}

interface JwsHeader {
  alg?: string;
  kid?: string;
  [key: string]: unknown;
}

interface ProfileEnvelope {
  profile: UserProfile;
  token: string;
}

const JWKS_TTL_MS = 5 * 60 * 1000;
const jwksCache = new Map<string, JwksCacheEntry>();

/** Decode a base64url string into raw bytes. */
export function b64urlToBytes(input: string): Uint8Array {
  const b64 = input.replace(/-/g, "+").replace(/_/g, "/");
  const pad = b64.length % 4 === 0 ? "" : "=".repeat(4 - (b64.length % 4));
  const binary = atob(b64 + pad);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes;
}

function b64urlDecodeJson<T>(input: string): T {
  return JSON.parse(new TextDecoder().decode(b64urlToBytes(input))) as T;
}

function asciiBytes(input: string): Uint8Array {
  const bytes = new Uint8Array(input.length);
  for (let i = 0; i < input.length; i++) {
    bytes[i] = input.charCodeAt(i);
  }
  return bytes;
}

async function fetchJwks(ssoUrl: string): Promise<Jwk[]> {
  const url = `${ssoUrl.replace(/\/$/, "")}/.well-known/jwks`;
  const cached = jwksCache.get(url);
  if (cached && cached.expiresAt > Date.now()) {
    return cached.keys;
  }

  const res = await fetch(url);
  if (!res.ok) {
    throw new Error(
      `verifyProfileFragment: JWKS fetch failed for ${url} (status ${res.status})`
    );
  }

  const body = (await res.json()) as { keys?: JsonWebKey[] };
  if (!body.keys || !Array.isArray(body.keys)) {
    throw new Error("verifyProfileFragment: JWKS response missing keys array");
  }

  const keys = body.keys as Jwk[];
  jwksCache.set(url, { keys, expiresAt: Date.now() + JWKS_TTL_MS });
  return keys;
}

/**
 * Verify a compact RS256 JWS against the SSO public key published at
 * `${ssoUrl}/.well-known/jwks`. Returns the decoded claims. Throws on any
 * malformation / missing kid / unknown key / bad signature.
 */
async function verifyJws(params: {
  jws: string;
  ssoUrl: string;
}): Promise<Record<string, unknown>> {
  const parts = params.jws.split(".");
  if (parts.length !== 3 || parts.some((p) => p.length === 0)) {
    throw new Error("verifyProfileFragment: malformed JWS");
  }
  const [headerB64, payloadB64, sigB64] = parts;

  const header = b64urlDecodeJson<JwsHeader>(headerB64);
  if (header.alg !== "RS256") {
    throw new Error(
      `verifyProfileFragment: unsupported JWS alg "${header.alg ?? "undefined"}"`
    );
  }
  if (!header.kid) {
    throw new Error("verifyProfileFragment: JWS header missing kid");
  }

  const jwks = await fetchJwks(params.ssoUrl);
  const jwk = jwks.find((k) => k.kid === header.kid);
  if (!jwk || jwk.kty !== "RSA" || !jwk.n || !jwk.e) {
    throw new Error(
      `verifyProfileFragment: no matching RSA public key for kid "${header.kid}"`
    );
  }

  const key = await crypto.subtle.importKey(
    "jwk",
    { kty: "RSA", n: jwk.n, e: jwk.e, alg: "RS256" },
    { name: "RSASSA-PKCS1-v1_5", hash: "SHA-256" },
    false,
    ["verify"]
  );

  const signature = b64urlToBytes(sigB64);
  const signingInput = asciiBytes(`${headerB64}.${payloadB64}`);

  const valid = await crypto.subtle.verify(
    "RSASSA-PKCS1-v1_5",
    key,
    signature as BufferSource,
    signingInput as BufferSource
  );
  if (!valid) {
    throw new Error("verifyProfileFragment: signature verification failed");
  }

  return b64urlDecodeJson<Record<string, unknown>>(payloadB64);
}

/**
 * Verify the `#profile=` URL fragment delivered to an OAuth app after an
 * advanced (zero-knowledge) login.
 *
 * The fragment is a standard-base64 envelope `{ profile, token }` where `token`
 * is an RS256 JWS signed by the SSO backend attesting `(sub = user id,
 * profile_version, iat, exp)`. The signature is verified against the SSO JWKS,
 * and the token must bind to the authenticated `expectedUserId` and be unexpired.
 * Any miss / tamper / mismatch throws, so the caller keeps identity null.
 */
export async function verifyProfileFragment(params: {
  fragment: string;
  ssoUrl: string;
  expectedUserId: string;
}): Promise<UserProfile> {
  let envelope: ProfileEnvelope;
  try {
    envelope = JSON.parse(atob(params.fragment)) as ProfileEnvelope;
  } catch {
    throw new Error("verifyProfileFragment: fragment is not a valid profile envelope");
  }
  if (!envelope?.profile || typeof envelope.token !== "string") {
    throw new Error("verifyProfileFragment: envelope missing profile or token");
  }

  const claims = await verifyJws({ jws: envelope.token, ssoUrl: params.ssoUrl });

  const sub = String(claims["sub"] ?? "");
  if (sub !== params.expectedUserId) {
    throw new Error("verifyProfileFragment: token subject does not match authenticated user");
  }

  const exp = typeof claims["exp"] === "number" ? (claims["exp"] as number) : 0;
  if (exp <= Math.floor(Date.now() / 1000)) {
    throw new Error("verifyProfileFragment: profile token expired");
  }

  return envelope.profile;
}
