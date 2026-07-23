<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Exceptions\DpopVerificationException;
use Dowardev\SsoServer\Exceptions\JwksFetchException;
use Dowardev\SsoServer\Exceptions\SsoAuthenticationException;

class ExceptionsTest extends TestCase
{
    public function test_sso_auth_token_missing(): void
    {
        $e = SsoAuthenticationException::tokenMissing();
        $this->assertEquals(401, $e->getStatusCode());
        $this->assertEquals('sso_authentication_failed', $e->getErrorCode());
    }

    public function test_sso_auth_token_invalid(): void
    {
        $e = SsoAuthenticationException::tokenInvalid('expired');
        $this->assertEquals(401, $e->getStatusCode());
        $this->assertStringContainsString('expired', $e->getMessage());
    }

    public function test_sso_auth_dpop_required(): void
    {
        $e = SsoAuthenticationException::dpopRequired();
        $this->assertEquals(401, $e->getStatusCode());
        $this->assertStringContainsString('DPoP', $e->getMessage());
    }

    public function test_dpop_replay_detected(): void
    {
        $e = DpopVerificationException::replayDetected();
        $this->assertEquals(401, $e->getStatusCode());
        $this->assertEquals('dpop_verification_failed', $e->getErrorCode());
    }

    public function test_dpop_thumbprint_mismatch(): void
    {
        $e = DpopVerificationException::thumbprintMismatch();
        $this->assertEquals(401, $e->getStatusCode());
    }

    public function test_jwks_fetch_unreachable(): void
    {
        $e = JwksFetchException::unreachable('http://sso.test');
        $this->assertEquals(503, $e->getStatusCode());
        $this->assertEquals('jwks_fetch_failed', $e->getErrorCode());
    }

    public function test_jwks_fetch_empty(): void
    {
        $e = JwksFetchException::emptyJwks();
        $this->assertEquals(503, $e->getStatusCode());
    }
}
