<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Auth;

use Dowardev\SsoServer\Types\SsoConfig;

class RoleChecker
{
    public function __construct(
        private readonly SsoConfig $config,
    ) {}

    public function isAdmin(array $roles): bool
    {
        $adminMapping = $this->config->roleMappings['admin'] ?? ['slug' => 'admin'];

        foreach ($roles as $role) {
            if ($this->matchesMapping($role, $adminMapping)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(array $roles, string $roleSlug): bool
    {
        $mapping = $this->config->roleMappings[$roleSlug] ?? null;

        if ($mapping) {
            foreach ($roles as $role) {
                if ($this->matchesMapping($role, $mapping)) {
                    return true;
                }
            }
        }

        foreach ($roles as $role) {
            if (isset($role['slug']) && $role['slug'] === $roleSlug) {
                return true;
            }
            if (isset($role['name']) && strtolower($role['name']) === strtolower($roleSlug)) {
                return true;
            }
        }

        return false;
    }

    private function matchesMapping(array $role, array $mapping): bool
    {
        foreach ($mapping as $key => $value) {
            if (! isset($role[$key]) || $role[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
