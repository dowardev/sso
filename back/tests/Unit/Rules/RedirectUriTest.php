<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Unit\Rules;

use App\Rules\RedirectUri;
use PHPUnit\Framework\TestCase;

class RedirectUriTest extends TestCase
{
    public function test_accepts_http_and_https_urls(): void
    {
        $this->assertTrue(RedirectUri::isAllowed('http://localhost:8104/auth/callback'));
        $this->assertTrue(RedirectUri::isAllowed('https://app.example.com/auth/callback'));
    }

    public function test_accepts_custom_scheme_uris(): void
    {
        $this->assertTrue(RedirectUri::isAllowed('com.dowardev.onespend://auth/callback'));
        $this->assertTrue(RedirectUri::isAllowed('myapp://callback?code=abc'));
    }

    public function test_rejects_garbage(): void
    {
        $this->assertFalse(RedirectUri::isAllowed('not a uri'));
        $this->assertFalse(RedirectUri::isAllowed('://missing-scheme'));
        $this->assertFalse(RedirectUri::isAllowed('myscheme:'));
        $this->assertFalse(RedirectUri::isAllowed('1bad://x'));
    }

    public function test_validate_fails_with_clear_message(): void
    {
        $rule = new RedirectUri();
        $failed = null;
        $rule->validate('redirect_url', 'not a uri', function (string $message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertNotNull($failed);
    }

    public function test_validate_passes_for_custom_scheme(): void
    {
        $rule = new RedirectUri();
        $failed = false;
        $rule->validate('redirect_url', 'com.dowardev.onespend://auth/callback', function () use (&$failed): void {
            $failed = true;
        });

        $this->assertFalse($failed);
    }
}
