<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace Database\Factories;

use App\Models\SsoClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SsoClient>
 */
class SsoClientFactory extends Factory
{
    protected $model = SsoClient::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'client_id' => Str::random(24),
            'client_secret' => Str::random(48),
            'redirect_url' => fake()->url(),
            'allowed_origins' => ['http://localhost:5173'],
            'grant_types' => ['authorization_code'],
            'active' => true,
        ];
    }
}
