<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Facades;

use Dowardev\SsoServer\Client\SsoServerClient;
use Dowardev\SsoServer\Types\SsoUser;
use Illuminate\Support\Facades\Facade;

/**
 * @method static SsoUser|null user()
 * @method static string|null id()
 * @method static bool isAuthenticated()
 * @method static array roles()
 * @method static bool hasRole(string $roleSlug)
 * @method static bool dpopVerified()
 *
 * @see SsoServerClient
 */
class SsoAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'sso-server-client';
    }
}
