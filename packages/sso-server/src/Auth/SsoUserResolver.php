<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Auth;

use Dowardev\SsoServer\Exceptions\SsoUserResolveException;
use Dowardev\SsoServer\Http\SsoHttpClient;
use Dowardev\SsoServer\Types\SsoConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SsoUserResolver
{
    public function __construct(
        private readonly SsoConfig $config,
        private readonly SsoHttpClient $httpClient,
    ) {}

    public function resolve(string $userId, string $token): array
    {
        $cached = $this->getCachedRoles($userId);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $userData = $this->httpClient->get('/me', $token);

            if ($userData && isset($userData['roles'])) {
                $this->cacheRoles($userId, $userData['roles']);

                return $userData['roles'];
            }
        } catch (SsoUserResolveException $e) {
            Log::warning('SSO user resolution failed: '.$e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            Log::warning('Failed to fetch user roles from SSO: '.$e->getMessage());
        }

        $stale = $this->getStaleRoles($userId);
        if ($stale !== null) {
            Log::warning("Using stale roles cache for user {$userId}");

            return $stale;
        }

        throw SsoUserResolveException::noRolesAvailable($userId);
    }

    private function cacheRoles(string $userId, array $roles): void
    {
        Cache::put('sso_user_roles:'.$userId, $roles, $this->config->rolesCacheTtl);
        Cache::put(
            'sso_user_roles_stale:'.$userId,
            $roles,
            $this->config->rolesCacheTtl * 6,
        );
    }

    private function getCachedRoles(string $userId): ?array
    {
        return Cache::get('sso_user_roles:'.$userId);
    }

    private function getStaleRoles(string $userId): ?array
    {
        return Cache::get('sso_user_roles_stale:'.$userId);
    }
}
