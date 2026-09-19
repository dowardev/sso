<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Support;

use App\Models\SsoClient;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for the origins the dynamic CORS layer honours for a
 * given client. The OAuth redirect validation consumes it too, so the check and
 * the browser-level decision can never disagree about the same allowlist (same
 * cache, same frontend append).
 *
 * The cache is invalidated by SsoClientObserver on client create/update/delete.
 */
class CorsOrigins
{
    /**
     * @return array<int, string>
     */
    public static function forClient(?string $clientId): array
    {
        $cacheKey = 'sso_cors_origins_'.($clientId ?? '__sso__');

        $origins = Cache::remember($cacheKey, 30, function () use ($clientId) {
            $query = SsoClient::where('active', true)->whereNotNull('allowed_origins');

            if ($clientId) {
                $query->where('client_id', $clientId);
            }

            $clients = $query->get(['allowed_origins']);

            $origins = [];
            foreach ($clients as $client) {
                $origins = array_merge($origins, $client->allowed_origins ?? []);
            }

            return array_values(array_unique($origins));
        });

        $frontendUrl = config('app.frontend_url') ?? config('app.url');

        if (is_string($frontendUrl) && $frontendUrl !== '') {
            $origins[] = $frontendUrl;
        }

        return $origins;
    }
}
