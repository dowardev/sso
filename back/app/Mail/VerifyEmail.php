<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class VerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verificationUrl;

    /**
     * Local logo -> data URI (inline base64) so it renders in every mail
     * client without external fetches. External URLs (https://cdn...) pass
     * through unchanged. Null when no logo is configured.
     */
    public ?string $logoSrc;

    public function __construct(string $plainToken)
    {
        $this->verificationUrl = rtrim((string) config('app.frontend_url'), '/').'/verify/'.$plainToken;

        $logo = Setting::get('email_logo_url', '');
        $this->logoSrc = null;

        if (! is_string($logo) || $logo === '') {
            return;
        }

        if (preg_match('#^https?://#i', $logo)) {
            $this->logoSrc = $logo;

            return;
        }

        $diskPath = ltrim(preg_replace('#^/?storage/#', '', $logo), '/');
        $fullPath = Storage::disk('public')->path($diskPath);

        if (! file_exists($fullPath)) {
            return;
        }

        $extension = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => (string) (mime_content_type($fullPath) ?: 'image/png'),
        };
        $data = base64_encode((string) file_get_contents($fullPath));
        $this->logoSrc = "data:{$mime};base64,{$data}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verifica tu email',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
        );
    }
}