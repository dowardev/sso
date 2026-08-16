// SPDX-License-Identifier: MIT
import { describe, it, expect, vi } from "vitest";
import axios, { AxiosError, InternalAxiosRequestConfig } from "axios";
import { TokenRefreshManager } from "./TokenRefreshManager";

function createHarness() {
  const storage = {
    getToken: vi.fn(async (): Promise<any> => "old-access"),
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
    createDpopProof: vi.fn(async () => undefined),
  };
  const manager = new TokenRefreshManager(
    storage as never,
    dpop as never,
    ssoRequester as never
  );
  return { storage, ssoRequester, manager };
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
    expect(storage.setToken).toHaveBeenCalledWith("new-access");
    expect(storage.setRefreshToken).toHaveBeenCalledWith("rotated-refresh");
    expect(recreateRequest).toHaveBeenCalledWith(config, "new-access");
    expect(result).toEqual({ token: "new-access" });
  });
});
