<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserRefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefreshTokenRotationTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        return User::factory()->create([
            'email' => 'rotation@example.com',
            'password' => bcrypt('password123'),
        ]);
    }

    private function createRefreshToken(User $user, bool $rememberMe = false): string
    {
        $plain = Str::random(64);

        UserRefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
            'remember_me' => $rememberMe,
        ]);

        return $plain;
    }

    public function test_two_consecutive_refreshes_succeed(): void
    {
        $user = $this->createUser();
        $plain = $this->createRefreshToken($user);

        $first = $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ]);
        $first->assertOk();

        $rotatedPlain = $first->json('refresh_token');
        $this->assertNotSame($plain, $rotatedPlain);

        // A rotated token must remain usable — this is the remember-me regression.
        $second = $this->postJson('/api/refresh-token', [
            'refreshToken' => $rotatedPlain,
        ]);
        $second->assertOk()
            ->assertJsonStructure(['token', 'refresh_token']);
    }

    public function test_reuse_of_old_revoked_token_revokes_chain(): void
    {
        $user = $this->createUser();
        $plain = $this->createRefreshToken($user);

        $first = $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ]);
        $first->assertOk();
        $rotatedPlain = $first->json('refresh_token');

        // Simulate theft replay of the old token after the grace window.
        UserRefreshToken::where('token_hash', hash('sha256', $plain))
            ->update(['revoked_at' => now()->subSeconds(31)]);

        $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ])->assertUnauthorized();

        // Entire chain revoked — the rotated token must also be dead.
        $newToken = UserRefreshToken::where('token_hash', hash('sha256', $rotatedPlain))->first();
        $this->assertNotNull($newToken->revoked_at);

        $this->postJson('/api/refresh-token', [
            'refreshToken' => $rotatedPlain,
        ])->assertUnauthorized();
    }

    public function test_recent_rotation_race_does_not_revoke_chain(): void
    {
        $user = $this->createUser();
        $plain = $this->createRefreshToken($user);

        $first = $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ]);
        $first->assertOk();
        $rotatedPlain = $first->json('refresh_token');

        // The old token was revoked moments ago (rotation race between tabs).
        $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ])->assertUnauthorized();

        // Chain NOT revoked: the newer token still works.
        $newToken = UserRefreshToken::where('token_hash', hash('sha256', $rotatedPlain))->first();
        $this->assertNull($newToken->revoked_at);

        $this->postJson('/api/refresh-token', [
            'refreshToken' => $rotatedPlain,
        ])->assertOk();
    }

    public function test_race_replay_response_does_not_clear_cookie(): void
    {
        $user = $this->createUser();
        $plain = $this->createRefreshToken($user);

        $first = $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ]);
        $first->assertOk();

        // Losing tab replays the just-rotated token: 401, but the response
        // MUST NOT clear the refresh_token cookie — the winner's newer
        // cookie in the browser jar must survive.
        $loser = $this->postJson('/api/refresh-token', [
            'refreshToken' => $plain,
        ]);
        $loser->assertUnauthorized();

        $cleared = collect($loser->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'refresh_token' && ($c->getValue() === null || $c->getValue() === '' || $c->getExpiresTime() < time()));
        $this->assertNull($cleared, 'race-losing 401 must not clear the refresh_token cookie');
    }

    public function test_remember_me_sets_persistent_cookie_and_rotation_preserves_it(): void
    {
        $user = $this->createUser();

        // remember_me = true → persistent cookie
        $login = $this->postJson('/api/login', [
            'email' => 'rotation@example.com',
            'password' => 'password123',
            'remember_me' => true,
        ]);
        $login->assertOk();

        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === 'refresh_token');
        $this->assertNotNull($cookie);
        $this->assertGreaterThan(time() + 3000, $cookie->getExpiresTime(),
            'remember_me refresh_token cookie must be persistent');

        // Rotation keeps the cookie persistent.
        $rotated = $this->postJson('/api/refresh-token', [
            'refreshToken' => $cookie->getValue(),
        ]);
        $rotated->assertOk();

        $rotatedCookie = collect($rotated->headers->getCookies())->first(fn ($c) => $c->getName() === 'refresh_token');
        $this->assertNotNull($rotatedCookie);
        $this->assertGreaterThan(time() + 3000, $rotatedCookie->getExpiresTime(),
            'rotated remember_me cookie must stay persistent');

        $user->refresh();
        $rotatedToken = UserRefreshToken::where('token_hash', hash('sha256', $rotatedCookie->getValue()))->first();
        $this->assertTrue($rotatedToken->remember_me);
    }

    public function test_session_login_uses_session_cookie_and_rotation_stays_session(): void
    {
        $this->createUser();

        $login = $this->postJson('/api/login', [
            'email' => 'rotation@example.com',
            'password' => 'password123',
        ]);
        $login->assertOk();

        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === 'refresh_token');
        $this->assertNotNull($cookie);
        $this->assertEquals(0, $cookie->getExpiresTime(),
            'non-remember-me refresh_token cookie must be a session cookie');

        $rotated = $this->postJson('/api/refresh-token', [
            'refreshToken' => $cookie->getValue(),
        ]);
        $rotated->assertOk();

        $rotatedCookie = collect($rotated->headers->getCookies())->first(fn ($c) => $c->getName() === 'refresh_token');
        $this->assertNotNull($rotatedCookie);
        $this->assertEquals(0, $rotatedCookie->getExpiresTime(),
            'rotated session cookie must stay a session cookie');

        $rotatedToken = UserRefreshToken::where('token_hash', hash('sha256', $rotatedCookie->getValue()))->first();
        $this->assertFalse($rotatedToken->remember_me);
    }
}
