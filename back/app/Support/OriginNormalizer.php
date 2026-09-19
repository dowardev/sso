<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Support;

class OriginNormalizer
{
    /**
     * Normalize an http(s) origin to its canonical scheme://host[:port] form.
     *
     * Returns null when the value carries no http(s) origin at all, so callers
     * can skip origin comparison instead of matching against an empty host.
     * Native app redirect URLs (e.g. "com.example.app://auth/callback") land
     * here on purpose: their token exchange never goes through CORS.
     */
    public static function normalize(?string $origin): ?string
    {
        if (! $origin) {
            return null;
        }

        $parsed = parse_url($origin);
        if ($parsed === false) {
            return null;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        $host = strtolower($parsed['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = $parsed['port'] ?? null;

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $port ? "{$scheme}://{$host}:{$port}" : "{$scheme}://{$host}";
    }

    /**
     * Normalize a list of origins, discarding entries that are not http(s).
     *
     * @param  array<int, string|null>  $origins
     * @return array<int, string>
     */
    public static function normalizeAll(array $origins): array
    {
        $normalized = [];

        foreach ($origins as $origin) {
            $value = self::normalize(is_string($origin) ? $origin : null);

            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Whether the given origin belongs to the allowed list, comparing
     * canonical forms so "https://host:443" and "https://host" match.
     *
     * @param  array<int, string|null>  $allowedOrigins
     */
    public static function matches(?string $origin, array $allowedOrigins): bool
    {
        $normalized = self::normalize($origin);

        if ($normalized === null) {
            return false;
        }

        return in_array($normalized, self::normalizeAll($allowedOrigins), true);
    }
}
