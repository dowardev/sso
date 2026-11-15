// SPDX-License-Identifier: MIT

/**
 * Regression guard for the CSRF-header/CORS breakage that made every login
 * fail with `TypeError: Failed to fetch`, surfacing as the generic
 * "No pudimos validar tus credenciales" message.
 *
 * The Laravel web route `/` sets an `XSRF-TOKEN` cookie. Cookies are scoped
 * by host+path, not by port, so the frontend on `localhost:8101` received the
 * cookie set by the backend on `localhost:8001` and sent it back as
 * `X-XSRF-TOKEN`. That header is not in the CORS allow-list, so the browser
 * rejected the preflight and blocked every request.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

describe('api headers', () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ ok: true }),
    });
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/';
    localStorage.clear();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  });

  it('never sends an X-XSRF-TOKEN header, even when the cookie is present', async () => {
    const { api } = await import('@/services/api');

    await api.post({ endpoint: '/auth/methods', data: { identifier: 'admin' } });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [, init] = fetchMock.mock.calls[0];
    const headers = init.headers as Record<string, string>;

    expect(headers['X-XSRF-TOKEN']).toBeUndefined();
    expect(Object.keys(headers)).not.toContain('X-XSRF-TOKEN');
  });

  it('still sends the bearer token when one is stored', async () => {
    localStorage.setItem('token', 'jwt-value');

    const { api } = await import('@/services/api');

    await api.post({ endpoint: '/auth/methods', data: { identifier: 'admin' } });

    const [, init] = fetchMock.mock.calls[0];
    const headers = init.headers as Record<string, string>;

    expect(headers['Authorization']).toBe('Bearer jwt-value');
  });
});
