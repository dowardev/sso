// SPDX-License-Identifier: MIT

/**
 * Passkey Service — WebAuthn API wrapper for frontend
 *
 * Provides high-level methods for passkey registration and authentication
 * using the browser's native navigator.credentials API.
 */

import { api } from '@/services/api';
import type { User } from '@/types';

// ─── Local JSON types (base64url wire format) ─────────────────────────────────

interface PublicKeyCredentialDescriptorJSON {
  id: string;
  type: 'public-key';
  transports?: string[];
}

interface PublicKeyCredentialCreationOptionsJSON {
  rp: { id?: string; name: string };
  user: { id: string; name: string; displayName: string };
  challenge: string;
  pubKeyCredParams: Array<{ type: 'public-key'; alg: number }>;
  timeout?: number;
  excludeCredentials?: PublicKeyCredentialDescriptorJSON[];
  authenticatorSelection?: {
    authenticatorAttachment?: string;
    userVerification?: string;
    residentKey?: string;
  };
  attestation?: string;
}

interface PublicKeyCredentialRequestOptionsJSON {
  challenge: string;
  timeout?: number;
  rpId?: string;
  allowCredentials?: PublicKeyCredentialDescriptorJSON[];
  userVerification?: string;
}

interface AuthenticationFinishResponse {
  token: string;
  token_type: string;
  expires_in: number;
  user: User;
}

// ─── Helper Functions ──────────────────────────────────────────────────────────

function bufferToBase64URL(params: { buffer: ArrayBuffer }): string {
  const bytes = new Uint8Array(params.buffer);
  let base64 = '';
  for (let i = 0; i < bytes.byteLength; i++) {
    base64 += String.fromCharCode(bytes[i]);
  }
  return btoa(base64).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

function base64URLToBuffer(params: { base64url: string }): ArrayBuffer {
  let base64 = params.base64url.replace(/-/g, '+').replace(/_/g, '/');
  const padding = base64.length % 4;
  if (padding) {
    base64 += '='.repeat(4 - padding);
  }
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}

// ─── Options decoding (JSON → Credentials API) ────────────────────────────────

function decodeCreationOptions(params: {
  optionsJSON: PublicKeyCredentialCreationOptionsJSON;
}): CredentialCreationOptions {
  const { optionsJSON } = params;
  return {
    publicKey: {
      rp: optionsJSON.rp,
      user: {
        ...optionsJSON.user,
        id: base64URLToBuffer({ base64url: optionsJSON.user.id }),
      },
      challenge: base64URLToBuffer({ base64url: optionsJSON.challenge }),
      pubKeyCredParams: optionsJSON.pubKeyCredParams,
      timeout: optionsJSON.timeout,
      excludeCredentials: optionsJSON.excludeCredentials?.map((cred) => ({
        ...cred,
        id: base64URLToBuffer({ base64url: cred.id }),
      })),
      authenticatorSelection: optionsJSON.authenticatorSelection,
      attestation: optionsJSON.attestation,
    } as PublicKeyCredentialCreationOptions,
  };
}

function decodeRequestOptions(params: {
  optionsJSON: PublicKeyCredentialRequestOptionsJSON;
}): CredentialRequestOptions {
  const { optionsJSON } = params;
  return {
    publicKey: {
      challenge: base64URLToBuffer({ base64url: optionsJSON.challenge }),
      timeout: optionsJSON.timeout,
      rpId: optionsJSON.rpId,
      allowCredentials: optionsJSON.allowCredentials?.map((cred) => ({
        ...cred,
        id: base64URLToBuffer({ base64url: cred.id }),
      })),
      userVerification: optionsJSON.userVerification,
    } as PublicKeyCredentialRequestOptions,
  };
}

// ─── Credential encoding (Credentials API → JSON) ─────────────────────────────

function encodeRegistrationResponse(params: {
  credential: PublicKeyCredential;
}): Record<string, unknown> {
  const response = params.credential.response as AuthenticatorAttestationResponse;
  return {
    id: params.credential.id,
    rawId: bufferToBase64URL({ buffer: params.credential.rawId }),
    response: {
      clientDataJSON: bufferToBase64URL({ buffer: response.clientDataJSON }),
      attestationObject: bufferToBase64URL({ buffer: response.attestationObject }),
      transports: response.getTransports?.() ?? [],
    },
    type: params.credential.type,
    authenticatorAttachment: params.credential.authenticatorAttachment,
  };
}

function encodeAuthenticationResponse(params: {
  credential: PublicKeyCredential;
}): Record<string, unknown> {
  const response = params.credential.response as AuthenticatorAssertionResponse;
  return {
    id: params.credential.id,
    rawId: bufferToBase64URL({ buffer: params.credential.rawId }),
    response: {
      clientDataJSON: bufferToBase64URL({ buffer: response.clientDataJSON }),
      authenticatorData: bufferToBase64URL({ buffer: response.authenticatorData }),
      signature: bufferToBase64URL({ buffer: response.signature }),
      userHandle: response.userHandle
        ? bufferToBase64URL({ buffer: response.userHandle })
        : undefined,
    },
    type: params.credential.type,
    authenticatorAttachment: params.credential.authenticatorAttachment,
  };
}

// ─── Passkey Service ───────────────────────────────────────────────────────────

export const passkeyService = {
  isSupported(): boolean {
    return (
      typeof window !== 'undefined' &&
      'credentials' in navigator &&
      typeof navigator.credentials.create === 'function' &&
      typeof navigator.credentials.get === 'function' &&
      'PublicKeyCredential' in window
    );
  },

  async isPlatformAuthenticatorAvailable(): Promise<boolean> {
    if (!this.isSupported()) return false;
    try {
      return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    } catch {
      return false;
    }
  },

  // ─── Registration ────────────────────────────────────────────────────────────

  async registrationStart(params: { userId: number }): Promise<PublicKeyCredentialCreationOptionsJSON> {
    return api.post<PublicKeyCredentialCreationOptionsJSON>({
      endpoint: '/passkey/register/start',
      data: { user_id: params.userId },
    });
  },

  async registrationFinish(params: {
    optionsJSON: PublicKeyCredentialCreationOptionsJSON;
  }): Promise<{ message: string; credential_id: string }> {
    const credential = (await navigator.credentials.create(
      decodeCreationOptions({ optionsJSON: params.optionsJSON }),
    )) as PublicKeyCredential | null;

    if (!credential) {
      throw new Error('No se pudo crear la credencial');
    }

    return api.post<{ message: string; credential_id: string }>({
      endpoint: '/passkey/register/finish',
      data: encodeRegistrationResponse({ credential }),
    });
  },

  // ─── Authentication ──────────────────────────────────────────────────────────

  async authenticationStart(params: {
    user_id?: number;
    login_id?: string;
  }): Promise<PublicKeyCredentialRequestOptionsJSON> {
    return api.post<PublicKeyCredentialRequestOptionsJSON>({
      endpoint: '/passkey/authenticate/start',
      data: params,
    });
  },

  async authenticationFinish(params: {
    optionsJSON: PublicKeyCredentialRequestOptionsJSON;
  }): Promise<AuthenticationFinishResponse> {
    const credential = (await navigator.credentials.get(
      decodeRequestOptions({ optionsJSON: params.optionsJSON }),
    )) as PublicKeyCredential | null;

    if (!credential) {
      throw new Error('Autenticación cancelada o fallida');
    }

    return api.post<AuthenticationFinishResponse>({
      endpoint: '/passkey/authenticate/finish',
      data: encodeAuthenticationResponse({ credential }),
    });
  },

  // ─── Management ──────────────────────────────────────────────────────────────

  async deletePasskey(): Promise<{ message: string }> {
    return api.delete<{ message: string }>({ endpoint: '/passkey' });
  },

  // ─── High-level flows ────────────────────────────────────────────────────────

  async register(params: { userId: number }): Promise<{ message: string; credential_id: string }> {
    const optionsJSON = await this.registrationStart({ userId: params.userId });
    return this.registrationFinish({ optionsJSON });
  },

  async authenticate(params: {
    user_id?: number;
    login_id?: string;
  }): Promise<AuthenticationFinishResponse> {
    const optionsJSON = await this.authenticationStart(params);
    return this.authenticationFinish({ optionsJSON });
  },
};

export default passkeyService;