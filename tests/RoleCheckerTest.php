<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Auth\RoleChecker;
use Dowardev\SsoServer\Types\SsoConfig;

class RoleCheckerTest extends TestCase
{
    private RoleChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $config = SsoConfig::fromConfig();
        $this->checker = new RoleChecker($config);
    }

    public function test_is_admin_returns_true_for_admin_slug(): void
    {
        $roles = [['slug' => 'admin', 'name' => 'Administrador']];

        $this->assertTrue($this->checker->isAdmin($roles));
    }

    public function test_is_admin_returns_false_for_non_admin(): void
    {
        $roles = [['slug' => 'user', 'name' => 'Usuario']];

        $this->assertFalse($this->checker->isAdmin($roles));
    }

    public function test_is_admin_returns_false_for_empty_roles(): void
    {
        $this->assertFalse($this->checker->isAdmin([]));
    }

    public function test_has_role_checks_slug(): void
    {
        $roles = [['slug' => 'editor', 'name' => 'Editor']];

        $this->assertTrue($this->checker->hasRole($roles, 'editor'));
        $this->assertFalse($this->checker->hasRole($roles, 'admin'));
    }

    public function test_has_role_falls_back_to_name_comparison(): void
    {
        $roles = [['name' => 'Editor', 'slug' => 'custom-slug']];

        $this->assertTrue($this->checker->hasRole($roles, 'editor'));
    }

    public function test_has_role_with_configured_mapping(): void
    {
        $roles = [['slug' => 'admin', 'name' => 'Administrador']];

        $this->assertTrue($this->checker->hasRole($roles, 'admin'));
    }
}
