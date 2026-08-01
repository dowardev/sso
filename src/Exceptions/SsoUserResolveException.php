<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Exceptions;

use RuntimeException;
use Throwable;

class SsoUserResolveException extends RuntimeException
{
    public function __construct(string $message = '', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function noRolesAvailable(string $userId): self
    {
        return new self(
            "Cannot resolve roles for user {$userId}: SSO /me failed and no stale cache available"
        );
    }
}
