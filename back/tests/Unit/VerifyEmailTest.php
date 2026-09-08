<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Unit;

use App\Mail\VerifyEmail;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifyEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_mailable_exposes_verification_url_and_renders(): void
    {
        config(['app.frontend_url' => 'https://app.example.com']);

        $mailable = new VerifyEmail('abc123');

        $this->assertSame('https://app.example.com/verify/abc123', $mailable->verificationUrl);

        $html = $mailable->render();
        $this->assertStringContainsString('https://app.example.com/verify/abc123', $html);
        $this->assertStringContainsString('Verificar email', $html);
    }

    public function test_mailable_exposes_null_logo_when_not_configured(): void
    {
        $mailable = new VerifyEmail('abc123');

        $this->assertNull($mailable->logoSrc);
    }

    public function test_mailable_embeds_local_logo_as_data_uri(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/email-logo.png', 'fake-png-content');
        Cache::put('setting:email_logo_url', new Setting([
            'key' => 'email_logo_url',
            'value' => '/storage/branding/email-logo.png',
            'type' => 'string',
        ]), 3600);

        $mailable = new VerifyEmail('abc123');

        $this->assertNotNull($mailable->logoSrc);
        $this->assertStringStartsWith('data:image/', $mailable->logoSrc);
        $this->assertStringContainsString(';base64,', $mailable->logoSrc);
    }

    public function test_mailable_keeps_external_logo_url(): void
    {
        Cache::put('setting:email_logo_url', new Setting([
            'key' => 'email_logo_url',
            'value' => 'https://cdn.example.com/logo.png',
            'type' => 'string',
        ]), 3600);

        $mailable = new VerifyEmail('abc123');

        $this->assertSame('https://cdn.example.com/logo.png', $mailable->logoSrc);
    }

    public function test_mailable_render_includes_logo_when_configured(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/email-logo.png', 'fake-png-content');
        Cache::put('setting:email_logo_url', new Setting([
            'key' => 'email_logo_url',
            'value' => '/storage/branding/email-logo.png',
            'type' => 'string',
        ]), 3600);

        $html = (new VerifyEmail('abc123'))->render();

        $this->assertStringContainsString('src="data:image/', $html);
    }
}
