// SPDX-License-Identifier: MIT
import { describe, it, expect, vi } from "vitest";
import { AuthService } from "./AuthService";

interface FakeCall {
  method: string;
  url: string;
  data?: object;
}

function createHarness() {
  const calls: FakeCall[] = [];
  const fake = {
    post: vi.fn(async (params: { url: string; data?: object }): Promise<any> => {
      calls.push({ method: "post", url: params.url, data: params.data });
      throw new Error("not stubbed");
    }),
    get: vi.fn(async (url: string): Promise<any> => {
      calls.push({ method: "get", url });
      throw new Error("not stubbed");
    }),
    getClientId: vi.fn(() => "test-app"),
  };
  return { fake, calls };
}

describe("AuthService", () => {
  it("checkSession forces an HTTP call to session/check with the client id", async () => {
    const { fake } = createHarness();
    fake.get.mockResolvedValue({
      authenticated: true,
      user: { id: 1, name: "Ana" },
    });
    const service = new AuthService(fake);

    const result = await service.checkSession();

    expect(fake.get).toHaveBeenCalledWith("session/check?client_id=test-app");
    expect(result).toEqual({
      authenticated: true,
      user: { id: 1, name: "Ana" },
    });
  });

  it("refreshToken sends the refresh token and client id and maps refresh_token to refreshToken", async () => {
    const { fake } = createHarness();
    fake.post.mockResolvedValue({
      token: "new-access",
      token_type: "Bearer",
      expires_in: 3600,
      refresh_token: "rotated-refresh",
    });
    const service = new AuthService(fake);

    const result = await service.refreshToken({ refreshToken: "old-refresh" });

    expect(fake.post).toHaveBeenCalledWith({
      url: "refresh-token",
      data: { refreshToken: "old-refresh", client_id: "test-app" },
    });
    expect(result.refreshToken).toBe("rotated-refresh");
  });
});
