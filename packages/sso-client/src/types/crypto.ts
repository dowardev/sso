// SPDX-License-Identifier: MIT

/** Encrypted profile blob (AES-GCM): ciphertext || iv || authTag */
export interface EncryptedBlob {
  ciphertext: string; // base64
  iv: string; // base64
  authTag: string; // base64
}

/** Wrapped key material (AES-KW) */
export interface WrappedKey {
  wrapped: string; // base64
  algorithm: string;
}

/** A user's identity profile payload (the data encrypted at rest) */
export interface UserProfile {
  name: string;
  user: string;
  email: string;
  [key: string]: unknown;
}

/** Decrypted profile delivered to OAuth apps on login (via #profile=). */
export interface DecryptedProfile extends UserProfile {
  version?: number;
}

/** One device-persisted PRF-wrapped DEK, keyed by credentialId. */
export interface PrfWrappedDek {
  wrappedDek: string; // base64
  salt: string; // base64
  credentialId: string;
}