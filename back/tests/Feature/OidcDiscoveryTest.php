<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Feature tests for the OIDC Discovery 1.0 endpoint exposed at
 * `/.well-known/openid-configuration` (mounted at /api/.well-known/...
 * in this app). Pins the discovery doc shape so a regression that strips or
 * renames a field surfaces immediately — every OIDC client fetches this doc
 * to configure itself, so the contract is part of the public surface.
 */
class OidcDiscoveryTest extends TestCase
{
    public function test_discovery_endpoint_returns_oidc_metadata(): void
    {
        $response = $this->getJson('/api/.well-known/openid-configuration');

        $response->assertOk()->assertJsonStructure([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'userinfo_endpoint',
            'jwks_uri',
            'end_session_endpoint',
            'scopes_supported',
            'response_types_supported',
            'response_modes_supported',
            'grant_types_supported',
            'token_endpoint_auth_methods_supported',
            'id_token_signing_alg_values_supported',
            'subject_types_supported',
            'claims_supported',
            'code_challenge_methods_supported',
        ]);
    }

    public function test_discovery_issuer_matches_jwt_issuer(): void
    {
        // The discovery `issuer` MUST equal the value the SSO stamps into
        // every JWT `iss` claim. If they ever drift, OIDC clients fail the
        // §4.3 issuer check and reject every token, so this test pins both
        // ends against the same config value.
        $expected = (string) config('jwt.issuer');
        $this->assertNotSame('', $expected, 'jwt.issuer must be configured for the test to be meaningful');

        $issuer = $this->getJson('/api/.well-known/openid-configuration')
            ->assertOk()
            ->json('issuer');

        $this->assertSame(
            $expected,
            $issuer,
            'discovery issuer must match config(jwt.issuer) so OIDC clients can match it against the iss claim'
        );
    }

    public function test_discovery_endpoint_urls_share_request_origin(): void
    {
        // Endpoint URLs are derived from the current request scheme + host so
        // reverse proxies that terminate TLS or rewrite paths stay correct.
        // A regression that hardcodes http://localhost:8001 would break every
        // prod deployment that fronts the SSO.
        $base = rtrim(url('/api'), '/');

        $response = $this->getJson('/api/.well-known/openid-configuration')->assertOk();
        $this->assertSame($base.'/oauth/authorize', $response->json('authorization_endpoint'));
        $this->assertSame($base.'/oauth/token', $response->json('token_endpoint'));
        $this->assertSame($base.'/oauth/userinfo', $response->json('userinfo_endpoint'));
        $this->assertSame($base.'/.well-known/jwks', $response->json('jwks_uri'));
        $this->assertSame($base.'/oauth/logout', $response->json('end_session_endpoint'));
    }

    public function test_discovery_honors_oidc_base_url_config_override(): void
    {
        // Behind a path-rewriting reverse proxy, the request's path is
        // not the path the RP will use to reach the SSO. OIDC_BASE_URL
        // pins the externally-visible origin so the advertised endpoints
        // are reachable. Without the override, the test framework's
        // request URL (which has no proxy in front of it) would be
        // used instead — and a production deployment with a proxy
        // would have a discovery doc full of unreachable URLs.
        config(['jwt.oidc_base_url' => 'https://sso.example.com']);

        $response = $this->getJson('/api/.well-known/openid-configuration')->assertOk();

        $this->assertSame('https://sso.example.com/oauth/authorize', $response->json('authorization_endpoint'));
        $this->assertSame('https://sso.example.com/oauth/token', $response->json('token_endpoint'));
        $this->assertSame('https://sso.example.com/oauth/userinfo', $response->json('userinfo_endpoint'));
        $this->assertSame('https://sso.example.com/.well-known/jwks', $response->json('jwks_uri'));
        $this->assertSame('https://sso.example.com/oauth/logout', $response->json('end_session_endpoint'));
    }

    public function test_discovery_falls_back_when_oidc_base_url_is_unparseable(): void
    {
        // An operator typo (missing scheme, bare hostname with
        // whitespace, etc.) must not propagate into the discovery
        // doc as a broken URL. The controller silently falls back to
        // the request-derived base so a misconfigured environment
        // still produces a usable document.
        config(['jwt.oidc_base_url' => 'not a real url']);

        $response = $this->getJson('/api/.well-known/openid-configuration')->assertOk();

        $this->assertStringStartsWith('http', $response->json('authorization_endpoint'));
        $this->assertStringNotContainsString('not a real url', $response->json('authorization_endpoint'));
    }

    public function test_discovery_advertises_required_oidc_capabilities(): void
    {
        $response = $this->getJson('/api/.well-known/openid-configuration')->assertOk();
        $body = $response->json();

        // Scopes: openid is mandatory for any OIDC RP-initiated flow; profile
        // and email are the standard claim groups; offline_access maps to the
        // existing refresh_token issuance, so it's already meaningful.
        $this->assertContains('openid', $body['scopes_supported']);
        $this->assertContains('profile', $body['scopes_supported']);
        $this->assertContains('email', $body['scopes_supported']);
        $this->assertContains('offline_access', $body['scopes_supported']);

        // Only `code` — no implicit/hybrid. The SSO does not ship an
        // /authorize UI flow that returns tokens in the fragment.
        $this->assertSame(['code'], $body['response_types_supported']);

        // Only RS256 — that's the only signing alg the JWKS publishes, and
        // JwtService hardcodes it. Listing anything else would be a lie.
        $this->assertSame(['RS256'], $body['id_token_signing_alg_values_supported']);

        // PKCE: S256 + plain. S256 is the recommended default; `plain`
        // is also accepted for back-compat with pre-migration clients
        // that cannot hash. The discovery doc advertises both so the
        // metadata is honest about what the validator will accept.
        $this->assertSame(['S256', 'plain'], $body['code_challenge_methods_supported']);
    }
}
