// SPDX-License-Identifier: MIT
export { SSOClient } from "./client/SSOClient";
export { HttpClient } from "./http/HttpClient";
export { createAuthGuard } from "./router/createAuthGuard";
export type {
  AuthGuardOptions,
  RouteLike,
  NavigationGuardNext,
  SSOClientLike,
} from "./router/createAuthGuard";
export * from "./types";

export {
  getCryptoWorkerClient,
  resetCryptoWorkerClient,
} from "./crypto/workerClient";
export type { CryptoWorkerClient } from "./crypto/workerClient";
export {
  PBKDF2_ITERATIONS,
  KEY_LENGTH_BITS,
  PRF_DEK_INFO,
  bytesToBase64,
  base64ToBytes,
  bytesToBase64Url,
  base64UrlToBytes,
  concatBytes,
  pbkdf2Bytes,
  hkdfBytes,
  importAesKwKey,
  wrapKeyWith,
  unwrapKeyWith,
  aesGcmEncrypt,
  aesGcmDecrypt,
  generateDekKey,
  hmacSign,
  randomBip39Mnemonic,
  generateIv,
} from "./crypto/core";

// ─── Zero-Knowledge Encryption (v2) ─────────────────────────────────────────
export { ZkeClient } from "./crypto/ZkeClient";
export type { ZkeClientOptions, ZkeHttp, DecryptResult } from "./crypto/ZkeClient";

export {
  detectPrfSupport,
  derivePrfKey,
  createPrfCredential,
  getPrfOutput,
  persistPrfWrappedDek,
  prfStore,
} from "./crypto/prfService";
export type { PrfSupport, PrfCeremonyResult } from "./crypto/prfService";

export {
  shouldRefresh,
  syncProfileSilently,
} from "./crypto/profileFreshness";
export type { ProfileBlobs } from "./crypto/profileFreshness";

export {
  encryptProfile,
  decryptProfile,
  setCachedProfile,
  getCachedProfile,
  getCachedProfileVersion,
  buildProfileRedirectUrl,
  encodeProfileFragment,
  decodeProfileFragment,
} from "./crypto/profileCipher";

export * from "./types/crypto";