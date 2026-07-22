<?php
// SPDX-License-Identifier: MIT

namespace TuOrg\SsoServer\Facades;

use Illuminate\Support\Facades\Facade;
use TuOrg\SsoServer\Types\SsoUser;

/**
 * @method static SsoUser|null user()
 * @method static string|null id()
 * @method static bool isAuthenticated()
 * @method static array roles()
 * @method static bool hasRole(string $roleSlug)
 * @method static bool dpopVerified()
 * @see \TuOrg\SsoServer\Client\SsoServerClient
 */
class SsoAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'sso-server-client';
    }
}
