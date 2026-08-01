<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Exceptions;

use RuntimeException;

class JwksFetchException extends RuntimeException
{
    protected string $errorCode = 'jwks_fetch_failed';

    protected int $statusCode = 503;

    public static function unreachable(string $ssoUrl): self
    {
        return new self("No se pudo obtener la clave pública del SSO desde: {$ssoUrl}", 503);
    }

    public static function emptyJwks(): self
    {
        return new self('El endpoint JWKS no contiene claves', 503);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
