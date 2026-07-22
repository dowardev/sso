<?php
// SPDX-License-Identifier: MIT

namespace TuOrg\SsoServer\Exceptions;

use RuntimeException;

class SsoAuthenticationException extends RuntimeException
{
    protected string $errorCode = 'sso_authentication_failed';

    protected int $statusCode = 401;

    public static function tokenMissing(): self
    {
        return new self('Token no proporcionado', 401);
    }

    public static function tokenInvalid(?string $reason = null): self
    {
        return new self(
            $reason ? "Token inválido: {$reason}" : 'Token inválido',
            401
        );
    }

    public static function dpopRequired(): self
    {
        return new self('DPoP proof requerido para este token', 401);
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
