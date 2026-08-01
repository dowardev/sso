<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Dowardev\SsoServer\Client;

use Dowardev\SsoServer\Auth\RoleChecker;
use Dowardev\SsoServer\Auth\SsoUserResolver;
use Dowardev\SsoServer\Dpop\DpopVerifier;
use Dowardev\SsoServer\Exceptions\SsoAuthenticationException;
use Dowardev\SsoServer\Jwt\JwtValidator;
use Dowardev\SsoServer\Types\SsoConfig;
use Dowardev\SsoServer\Types\SsoUser;

class SsoServerClient
{
    private ?SsoUser $currentUser = null;

    public function __construct(
        private readonly SsoConfig $config,
        private readonly JwtValidator $jwtValidator,
        private readonly DpopVerifier $dpopVerifier,
        private readonly SsoUserResolver $userResolver,
        private readonly RoleChecker $roleChecker,
    ) {}

    public function authenticateRequest(
        string $bearerToken,
        ?string $dpopProof,
        string $method,
        string $uri,
        bool $requireDpop = false,
    ): SsoUser {
        $parsedToken = $this->jwtValidator->validate($bearerToken);

        $cnf = $this->jwtValidator->getClaim($parsedToken, 'cnf');
        $jkt = null;
        if ($cnf !== null && isset($cnf['jkt'])) {
            $jkt = $cnf['jkt'];
        }

        if ($jkt !== null) {
            if (! $dpopProof) {
                throw SsoAuthenticationException::dpopRequired();
            }

            $this->dpopVerifier->verify($dpopProof, $bearerToken, $jkt, $method, $uri);
        } elseif ($requireDpop) {
            throw SsoAuthenticationException::dpopRequired();
        }

        $userClaim = $this->jwtValidator->getClaim($parsedToken, 'user');
        $userId = $this->jwtValidator->getClaim($parsedToken, 'sub');

        $roles = $this->userResolver->resolve((string) $userId, $bearerToken);
        $isAdmin = $this->roleChecker->isAdmin($roles);

        $this->currentUser = new SsoUser(
            id: (string) $userId,
            name: $userClaim['name'] ?? null,
            email: $userClaim['email'] ?? null,
            userName: $userClaim['user'] ?? null,
            roles: $roles,
            isAdmin: $isAdmin,
            dpopVerified: $jkt !== null,
        );

        return $this->currentUser;
    }

    public function getUser(): ?SsoUser
    {
        return $this->currentUser;
    }

    public function getId(): ?string
    {
        return $this->currentUser?->id;
    }

    public function isAuthenticated(): bool
    {
        return $this->currentUser !== null;
    }

    public function getRoles(): array
    {
        return $this->currentUser?->roles ?? [];
    }

    public function hasRole(string $roleSlug): bool
    {
        if ($this->currentUser === null) {
            return false;
        }

        if ($roleSlug === 'admin') {
            return $this->currentUser->isAdmin;
        }

        return $this->roleChecker->hasRole($this->currentUser->roles, $roleSlug);
    }

    public function isDpopVerified(): bool
    {
        return $this->currentUser?->dpopVerified ?? false;
    }

    public function clearUser(): void
    {
        $this->currentUser = null;
    }

    public function user(): ?SsoUser
    {
        return $this->currentUser;
    }

    public function id(): ?string
    {
        return $this->currentUser?->id;
    }

    public function roles(): array
    {
        return $this->currentUser?->roles ?? [];
    }

    public function dpopVerified(): bool
    {
        return $this->currentUser?->dpopVerified ?? false;
    }
}
