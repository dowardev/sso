<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Exceptions;

use RuntimeException;

class JwksFetchException extends RuntimeException
{
    protected string $errorCode = 'jwks_fetch_failed';

    protected int $statusCode = 503;

    public function __construct(string $message, int $statusCode = 503, string $errorCode = 'jwks_fetch_failed')
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
    }

    public static function unreachable(string $ssoUrl): self
    {
        return new self("No se pudo obtener la clave pública del SSO desde: {$ssoUrl}", 503);
    }

    public static function emptyJwks(): self
    {
        return new self('El endpoint JWKS no contiene claves', 503);
    }

    public static function unknownKid(string $kid): self
    {
        // Unknown kid is an authentication / client-side failure (the token
        // referenced a key the SSO server never published), not a JWKS
        // transport failure. The other factories use 5xx because they signal
        // the SSO server itself is unreachable or returning an empty key set;
        // this one is closer to a bad request, so we surface 400.
        // errorCode is 'unknown_kid' (not the inherited 'jwks_fetch_failed')
        // so consumers can distinguish a missing-key reference from a transport failure.
        return new self("Unknown kid: {$kid}", 400, 'unknown_kid');
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
