<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Http\Controllers;

use App\Models\SsoClient;
use App\Models\User;
use App\Services\AuthService;
use App\Services\JwtService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OidcController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private SessionService $sessionService,
    ) {}

    /**
     * OIDC Discovery 1.0 endpoint.
     *
     * Returns the static metadata describing this IdP so any OIDC client can
     * configure itself without out-of-band coordination. The `issuer` matches
     * config('jwt.issuer') — the same value the SSO stamps into every `iss`
     * claim — so a client can match the discovery issuer against the token
     * issuer before trusting either. A mismatch is a hard fail per OIDC
     * Discovery 1.0 §4.3: the client must abort.
     *
     * Endpoint URLs are derived from the current request's scheme + host so
     * the discovery doc stays correct regardless of whether the SSO is
     * running at http://localhost:8001 locally, behind a reverse proxy that
     * terminates TLS, or behind a path-rewriting proxy. Hardcoding
     * config('app.url') would silently break any deployment where the SSO is
     * not the top of the request's host.
     */
    public function discovery(): JsonResponse
    {
        $base = $this->oidcBaseUrl();

        return response()->json([
            'issuer' => config('jwt.issuer'),
            'authorization_endpoint' => $base.'/oauth/authorize',
            'token_endpoint' => $base.'/oauth/token',
            'userinfo_endpoint' => $base.'/oauth/userinfo',
            'jwks_uri' => $base.'/.well-known/jwks',
            'end_session_endpoint' => $base.'/oauth/logout',
            'scopes_supported' => ['openid', 'profile', 'email', 'offline_access'],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
                'none',
            ],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'subject_types_supported' => ['public'],
            'claims_supported' => [
                'sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce',
                'acr', 'amr', 'name', 'email', 'email_verified',
                'preferred_username', 'picture', 'client_id',
            ],
            // PKCE methods accepted at /oauth/authorize. S256 is the
            // recommended default; `plain` is also accepted for
            // back-compat with pre-migration clients that cannot hash.
            // The discovery doc advertises both so the metadata is
            // honest about what the validator will accept — hiding
            // `plain` while still honouring it would make new clients
            // believe we are stricter than we are, and then break when
            // a legacy RP sends `plain` anyway.
            'code_challenge_methods_supported' => ['S256', 'plain'],
            'request_parameter_supported' => false,
            'request_uri_parameter_supported' => false,
            'claims_parameter_supported' => false,
        ]);
    }

    /**
     * Base URL the OIDC surface hangs off, derived from the live request.
     *
     * Strips the well-known suffix from the current request URL to leave the
     * path the rest of the OIDC endpoints share. This is tied to the route
     * mounted in routes/api.php at `/.well-known/openid-configuration` — if
     * the route moves, the strip pattern must move with it.
     */
    private function oidcBaseUrl(): string
    {
        // Pin via OIDC_BASE_URL when set. The discovery response MUST
        // advertise URLs an RP can reach; behind a path-rewriting
        // proxy (e.g. /sso/api/... served at /api/...), the request
        // URL would carry the proxy's path and the advertised
        // endpoints would be unreachable. The config knob is the
        // escape hatch — set OIDC_BASE_URL=https://sso.example.com
        // in production and the discovery doc is correct without
        // depending on the request's path.
        //
        // The value is validated: an operator typo (e.g. missing
        // scheme, host with whitespace) would otherwise propagate
        // into the discovery response and produce a document full of
        // unreachable endpoints. A silent fallback to the
        // request-derived URL is safer than emitting broken
        // metadata, so we ignore an unparseable value and let the
        // request path take over.
        $configured = config('jwt.oidc_base_url');

        if (is_string($configured) && $configured !== '') {
            $trimmed = rtrim($configured, '/');
            $parts = parse_url($trimmed);

            if (is_array($parts) && isset($parts['scheme'], $parts['host'])
                && in_array($parts['scheme'], ['http', 'https'], true)
            ) {
                return $trimmed;
            }
        }

        $url = request()->url();

        return preg_replace('#/\.well-known/openid-configuration$#', '', $url);
    }

    /**
     * OIDC Core 1.0 §5.3 userinfo endpoint.
     *
     * Returns the standard OIDC claim set for the user behind the Bearer
     * access token, filtered by the scopes that were granted at /oauth/
     * authorize time. Per the spec, the response always carries `sub`; the
     * `profile` and `email` claim groups only appear when their matching
     * scope was granted. Anything else the user has (encryption_level, roles,
     * sessions) stays out — the OIDC surface is intentionally narrow.
     *
     * Tokens issued via the client_credentials grant carry no user, so the
     * middleware's user resolver returns null and we answer 403 instead of
     * letting the rest of the pipeline build a User-less response. That
     * mirrors the §5.3.1 "Access Token MUST be valid" rule, and a missing
     * user behind an otherwise-valid token is a configuration error worth
     * surfacing rather than masking as an empty 200.
     */
    public function userinfo(Request $request, JwtService $jwtService): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            $grantType = $request->input('sso_grant_type');

            if ($grantType === 'client_credentials') {
                return response()->json([
                    'error' => 'invalid_token',
                    'error_description' => 'client_credentials tokens have no associated user',
                ], 403);
            }

            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'The access token did not resolve to a user',
            ], 403);
        }

        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'error' => 'invalid_token',
            ], 401);
        }

        $parsed = $jwtService->parseAndValidateToken($token);

        if ($parsed === null) {
            return response()->json([
                'error' => 'invalid_token',
            ], 401);
        }

        // The OIDC IdP userinfo endpoint expects a bearer access token
        // issued by the OAuth2 authorization_code / refresh_token grant
        // flow — the kind that carries a `client_id` claim so the
        // handler can resolve which SsoClient made the request.
        // SSO-web login tokens do not carry a `client_id` because they
        // authenticate a user against the SSO's own frontend, not
        // against an external RP. Surfacing them as 403 here makes the
        // contract explicit instead of letting a confused integration
        // see a 200 with just `sub`.
        if (! is_string($parsed->claims()->get('client_id')) || $parsed->claims()->get('client_id') === '') {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'The access token is not an OIDC IdP access token (no client_id claim)',
            ], 403);
        }

        $grantedScopes = $this->parseScopes($parsed->claims()->get('scope'));

        $claims = [
            'sub' => (string) $user->id,
        ];

        if (in_array('profile', $grantedScopes, true)) {
            $claims['name'] = $user->name;
            $claims['preferred_username'] = $user->user;
            $claims['updated_at'] = $user->updated_at?->getTimestamp();
        }

        if (in_array('email', $grantedScopes, true)) {
            $claims['email'] = $user->email;
            $claims['email_verified'] = $user->email_verified_at !== null;
        }

        // LBDS-303: per-client claim overrides. The access token carries
        // the requesting client_id (added to the access token in the
        // same commit), so we can look up the client config and apply
        // the overrides. The reserved OIDC claims stay locked; an
        // override named `sub` / `aud` etc. is silently skipped, same
        // contract the id_token applies. The list is shared with the
        // id_token path so a per-client `email` override can never
        // make the two surfaces disagree.
        $clientId = $parsed->claims()->get('client_id');
        if (is_string($clientId) && $clientId !== '') {
            $client = SsoClient::where('client_id', $clientId)->first();
            if ($client) {
                $overrides = $client->effectiveClaimOverrides();
                $reserved = JwtService::reservedClaims();
                foreach ($overrides['extra_claims'] as $name => $value) {
                    if (! is_string($name)
                        || $name === ''
                        || array_key_exists($name, $claims)
                        || in_array($name, $reserved, true)
                    ) {
                        continue;
                    }
                    $claims[$name] = $this->resolveClaimValue($value, $user);
                }
            }
        }

        return response()->json($claims);
    }

    private function resolveClaimValue(string $value, User $user): mixed
    {
        if (! str_starts_with($value, 'user.')) {
            return $value;
        }

        $attribute = substr($value, 5);

        if ($attribute === '' || ! array_key_exists($attribute, $user->getAttributes())) {
            return null;
        }

        $raw = $user->getAttribute($attribute);

        if ($raw instanceof \DateTimeInterface) {
            return $raw->getTimestamp();
        }

        return $raw;
    }

    /**
     * @return array<int, string>
     */
    private function parseScopes(mixed $scope): array
    {
        if (! is_string($scope) || trim($scope) === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /**
     * OIDC Session Management 1.0 §2 RP-initiated logout endpoint.
     *
     * Accepts id_token_hint, post_logout_redirect_uri, and state. The
     * id_token_hint is verified (signature, issuer, expiry) to identify
     * the user and the requesting client; the post_logout_redirect_uri
     * is matched against the client's registered redirect_url so an RP
     * cannot use the SSO as an open redirector after logging the user
     * out.
     *
     * Behavior:
     *   - id_token_hint invalid / missing user  -> 400 (no logout happens)
     *   - post_logout_redirect_uri present and
     *     doesn't match the client's redirect_url -> 400 (open-redirect
     *     protection)
     *   - post_logout_redirect_uri present and matches -> 302 redirect,
     *     with the original `state` echoed in the query
     *   - no post_logout_redirect_uri -> 200 HTML page that confirms the
     *     logout, so a user that typed the URL directly still sees what
     *     happened instead of a blank screen
     *
     * The user's SSO sessions and refresh tokens are revoked in the
     * database regardless of whether a redirect happens. Skipping the DB
     * side would let the SSO keep issuing access tokens to the old
     * refresh tokens for up to the refresh-token inactivity window.
     */
    public function endSession(Request $request, JwtService $jwtService): Response|RedirectResponse
    {
        $idTokenHint = $request->query('id_token_hint');
        $postLogoutRedirectUri = $request->query('post_logout_redirect_uri');
        $state = $request->query('state');

        $user = null;
        $client = null;

        if (is_string($idTokenHint) && $idTokenHint !== '') {
            $parsed = $jwtService->parseAndValidateToken($idTokenHint);

            if ($parsed === null) {
                return response('Invalid id_token_hint', 400);
            }

            $aud = $parsed->claims()->get('aud');
            $clientId = is_array($aud) ? ($aud[0] ?? null) : $aud;
            if (is_string($clientId) && $clientId !== '') {
                $client = SsoClient::where('client_id', $clientId)->first();
            }

            $sub = $parsed->claims()->get('sub');
            if ($sub !== null && $sub !== '') {
                $user = User::find($sub);
            }

            if (! $user) {
                return response('id_token_hint resolved to no user', 400);
            }
        } else {
            // No id_token_hint — degraded mode. Without a verified hint
            // we have no user anchor and no client anchor, so a
            // post_logout_redirect_uri cannot be validated and a logout
            // cannot be performed. The endpoint falls through to the
            // HTML confirmation page below, which is what a user that
            // typed the URL directly sees. The OIDC spec lists
            // id_token_hint as RECOMMENDED, not REQUIRED; the
            // previous behavior (400) was a spec deviation surfaced by
            // the pre-merge review.
        }

        if (is_string($postLogoutRedirectUri) && $postLogoutRedirectUri !== '') {
            if (! $client) {
                return response('post_logout_redirect_uri requires a known id_token_hint client', 400);
            }

            if ($postLogoutRedirectUri !== $client->redirect_url) {
                // Open-redirect protection: an RP must declare its
                // post_logout URL up front (for now, the same as its
                // /oauth/authorize callback). Anything else is a likely
                // phishing attempt.
                return response('post_logout_redirect_uri does not match the client redirect_url', 400);
            }
        }

        // Revoke everything the SSO holds for this user. Doing this
        // unconditionally on a valid id_token_hint is the whole point of
        // RP-initiated logout — the RP no longer trusts the access
        // token, and the SSO must not keep issuing new ones.
        if ($user !== null) {
            $this->authService->revokeAllUserRefreshTokens($user);
            $this->sessionService->revokeAllUserSessions($user);
        }

        if (is_string($postLogoutRedirectUri) && $postLogoutRedirectUri !== '' && $user !== null) {
            $url = $postLogoutRedirectUri;
            if (is_string($state) && $state !== '') {
                $separator = str_contains($url, '?') ? '&' : '?';
                $url .= $separator.'state='.urlencode($state);
            }

            return redirect($url);
        }

        $name = $user?->name;

        // The copy is intentionally explicit: when `$user` is null
        // (no id_token_hint or invalid hint) no logout happened. The
        // page must not pretend otherwise — a user that lands here
        // from a bookmark or a phishing link would otherwise see a
        // 'logged out' confirmation while their actual sessions stay
        // live. Telling them the truth is the only honest answer.
        $message = $user === null
            ? '<p>No se pudo identificar la sesión a cerrar. Si querés cerrar tu sesión en el SSO, usá el botón <strong>Cerrar sesión</strong> en el frontend.</p>'
            : "<p>{$name}, tu sesión en el SSO ha sido terminada.</p>";

        return response(
            '<!DOCTYPE html><html><head><title>Cierre de sesión</title></head>'
            .'<body style="font-family:system-ui;padding:2rem;">'
            .'<h1>Cierre de sesión</h1>'
            .$message
            .'</body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }
}
