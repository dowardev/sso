<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Exceptions;

use RuntimeException;

class DpopVerificationException extends RuntimeException
{
    protected string $errorCode = 'dpop_verification_failed';

    protected int $statusCode = 401;

    public static function invalidType(): self
    {
        return new self('Tipo de token DPoP inválido', 401);
    }

    public static function unsupportedAlgorithm(string $alg): self
    {
        return new self("Algoritmo DPoP no soportado: {$alg}", 401);
    }

    public static function missingJwk(): self
    {
        return new self('Falta la clave pública JWK en el header DPoP', 401);
    }

    public static function incompleteJwk(): self
    {
        return new self('JWK del DPoP proof incompleto: faltan campos requeridos', 401);
    }

    public static function thumbprintMismatch(): self
    {
        return new self('Huella digital JWK del DPoP proof no coincide', 401);
    }

    public static function invalidSignature(): self
    {
        return new self('Firma del DPoP proof inválida', 401);
    }

    public static function missingClaims(): self
    {
        return new self('DPoP proof incompleto: faltan claims requeridos (htm, htu, iat, jti, ath)', 401);
    }

    public static function methodMismatch(): self
    {
        return new self('Método HTTP no coincide en DPoP proof', 401);
    }

    public static function uriMismatch(): self
    {
        return new self('URI HTTP no coincide en DPoP proof', 401);
    }

    public static function timestampOutOfRange(): self
    {
        return new self('Timestamp del DPoP proof fuera de rango', 401);
    }

    public static function accessTokenHashMismatch(): self
    {
        return new self('Hash del token de acceso no coincide', 401);
    }

    public static function replayDetected(): self
    {
        return new self('DPoP proof reutilizado (replay detectado)', 401);
    }

    public static function invalidProof(string $reason): self
    {
        return new self("DPoP proof inválido: {$reason}", 401);
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
