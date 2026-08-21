<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmailVerificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_tokens_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('email_verification_tokens'));
        $this->assertTrue(Schema::hasColumns('email_verification_tokens', [
            'id', 'user_id', 'token_hash', 'expires_at', 'used_at', 'revoked_at', 'created_at',
        ]));
    }

    public function test_backfill_marks_existing_users_as_verified(): void
    {
        // Undo the migration so a user can exist before the backfill runs.
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_19_000000_create_email_verification_tokens_table.php',
        ]);

        $user = User::factory()->unverified()->create();
        $this->assertNull($user->fresh()->email_verified_at);

        // Re-run the migration: the backfill inside up() must verify the user.
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_08_19_000000_create_email_verification_tokens_table.php',
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at);

        // The rollback/migrate DDL implicitly commits on MySQL, so the
        // created user would leak into sibling tests; clean it up.
        $user->delete();
    }

    public function test_token_hash_column_is_unique(): void
    {
        $this->assertTrue(Schema::hasIndex('email_verification_tokens', 'email_verification_tokens_token_hash_unique'));
    }
}
