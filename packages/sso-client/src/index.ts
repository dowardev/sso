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