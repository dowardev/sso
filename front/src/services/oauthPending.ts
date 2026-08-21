// SPDX-License-Identifier: MIT

const OAUTH_PENDING_KEY = 'oauth_pending';
const TTL_MS = 30 * 60 * 1000;

interface OauthPendingContext {
  params: Record<string, string>;
  expiresAt: number;
}

interface OauthParams {
  client_id: string;
  redirect_uri: string;
  state?: string;
  response_type: string;
  code_challenge?: string;
  code_challenge_method?: string;
  code_verifier?: string;
}

function readContext(): OauthPendingContext | null {
  try {
    const raw = localStorage.getItem(OAUTH_PENDING_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as OauthPendingContext;
    if (!parsed.params || typeof parsed.expiresAt !== 'number') return null;
    if (parsed.expiresAt <= Date.now()) {
      localStorage.removeItem(OAUTH_PENDING_KEY);
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

export function saveOauthPending(params: OauthParams): void {
  const context: OauthPendingContext = {
    params: { ...params },
    expiresAt: Date.now() + TTL_MS,
  };
  localStorage.setItem(OAUTH_PENDING_KEY, JSON.stringify(context));
}

export function loadOauthPending(): OauthParams | null {
  const context = readContext();
  if (!context) return null;
  return {
    client_id: context.params.client_id ?? '',
    redirect_uri: context.params.redirect_uri ?? '',
    state: context.params.state ?? '',
    response_type: context.params.response_type ?? 'code',
    code_challenge: context.params.code_challenge ?? '',
    code_challenge_method: context.params.code_challenge_method ?? '',
    code_verifier: context.params.code_verifier ?? '',
  };
}

export function clearOauthPending(): void {
  localStorage.removeItem(OAUTH_PENDING_KEY);
}

export function refreshOauthPendingTtl(): void {
  const context = readContext();
  if (!context) return;
  saveOauthPending(context.params as unknown as OauthParams);
}

export function buildOauthLoginQuery(params: OauthParams): Record<string, string> {
  const query: Record<string, string> = {
    client_id: params.client_id,
    redirect_uri: params.redirect_uri,
    response_type: params.response_type || 'code',
  };
  if (params.state) query.state = params.state;
  if (params.code_challenge) {
    query.code_challenge = params.code_challenge;
    query.code_challenge_method = params.code_challenge_method || 'S256';
  }
  if (params.code_verifier) query.code_verifier = params.code_verifier;
  return query;
}