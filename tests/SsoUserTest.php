<?php

namespace Dowardev\SsoServer\Tests;

use Dowardev\SsoServer\Types\SsoUser;

class SsoUserTest extends TestCase
{
    public function test_constructor_sets_properties(): void
    {
        $user = new SsoUser(
            id: '123',
            name: 'John',
            email: 'john@test.com',
            userName: 'john',
            roles: [['slug' => 'admin']],
            isAdmin: true,
            dpopVerified: true,
        );

        $this->assertEquals('123', $user->id);
        $this->assertEquals('John', $user->name);
        $this->assertEquals('john@test.com', $user->email);
        $this->assertEquals('john', $user->userName);
        $this->assertTrue($user->isAdmin);
        $this->assertTrue($user->dpopVerified);
    }

    public function test_has_role_by_slug(): void
    {
        $user = new SsoUser(id: '1', roles: [['slug' => 'admin']]);
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_has_role_by_name(): void
    {
        $user = new SsoUser(id: '1', roles: [['name' => 'Administrador']]);
        $this->assertTrue($user->hasRole('administrador'));
    }

    public function test_has_role_not_found(): void
    {
        $user = new SsoUser(id: '1', roles: [['slug' => 'user']]);
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_to_array(): void
    {
        $user = new SsoUser(
            id: '1',
            name: 'Test',
            email: 'test@test.com',
            userName: 'test',
            roles: [],
            isAdmin: false,
            dpopVerified: true,
        );

        $array = $user->toArray();
        $this->assertEquals('1', $array['id']);
        $this->assertEquals('Test', $array['name']);
        $this->assertTrue($array['dpop_verified']);
    }

    public function test_default_values(): void
    {
        $user = new SsoUser(id: '1');
        $this->assertNull($user->name);
        $this->assertNull($user->email);
        $this->assertNull($user->userName);
        $this->assertEmpty($user->roles);
        $this->assertFalse($user->isAdmin);
        $this->assertFalse($user->dpopVerified);
    }
}
