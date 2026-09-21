// SPDX-License-Identifier: MIT
//
// Erase the `#profile=` fragment from the live document after `handleCallback`
// has already copied its payload into memory.
//
// WHY: the decrypted ZKE profile arrives as a URL fragment on the OAuth app's
// callback URL. Once the consumer has read it, the browser must not keep it:
// a lingering fragment stays visible and copyable in `window.location` of the
// callback document, and the URL with the fragment is retained in the session
// history entry created by the redirect navigation into that callback. The
// payload is already in a local variable by the time this runs, so rewriting
// the location only removes the copy the browser retained.
//
// MATCH GUARD: only the document the callback actually belongs to is rewritten.
// A caller may hand `handleCallback` a URL that is not the live document (a
// stored URL, an iframe, a popup); rewriting an unrelated location would be an
// unexpected side effect on the host app. We therefore require the parsed
// callback URL and the parsed live URL to agree on `origin`, `pathname`,
// `search`, and `hash` before touching `history`.
export function eraseProfileFragmentIfCurrentDocument(params: {
  callbackUrl: string;
}): boolean {
  const { callbackUrl } = params;

  // Resolve the live document defensively. In a non-browser environment (for
  // example the SDK's `node` test suite) there is nothing to clean up.
  if (typeof window === "undefined") {
    return false;
  }
  if (!window.location?.href) {
    return false;
  }
  if (typeof window.history?.replaceState !== "function") {
    return false;
  }

  let callback: URL;
  let live: URL;
  try {
    callback = new URL(callbackUrl);
    live = new URL(window.location.href);
  } catch {
    return false;
  }

  if (
    callback.origin !== live.origin ||
    callback.pathname !== live.pathname ||
    callback.search !== live.search ||
    callback.hash !== live.hash
  ) {
    return false;
  }

  // Preserve `pathname` and `search` so the OAuth `code` and `state` query
  // params survive; drop only the hash. `replaceState` is called with `this`
  // bound to the history object because jsdom requires it. Browsers ignore the
  // title argument, and reading it optionally keeps the module usable in the
  // `node` test environment where `document` does not exist.
  //
  // The state argument must be the existing `history.state`: Vue Router and
  // Ionic keep back/forward position, scroll bookkeeping, and their replacement
  // flag in `history.state`, and replacing it with a fresh object corrupts
  // `popstate` handling (their computed deltas become `NaN`).
  //
  // `replaceState` can throw `SecurityError` in an embedded or sandboxed
  // context (opaque document origin, cross-origin `<base href>`). Swallow it so
  // this helper honors its documented never-throws contract and a failed cleanup
  // does not abort the OAuth token exchange that follows.
  try {
    window.history.replaceState.call(
      window.history,
      window.history.state ?? null,
      window.document?.title ?? "",
      live.pathname + live.search
    );
  } catch {
    return false;
  }
  return true;
}
