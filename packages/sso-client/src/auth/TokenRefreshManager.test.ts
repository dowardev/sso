// SPDX-License-Identifier: MIT
import { describe, it, expect, vi } from "vitest";
import axios, { AxiosError, InternalAxiosRequestConfig } from "axios";
import { TokenRefreshManager } from "./TokenRefreshManager";

function createHarness(overrides: { accessToken?: string | null } = {}) {
  const accessToken =
    overrides.accessToken === undefined ? "old-access" : overrides.accessToken;
  const storage = {
    getToken: vi.fn(async (): Promise<any> => accessToken),
    getRefreshToken: vi.fn(async (): Promise<any> => "old-refresh"),
    setToken: vi.fn(async (): Promise<any> => undefined),
    setRefreshToken: vi.fn(async (): Promise<any> => undefined),
  };
  const ssoRequester = {
    getSsoUrl: vi.fn(() => "https://sso.example/api"),
    getClientId: vi.fn(() => "test-app"),
    onRefreshFailed: vi.fn(),
    post: vi.fn(),
  };
  const dpop = {
    getPublicKeyJwk: vi.fn(() => ({ kty: "RSA", n: "test-n", e: "AQAB" })),
    createDpopProof: vi.fn(async () => "test-dpop-proof"),
  };
  const manager = new TokenRefreshManager(
    storage as never,
    dpop as never,
    ssoRequester as never
  );
  return { storage, ssoRequester, dpop, manager };
}

describe("TokenRefreshManager", () => {
  it("refreshes via /refresh-token with body transport and stores rotated refresh token", async () => {
    const requestSpy = vi
      .spyOn(axios, "request")
      .mockResolvedValue({ data: { token: "new-access", refresh_token: "rotated-refresh" } });

    const { storage, manager } = createHarness();
    const config = { headers: {} } as InternalAxiosRequestConfig;
    const error = new AxiosError(
      "Request failed with status code 401",
      AxiosError.ERR_BAD_REQUEST,
      config,
      null,
      {
        status: 401,
        data: {},
        statusText: "",
        headers: {},
        config,
      }
    );
    const recreateRequest = vi.fn(async (_failedConfig: InternalAxiosRequestConfig, token: string) => ({
      token,
    }));

    const result = await manager.handle401Error(error, recreateRequest);

    expect(requestSpy).toHaveBeenCalledWith(
      expect.objectContaining({
        url: "https://sso.example/api/refresh-token",
        method: "POST",
        data: { refreshToken: "old-refresh", client_id: "test-app" },
      })
    );
    // The proof travels with the refresh even when it is a rotation.
    const refreshCall = requestSpy.mock.calls[0][0] as {
      headers?: Record<string, string>;
    };
    expect(refreshCall.headers?.["DPoP"]).toBe("test-dpop-proof");
    expect(storage.setToken).toHaveBeenCalledWith("new-access");
    expect(storage.setRefreshToken).toHaveBeenCalledWith("rotated-refresh");
    expect(recreateRequest).toHaveBeenCalledWith(config, "new-access");
    expect(result).toEqual({ token: "new-access" });
  });

  it("sends a DPoP proof even when no access token is stored, without requiring ath", async () => {
    const requestSpy = vi
      .spyOn(axios, "request")
      .mockResolvedValue({ data: { token: "new-access" } });

    const { storage, dpop, manager } = createHarness({ accessToken: null });
    const token = await (
      manager as unknown as { refreshToken(): Promise<string> }
    ).refreshToken();

    expect(token).toBe("new-access");
    expect(storage.getToken).toHaveBeenCalled();
    // No cached bearer: no Authorization header, but the proof is still built
    // with no access token so `ath` is omitted.
    const refreshCall = requestSpy.mock.calls[0][0] as {
      headers?: Record<string, string>;
    };
    expect(refreshCall.headers?.["DPoP"]).toBe("test-dpop-proof");
    expect(refreshCall.headers?.["Authorization"]).toBeUndefined();
    expect(dpop.createDpopProof).toHaveBeenCalledWith(
      "POST",
      "https://sso.example/api/refresh-token",
      undefined
    );
  });
});
