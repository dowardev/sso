# DPoP ES256 cutover runbook

**Tickets:** LBDS-290 (this doc), LBDS-258 (dual-support server-side, deployed).
**Parent epic:** LBDS-249 (SSO Hardening: Crypto, Token Binding & Passkeys).
**Status:** Dual-allowlist active (`['ES256', 'RS256']`). Cutover pending.

This runbook owns the close-out of the RSA-2048 → ECDSA P-256 DPoP
migration. It explains how to read the cutover metric, when it is safe
to tighten the allowlist, and how to force-rotate the legacy RSA keys
that keep showing up in IndexedDB on the client side.

---

## 1. The cutover metric

The metric source is **structured logs**, not a `/metrics` endpoint.
There is no Prometheus / OTLP client wired into the Laravel app yet
(see "Future work" at the bottom), so every DPoP proof verification
emits a single log line:

```
event=dpop_proof_verified alg=<RS256|ES256> result=<accepted|rejected> allowlist=<list> jkt_prefix=<first8> htm=<METHOD> [reason=<code>] [message=<text>]
```

Emitted from:

- `back/app/Http/Middleware/VerifyDpopProof.php` → `emitDpopMetric()`.
- `packages/sso-server/src/Dpop/DpopVerifier.php` → `emitMetric()`.

Both paths share the same field schema so the cutover dashboard
queries are identical on both sides. The line goes through the default
Laravel log channel with a triple-fallback policy:

1. `Log::channel(config('logging.default') ?? 'stack')`.
2. On `Throwable`, fall back to `Log::channel('single')` (file-backed,
   always present) with a `metric_primary_channel_error` field carrying
   the original error.
3. On `Throwable` again, write a final `error_log()` line so a totally
   broken pipeline never silently kills the cutover metric.

This is best-effort by design: telemetry must never break the auth
path. The fallback chain is purely about making the failure *visible*.

### Building `dpop_proofs_total{alg,result}` from the log stream

**Loki / Cloud Logging (LogQL / filter syntax):**

```logql
{job="sso-back"} | json | event="dpop_proof_verified"
  | unwrap count_over_time({event="dpop_proof_verified"}[5m]) as proofs
```

**Elasticsearch / Datadog (Kibana / Datadog search):**

```
event:dpop_proof_verified
| group by alg, result
| count as proofs
```

**Direct SQL on the log store (Loki / Cloud Logging):**

```sql
SELECT
  json_payload->>'alg'      AS alg,
  json_payload->>'result'   AS result,
  COUNT(*)                  AS proofs
FROM log_stream
WHERE json_payload->>'event' = 'dpop_proof_verified'
  AND timestamp > NOW() - INTERVAL '1 day'
GROUP BY alg, result;
```

Both `alg` values matter independently of `result` — `accepted=RS256`
is the legacy binding we want to retire, `rejected=RS256` is traffic
from clients that have already migrated but did not see the new
allowlist yet (zero expected once the config flip lands).

### Client-side `dpop_event=...` lines

The `@dowardev/sso-client` library emits two complementary events from
the browser, also as a single JSON line per `console.info`:

- `dpop_legacy_key_retained` (deduplicated by `jkt_prefix` per
  session) — the absolute count of sessions still carrying a pre-LBDS-258
  RSA key in IndexedDB. This is the second cutover signal: the
  server-side `dpop_proofs_total{alg="RS256",result="accepted"}` drops
  to zero only when every client has rotated, and this counter is
  exactly that.
- `dpop_legacy_key_rotated` — the absolute count of force-rotations
  triggered by the app. Useful to validate that the rotation path is
  actually being called (not just available).

Both client events are JSON-formatted, identical to the server-side
log line shape, so the same parser handles both.

---

## 2. When is it safe to cut over?

The cutover criterion (from LBDS-290 acceptance criteria):

> `< 1% RS256 sustained for 30 days` (post-LBDS-258 deploy).

Translation for the dashboard:

| Metric | Target | Action |
|---|---|---|
| `dpop_proofs_total{alg="RS256",result="accepted"}` / `dpop_proofs_total{result="accepted"}` | < 1% | Cutover go |
| Same ratio | 1% – 5% | Watch weekly; investigate outliers |
| Same ratio | > 5% | **Do not cut over.** Most clients still on RS256 |
| `dpop_legacy_key_retained` (client) | 0 sustained | Confirms rotation is complete |

The 30-day window is intentional: pre-LBDS-258 bindings can outlive the
refresh-token TTL (30 days), so a shorter window risks stranding users
mid-session. The client-side `dpop_legacy_key_retained` line gives the
absolute count of retained RSA keys in the wild; it should reach zero
before the cutover is irreversible.

### Two-stage cutover

The cutover is two config-only steps so each is independently
reversible without a redeploy.

> **Set BOTH env vars on the same deploy.** The cutover spans two
> independent config surfaces — `back/config/jwt.php` and
> `packages/sso-server/config/sso.php` (or the consumer app that wires
> the `sso-server` package). Flipping only one leaves the other on the
> dual allowlist, which causes inconsistent rejections. Pre-merge
> review #6 flagged this footgun; the fix is to always update both.

**Stage 1 — tighten allowlist to ES256-only on staging.**

On the back side, in `.env` (or wherever the host reads env from):

```env
DPOP_ALLOWED_ALGS=ES256
```

On the sso-server consumer side, in `.env`:

```env
SSO_DPOP_ALLOWED_ALGS=ES256
```

(Or override `config('sso.dpop_allowed_algs')` in the consumer's own
config file; the env var takes precedence if both are set, mirroring
the back-side behavior.)

Deploy to staging. Watch the `rejected=RS256` rate for 7 days. It
should be non-zero only if a pre-LBDS-258 client is still pointing at
the staging SSO host (most prod-only clients will not reach staging at
all). If zero, the cutover is safe to roll to production.

**Stage 2 — roll the same change to production.**

Same env / config change in `DPOP_ALLOWED_ALGS=ES256` (back) and
`SSO_DPOP_ALLOWED_ALGS=ES256` (consumer). Monitor for the next 7 days;
expect a small spike in `rejected=RS256` from any pre-LBDS-258 client
that has not yet re-installed (Android / iOS apps older than the
LBDS-258 release).

### Rollback

If the rejected rate is not the spike we expected — e.g. new clients
are getting `rejected=RS256` because of a misconfiguration — restore
the dual allowlist on BOTH sides:

```env
# back/.env
DPOP_ALLOWED_ALGS=ES256,RS256

# consumer/.env
SSO_DPOP_ALLOWED_ALGS=ES256,RS256
```

No rebuild or deploy is required for the rollback, BUT the runtime
config read is done by `AppServiceProvider::bindDpopAllowedAlgs()` on
the back side and `SsoServerServiceProvider::resolveDpopAllowedAlgsConfig()`
on the consumer side. Pre-merge review #4-5: those providers
deliberately read `env()` at runtime instead of inside the config
files so `php artisan config:cache` does not freeze the value. If
your environment uses `config:cache` (production usually does), the
provider re-reads on every request, so a `.env` flip is picked up
without `config:clear`. **Verify on your deploy** that the env
re-read path is actually wired (a quick test: tighten the allowlist,
hit the endpoint, and watch the `dpop_proofs_total{allowlist="..."}`
field in the metric line — it should reflect the new value).

### Misconfiguration warnings

Both providers log a warning at boot when the allowlist contains
algorithms the verifier does not know how to dispatch (anything other
than `ES256` / `RS256`). These warnings are the early signal that a
config flip is broken before traffic hits it; on-call should treat
them as P2 alerts during the cutover window.

---

## 3. Force-rotation for legacy RSA keys

Even after the metric drops below 1%, individual users keep their RSA
keys in IndexedDB until one of the following fires:

1. They log out and log in again (a fresh ES256 keypair is generated).
2. The app explicitly calls `DpopService.forceRotateLegacyKeys()`.
3. The refresh-token TTL expires (typically 30 days, but can lag in
   practice if a long-lived session never logs out).

To shorten that tail, the application code should check
`DpopService.hasLegacyRsaKey()` at every "natural" boundary — logout,
session restart, app foreground, 24h-tick — and call
`forceRotateLegacyKeys()` if it returns `true`. The rotation is silent
to the user: the next `initialize()` regenerates a fresh ES256 pair and
re-binds the refresh token on the next API call.

### Client log lines (JSON)

```json
{"event":"dpop_legacy_key_retained","alg":"RS256","jkt_prefix":"<first8>"}
```

emitted on the first `DpopService.initialize()` call where the stored
record's `kty` is `RSA`. Pre-merge review #14: deduplicated by
`jkt_prefix` per session so re-initializing in the same app session
does not inflate the counter. The `jkt_prefix` correlates with the
server-side `jkt_prefix` in `dpop_proof_verified`.

```json
{"event":"dpop_legacy_key_rotated","previous_alg":"RS256","previous_jkt_prefix":"<first8>"}
```

emitted when the rotation runs. The two lines together are the proof
of rotation: same `previous_jkt_prefix` on both ends.

### Admin endpoint (alternative)

For users who cannot log out (broken session, expired refresh token,
locked device), the cutover can also drop the `cnf.jkt` binding on
the server side. There is no such endpoint yet — it is a follow-up
ticket (see LBDS-291, not yet filed). Until that lands, the only
forced rotation path is via the client library.

---

## 4. Smoke checklist before each deploy

Run after any change to `back/config/jwt.php`, the `sso-server`
allowlist, the service providers (`AppServiceProvider` /
`SsoServerServiceProvider`), or the `DpopService`:

```bash
# back
cd back && vendor/bin/phpunit --filter DpopAllowlistTest
cd back && vendor/bin/phpunit --filter DpopRefreshTokenTest

# sso-server
cd packages/sso-server && vendor/bin/phpunit --filter DpopAllowlistTest
cd packages/sso-server && vendor/bin/phpunit --filter DpopVerifierTest

# sso-client
cd packages/sso-client && pnpm exec vitest run src/dpop/DpopService.test.ts
```

All five must be green before the change leaves draft.

---

## 5. Future work

- **Prometheus `/metrics` endpoint.** Today the metric is log-only;
  adding a `/metrics` route with a `dpop_proofs_total{alg,result}`
  counter would let SRE own the dashboard without grep'ing logs. Out of
  scope for LBDS-290 (no Grafana stack in production yet).
- **Admin rotation endpoint** (`POST /api/admin/dpop/rotate-binding`).
  Tracked separately; required for users who cannot reach the client.
- **Audit log table.** `dpop_proof_verified` writes to the log channel
  only; a DB-backed table would give per-user forensic detail (which
  jkt, which IP, which UA) for incident response. Not blocking the
  cutover.

---

## 6. References

- LBDS-258 — original dual-support server-side deploy.
- `back/tests/Feature/DpopAllowlistTest.php` — allowlist transition
  tests on the Laravel side (covers both happy paths and metric
  emission shape).
- `back/tests/Feature/DpopRefreshTokenTest.php` — DPoP end-to-end tests
  on the protected endpoints.
- `packages/sso-server/tests/DpopAllowlistTest.php` — allowlist
  transition tests on the consumer-library side (covers env-parsing
  edge cases and metric emission shape).
- `packages/sso-server/tests/DpopVerifierTest.php` — pre-LBDS-290
  verifier tests (kept as the regression guard for the dual-allowlist
  default).
- `packages/sso-client/src/dpop/DpopService.ts` — `hasLegacyRsaKey()`,
  `forceRotateLegacyKeys()`, and the structured log emitter.
- `back/app/Providers/AppServiceProvider.php` — runtime resolution of
  `DPOP_ALLOWED_ALGS` (the only place the env var is read on the back
  side; the config file in `back/config/jwt.php` ships a hard-coded
  default and is unaffected by `config:cache`).
- `packages/sso-server/src/SsoServerServiceProvider.php` — runtime
  resolution of `SSO_DPOP_ALLOWED_ALGS` (same pattern).