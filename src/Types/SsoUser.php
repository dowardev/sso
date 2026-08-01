<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Types;

use JsonSerializable;

class SsoUser implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly ?string $userName = null,
        public readonly array $roles = [],
        public readonly bool $isAdmin = false,
        public readonly bool $dpopVerified = false,
    ) {}

    public function hasRole(string $roleSlug): bool
    {
        foreach ($this->roles as $role) {
            if (isset($role['slug']) && $role['slug'] === $roleSlug) {
                return true;
            }
            if (isset($role['name']) && strtolower($role['name']) === strtolower($roleSlug)) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'user' => $this->userName,
            'roles' => $this->roles,
            'is_admin' => $this->isAdmin,
            'dpop_verified' => $this->dpopVerified,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
