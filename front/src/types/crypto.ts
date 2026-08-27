// SPDX-License-Identifier: MIT
import type { User } from './index';
import type {
  PublicKeyCredentialCreationOptionsJSON,
  PublicKeyCredentialRequestOptionsJSON,
  RegistrationResponseJSON,
  AuthenticationResponseJSON,
} from '@simplewebauthn/types';

/**
 * Crypto-related types for Zero-Knowledge Encryption (Phase 1: Crypto Core)
 */

export type EncryptionLevel = 'basic' | 'advanced';

/**
 * Encrypted blob stored in database / transferred over API
 * Contains ciphertext + IV + auth tag (for AES-GCM) or wrapped key material
 */
export interface EncryptedBlob {
  /** Base64-encoded ciphertext */
  ciphertext: string;
  /** Base64-encoded initialization vector (12 bytes for AES-GCM) */
  iv: string;
  /** Base64-encoded authentication tag (16 bytes for AES-GCM) */
  authTag: string;
}

/**
 * Wrapped DEK (Data Encryption Key) using AES-KW
 * Key wrapping doesn't use IV/authTag, just wrapped key material
 */
export interface WrappedKey {
  /** Base64-encoded wrapped key */
  wrapped: string;
  /** Algorithm identifier for unwrapping (e.g., 'AES-KW') */
  algorithm: string;
}

/**
 * User profile data that gets encrypted with DEK
 */
export interface UserProfile {
  name: string;
  email: string;
  user: string;
}

/**
 * Crypto keys held in memory (Web Worker) - NEVER persisted
 */
export interface CryptoKeys {
  /** Key_A derived from password + salt_a via PBKDF2 (1M iterations) */
  keyA: CryptoKey;
  /** DEK - random 256-bit key for profile encryption */
  dek: CryptoKey;
  /** Key_R derived from seed phrase + salt_r (future phase) */
  keyR?: CryptoKey;
}

/**
 * Key derivation parameters sent to/from backend
 */
export interface KeyDerivationParams {
  /** PBKDF2 iterations (≥1,000,000) */
  iterations: number;
  /** Base64-encoded salt */
  salt: string;
  /** Key length in bits (256) */
  keyLength: number;
  /** Hash algorithm (SHA-256) */
  hash: 'SHA-256';
}

/**
 * Registration payload for advanced encryption level
 */
export interface AdvancedRegistrationPayload {
  login_id: string;
  auth_hash: string;           // Argon2id(auth_proof) where auth_proof = HMAC(Key_A, "auth")
  salt_a: string;              // Base64-encoded salt for Key_A derivation
  salt_r: string;              // Base64-encoded salt for Key_R derivation
  wrapped_dek_a: WrappedKey;   // DEK wrapped with Key_A
  wrapped_dek_r: WrappedKey;   // DEK wrapped with Key_R
  encrypted_profile: EncryptedBlob; // Profile encrypted with DEK
  encrypted_seed_verification: string; // Base64 HMAC(Key_R, "verify")
  encryption_level: 'advanced';
}

/**
 * Login response for advanced users (blobs needed for client-side decryption)
 */
export interface AdvancedLoginResponse {
  encryption_level: 'advanced';
  profile_version: number;
  salt_a: string;
  salt_r: string;
  wrapped_dek_a: WrappedKey;
  encrypted_profile: EncryptedBlob;
}

/**
 * Login response for basic users (unchanged from current)
 */
export interface BasicLoginResponse {
  encryption_level: 'basic';
  user: User;
  access_token: string;
  refresh_token: string;
}

/**
 * Union type for login response
 */
export type LoginResponse = BasicLoginResponse | AdvancedLoginResponse;

/**
 * Auth proof computation input
 */
export interface AuthProofInput {
  keyA: CryptoKey;
  context: string; // "auth"
}

/**
 * Recovery - Step 1: Verify seed phrase request
 */
export interface RecoveryVerifyRequest {
  login_id: string;
  seed_verification: string; // HMAC(Key_R, "verify")
}

/**
 * Recovery - Step 1: Response with blobs needed for recovery
 */
export interface RecoveryVerifyResponse {
  profile_version: number;
  salt_r: string;
  wrapped_dek_r: WrappedKey;
  encrypted_profile: EncryptedBlob;
}

/**
 * Recovery - Step 2: Complete recovery request
 */
export interface RecoveryCompleteRequest {
  login_id: string;
  auth_proof: string;          // HMAC(new_Key_A, "auth")
  salt_a: string;              // New salt for Key_A
  wrapped_dek_a: WrappedKey;   // DEK wrapped with new Key_A
  wrapped_dek_r: WrappedKey;   // DEK wrapped with Key_R (unchanged)
  encrypted_profile: EncryptedBlob; // Re-encrypted profile
}

/**
 * Change password advanced request (same DEK, new Key_A)
 */
export interface ChangePasswordAdvancedRequest {
  login_id: string;
  auth_proof: string;        // HMAC(new_Key_A, "auth")
  salt_a: string;            // New salt for Key_A
  wrapped_dek_a: WrappedKey; // DEK wrapped with new Key_A
}

/**
 * Crypto operation messages for Web Worker communication
 */
export type CryptoWorkerMessage =
  | { type: 'derive-key-a'; payload: { password: string; salt: string; iterations: number } }
  | { type: 'generate-dek' }
  | { type: 'wrap-dek'; payload: { dek: CryptoKey; wrappingKey: CryptoKey } }
  | { type: 'unwrap-dek'; payload: { wrapped: WrappedKey; unwrappingKey: CryptoKey } }
  | { type: 'encrypt-profile'; payload: { dek: CryptoKey; profile: UserProfile } }
  | { type: 'decrypt-profile'; payload: { dek: CryptoKey; blob: EncryptedBlob } }
  | { type: 'compute-auth-proof'; payload: AuthProofInput }
  | { type: 'generate-seed-phrase' }
  | { type: 'derive-key-r'; payload: { seedPhrase: string; salt: string; iterations: number } };

/**
 * Crypto Worker responses
 */
export type CryptoWorkerResponse =
  | { type: 'key-derived'; payload: { key: CryptoKey } }
  | { type: 'dek-generated'; payload: { dek: CryptoKey } }
  | { type: 'dek-wrapped'; payload: WrappedKey }
  | { type: 'dek-unwrapped'; payload: { dek: CryptoKey } }
  | { type: 'profile-encrypted'; payload: EncryptedBlob }
  | { type: 'profile-decrypted'; payload: UserProfile }
  | { type: 'auth-proof-computed'; payload: { proof: string } }
  | { type: 'seed-phrase-generated'; payload: { phrase: string } }
  | { type: 'error'; payload: { message: string; code: string } };

/**
 * High-level crypto service API (used by frontend controllers)
 */
export interface CryptoServiceAPI {
  /** Derive Key_A from password + salt */
  deriveKeyA(params: { password: string; salt: string; iterations?: number }): Promise<CryptoKey>;
  /** Generate random DEK */
  generateDek(): Promise<void>;
  /** Wrap DEK with Key_A or Key_R */
  wrapDek(params: { wrappingKey: CryptoKey }): Promise<WrappedKey>;
  /** Unwrap DEK with Key_A or Key_R */
  unwrapDek(params: { wrapped: WrappedKey; unwrappingKey: CryptoKey }): Promise<void>;
  /** Encrypt user profile with DEK */
  encryptProfile(params: { profile: UserProfile }): Promise<EncryptedBlob>;
  /** Decrypt user profile with DEK */
  decryptProfile(params: { blob: EncryptedBlob }): Promise<UserProfile>;
  /** Compute auth proof = HMAC(Key_A, "auth") */
  computeAuthProof(params: { keyA: CryptoKey }): Promise<string>;
  /** Generate BIP39 seed phrase (24 words) */
  generateSeedPhrase(): Promise<string>;
  /** Derive Key_R from seed phrase + salt */
  deriveKeyR(params: { seedPhrase: string; salt: string; iterations?: number }): Promise<CryptoKey>;
  /** Clear all keys from memory (tab close, logout) */
  clearKeys(): void;

  // ─── Additional helper methods ────────────────────────────────────────

  /** Get current DEK from memory */
  getDek(): CryptoKey | null;
  /** Get current Key_A from memory */
  getKeyA(): CryptoKey | null;
  /** Check if user has advanced encryption */
  isAdvanced(): boolean;
  /** Get login ID for advanced user */
  getLoginId(): string | null;
  /** Initialize advanced user state after successful login/registration */
  initializeAdvancedState(params: { loginId: string }): void;
  /** Terminate worker (call on app shutdown if needed) */
  terminate(): void;

  // ─── HMAC helpers (shared by auth proof and seed verification) ────────────

  /** Compute an HMAC proof with a wrapping key (context: "auth" | "verify") */
  computeHmacProof(params: { key: CryptoKey; context: string }): Promise<string>;

  // ─── Profile delivery to OAuth apps (tab-scoped cache) ────────────────────

  /** Decrypted profile cached from the last successful decryptProfile call */
  getCachedProfile(): UserProfile | null;
  /** Profile version captured alongside the cached profile (staleness check) */
  getCachedProfileVersion(): number | null;
  /** Store profile + its server-side version together after a fetch/decrypt */
  setCachedProfile(params: { profile: UserProfile; version: number }): void;
}

/**
 * Advanced user state stored in frontend auth store (memory only)
 */
export interface AdvancedAuthState {
  /** DEK in memory - tab scoped, cleared on close */
  dek: CryptoKey | null;
  /** Key_A in memory - tab scoped */
  keyA: CryptoKey | null;
  /** Whether user has advanced encryption */
  isAdvanced: boolean;
  /** Login ID for advanced users */
  loginId: string | null;
}

// ─── Passkey / WebAuthn Types ────────────────────────────────────────────────

/**
 * Passkey registration options from server
 */
export interface PasskeyRegistrationOptions {
  options: PublicKeyCredentialCreationOptionsJSON;
}

/**
 * Passkey registration finish request
 */
export interface PasskeyRegistrationFinishRequest {
  credential: RegistrationResponseJSON;
}

/**
 * Passkey registration finish response
 */
export interface PasskeyRegistrationFinishResponse {
  message: string;
  credential_id: string;
}

/**
 * Passkey authentication options from server
 */
export interface PasskeyAuthenticationOptions {
  options: PublicKeyCredentialRequestOptionsJSON;
}

/**
 * Passkey authentication finish request
 */
export interface PasskeyAuthenticationFinishRequest {
  credential: AuthenticationResponseJSON;
}

/**
 * Passkey authentication finish response (includes JWT token)
 */
export interface PasskeyAuthenticationFinishResponse {
  token: string;
  token_type: string;
  expires_in: number;
  user: User;
}

/**
 * Passkey delete response
 */
export interface PasskeyDeleteResponse {
  message: string;
}