<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->token = $response->json('token');
    }

    public function test_login_creates_activity_log(): void
    {
        $log = UserActivityLog::where('user_id', $this->user->id)
            ->where('action', UserActivityLog::ACTION_LOGIN)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Inicio de sesión', $log->description);
    }

    public function test_activity_log_endpoint_returns_paginated_data(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/user/activity-log');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'action', 'description', 'created_at', 'metadata'],
                ],
                'current_page',
                'last_page',
                'total',
            ]);

        $this->assertGreaterThanOrEqual(1, $response->json('total'));
    }

    public function test_activity_log_requires_auth(): void
    {
        $this->getJson('/api/user/activity-log')
            ->assertUnauthorized();
    }

    public function test_logout_creates_activity_log(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/logout');

        $log = UserActivityLog::where('user_id', $this->user->id)
            ->where('action', UserActivityLog::ACTION_LOGOUT)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Cierre de sesión', $log->description);
    }
}
