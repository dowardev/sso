<?php

namespace TuOrg\SsoServer\Client;

use TuOrg\SsoServer\Auth\RoleChecker;
use TuOrg\SsoServer\Auth\SsoUserResolver;
use TuOrg\SsoServer\Dpop\DpopVerifier;
use TuOrg\SsoServer\Exceptions\DpopVerificationException;
use TuOrg\SsoServer\Exceptions\SsoAuthenticationException;
use TuOrg\SsoServer\Jwt\JwtValidator;
use TuOrg\SsoServer\Types\SsoConfig;
use TuOrg\SsoServer\Types\SsoUser;

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
            if (!$dpopProof) {
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
