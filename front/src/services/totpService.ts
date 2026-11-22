// SPDX-License-Identifier: MIT

/**
 * TOTP (RFC 6238) service — frontend API wrapper.
 *
 * Talks to the same endpoints as the backend TotpService:
 *   - POST /api/totp/enroll/start           → secret + otpauth URI
 *   - POST /api/totp/enroll/confirm         → confirm + backup codes
 *   - DELETE /api/totp                     → disable (re-auth)
 *   - GET /api/totp/status                 → current state
 *   - POST /api/totp/backup-codes/regenerate → new set (re-auth)
 *   - POST /api/totp/login                 → challenge step
 *
 * Pure HTTP — no TOTP math here. The user's authenticator app computes
 * the 6-digit code from the otpauth:// URI; this service only moves the
 * secret + codes between the server and the UI.
 */

import { api } from '@/services/api';

export interface TotpEnrollStartResponse {
  secret: string;
  otpauth_uri: string;
  digits: number;
  period: number;
  algorithm: string;
}

export interface TotpEnrollConfirmResponse {
  message: string;
  backup_codes: string[];
  backup_codes_count: number;
}

export interface TotpStatusResponse {
  enabled: boolean;
  digits: number | null;
  period: number | null;
  algorithm: string | null;
  confirmed_at: string | null;
  backup_codes_remaining: number;
}

export interface TotpRequiredLoginResponse {
  code: 'auth.totp_required';
  message: string;
  totp_token: string;
  digits: number;
  period: number;
}

export interface TotpLoginSuccessResponse {
  user: unknown;
  token: string;
  token_type: string;
  expires_in: number;
}

export const totpService = {
  async startEnrollment(): Promise<TotpEnrollStartResponse> {
    return api.post<TotpEnrollStartResponse>({
      endpoint: '/totp/enroll/start',
    });
  },

  async confirmEnrollment(params: { code: string }): Promise<TotpEnrollConfirmResponse> {
    return api.post<TotpEnrollConfirmResponse>({
      endpoint: '/totp/enroll/confirm',
      data: params,
    });
  },

  async disable(params: { password?: string; auth_proof?: string }): Promise<{ message: string }> {
    return api.delete<{ message: string }>({
      endpoint: '/totp',
      data: params,
    });
  },

  async status(): Promise<TotpStatusResponse> {
    return api.get<TotpStatusResponse>({ endpoint: '/totp/status' });
  },

  async regenerateBackupCodes(params: {
    password?: string;
    auth_proof?: string;
  }): Promise<TotpEnrollConfirmResponse> {
    return api.post<TotpEnrollConfirmResponse>({
      endpoint: '/totp/backup-codes/regenerate',
      data: params,
    });
  },

  /**
   * Complete the TOTP step of a login. The backend issued a one-time
   * `totp_token` in response to the password step (see
   * {@link authController.login}); the client posts it here together with
   * either `code` (authenticator app) or `backup_code` (single-use recovery).
   */
  async completeLogin(params: {
    totp_token: string;
    code?: string;
    backup_code?: string;
  }): Promise<TotpLoginSuccessResponse> {
    return api.post<TotpLoginSuccessResponse>({
      endpoint: '/totp/login',
      data: params,
    });
  },
};

export default totpService;