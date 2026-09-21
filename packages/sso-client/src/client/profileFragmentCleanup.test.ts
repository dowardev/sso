// @vitest-environment jsdom
// SPDX-License-Identifier: MIT
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { eraseProfileFragmentIfCurrentDocument } from "./profileFragmentCleanup";

const CALLBACK_PATH = "/auth/callback?code=abc&state=xyz";

function setLiveLocation(params: { url: string }): void {
  const { url } = params;
  window.history.replaceState({}, "", url);
}

describe("eraseProfileFragmentIfCurrentDocument", () => {
  beforeEach(() => {
    // Restore a clean live location so tests do not contaminate each other.
    setLiveLocation({ url: "/" });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("erases the fragment while preserving pathname and search", () => {
    const callbackUrl = `${window.location.origin}${CALLBACK_PATH}#profile=AAAA`;
    setLiveLocation({ url: `${CALLBACK_PATH}#profile=AAAA` });

    const result = eraseProfileFragmentIfCurrentDocument({ callbackUrl });

    expect(result).toBe(true);
    expect(window.location.hash).toBe("");
    expect(window.location.pathname).toBe("/auth/callback");
    expect(window.location.search).toBe("?code=abc&state=xyz");
    expect(window.location.href).not.toContain("#profile=");
  });

  it("is idempotent: a second call on the same document returns false", () => {
    const callbackUrl = `${window.location.origin}${CALLBACK_PATH}#profile=AAAA`;
    setLiveLocation({ url: `${CALLBACK_PATH}#profile=AAAA` });

    expect(eraseProfileFragmentIfCurrentDocument({ callbackUrl })).toBe(true);
    const afterFirst = window.location.href;

    expect(eraseProfileFragmentIfCurrentDocument({ callbackUrl })).toBe(false);
    expect(window.location.href).toBe(afterFirst);
  });

  it("returns false for a callback URL on a different origin", () => {
    setLiveLocation({ url: `${CALLBACK_PATH}#profile=AAAA` });
    const before = window.location.href;

    const result = eraseProfileFragmentIfCurrentDocument({
      callbackUrl: `https://other.example.com${CALLBACK_PATH}#profile=AAAA`,
    });

    expect(result).toBe(false);
    expect(window.location.href).toBe(before);
  });

  it("returns false for a different pathname", () => {
    setLiveLocation({ url: `${CALLBACK_PATH}#profile=AAAA` });
    const before = window.location.href;

    const result = eraseProfileFragmentIfCurrentDocument({
      callbackUrl: `${window.location.origin}/other/callback?code=abc&state=xyz#profile=AAAA`,
    });

    expect(result).toBe(false);
    expect(window.location.href).toBe(before);
  });

  it("returns false for a different search", () => {
    setLiveLocation({ url: `${CALLBACK_PATH}#profile=AAAA` });
    const before = window.location.href;

    const result = eraseProfileFragmentIfCurrentDocument({
      callbackUrl: `${window.location.origin}/auth/callback?code=zzz&state=xyz#profile=AAAA`,
    });

    expect(result).toBe(false);
    expect(window.location.href).toBe(before);
  });

  it("returns false when the live document has no fragment", () => {
    setLiveLocation({ url: CALLBACK_PATH });
    const before = window.location.href;

    const result = eraseProfileFragmentIfCurrentDocument({
      callbackUrl: `${window.location.origin}${CALLBACK_PATH}#profile=AAAA`,
    });

    expect(result).toBe(false);
    expect(window.location.href).toBe(before);
  });

  it("returns false and never throws for a malformed callback URL", () => {
    setLiveLocation({ url: `${CALLBACK_PATH}#profile=AAAA` });
    const before = window.location.href;

    expect(() =>
      eraseProfileFragmentIfCurrentDocument({ callbackUrl: "not a url" })
    ).not.toThrow();
    expect(
      eraseProfileFragmentIfCurrentDocument({ callbackUrl: "not a url" })
    ).toBe(false);
    expect(window.location.href).toBe(before);
  });

  it("returns false when history.replaceState is absent", () => {
    const callbackUrl = `${window.location.origin}${CALLBACK_PATH}#profile=AAAA`;
    vi.stubGlobal("window", {
      location: { href: callbackUrl },
    });

    expect(() =>
      eraseProfileFragmentIfCurrentDocument({ callbackUrl })
    ).not.toThrow();
    expect(eraseProfileFragmentIfCurrentDocument({ callbackUrl })).toBe(false);
  });

  it("returns false when history.replaceState is not a function", () => {
    const callbackUrl = `${window.location.origin}${CALLBACK_PATH}#profile=AAAA`;
    vi.stubGlobal("window", {
      location: { href: callbackUrl },
      history: { replaceState: "not a function" },
    });

    expect(eraseProfileFragmentIfCurrentDocument({ callbackUrl })).toBe(false);
  });

  it("preserves the existing history.state while erasing the fragment", () => {
    const callbackUrl = `${window.location.origin}${CALLBACK_PATH}#profile=AAAA`;
    setLiveLocation({ url: `${CALLBACK_PATH}#profile=AAAA` });
    const routerState = {
      back: "previous",
      position: 3,
      scroll: { x: 12, y: 34 },
    };
    window.history.replaceState(routerState, "", `${CALLBACK_PATH}#profile=AAAA`);

    const result = eraseProfileFragmentIfCurrentDocument({ callbackUrl });

    expect(result).toBe(true);
    expect(window.location.hash).toBe("");
    expect(window.history.state).toEqual(routerState);
  });

  it("returns false and never throws when replaceState throws", () => {
    const callbackUrl = `${window.location.origin}${CALLBACK_PATH}#profile=AAAA`;
    vi.stubGlobal("window", {
      location: {
        origin: window.location.origin,
        href: callbackUrl,
      },
      history: {
        replaceState: () => {
          throw new DOMException("blocked", "SecurityError");
        },
      },
      document: { title: "Test" },
    });

    let result: boolean | undefined;
    expect(() => {
      result = eraseProfileFragmentIfCurrentDocument({ callbackUrl });
    }).not.toThrow();
    expect(result).toBe(false);
  });
});
