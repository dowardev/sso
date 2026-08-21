{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifica tu email</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background-color:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding:36px 32px 8px;">
                            @if ($logoSrc)
                                <img src="{{ $logoSrc }}" alt="{{ \Illuminate\Support\Str::title(config('app.name')) }}" style="display:block;max-height:80px;width:auto;max-width:320px;border:0;outline:none;text-decoration:none;" />
                            @else
                                <span style="font-size:20px;font-weight:700;color:#111827;letter-spacing:0.5px;">{{ \Illuminate\Support\Str::title(config('app.name')) }}</span>
                            @endif
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:20px 40px 24px;">
                            <h2 style="margin:0 0 14px;color:#111827;font-size:22px;line-height:1.3;">Verifica tu email</h2>
                            <p style="margin:0 0 16px;color:#4b5563;font-size:15px;line-height:1.6;">Hola,</p>
                            <p style="margin:0 0 16px;color:#4b5563;font-size:15px;line-height:1.6;">
                                Para completar tu registro, confirma tu dirección de email haciendo clic en el botón de abajo.
                                El enlace es válido por <strong>30 minutos</strong>.
                            </p>
                            <!-- Bulletproof CTA -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 8px;">
                                <tr>
                                    <td align="center">
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="border-radius:8px;">
                                            <tr>
                                                <td align="center" style="border-radius:8px;background-color:#1a73e8;">
                                                    <a href="{{ $verificationUrl }}" style="display:inline-block;padding:13px 36px;background-color:#1a73e8;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;border-radius:8px;">Verificar email</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 24px;color:#6b7280;font-size:13px;line-height:1.6;">
                                Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
                                <span style="color:#1a73e8;word-break:break-all;">{{ $verificationUrl }}</span>
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:20px 40px 28px;background-color:#f9fafb;border-top:1px solid #e5e7eb;">
                            <p style="margin:0 0 8px;color:#6b7280;font-size:12px;line-height:1.6;">
                                Si no creaste esta cuenta, puedes ignorar este mensaje.
                            </p>
                            <p style="margin:0;color:#9ca3af;font-size:12px;">{{ \Illuminate\Support\Str::title(config('app.name')) }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>