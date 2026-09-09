<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RedirectUri implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isAllowed($value)) {
            $fail('El campo Redirect URL debe ser una URL válida (http(s)://...) o un esquema personalizado (miapp://...).');
        }
    }

    public static function isAllowed(string $value): bool
    {
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (! is_string($scheme) || preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*$/', $scheme) !== 1) {
            return false;
        }

        if (in_array(strtolower($scheme), ['javascript', 'data', 'file', 'ftp', 'vbscript'], true)) {
            return false;
        }

        if (! str_contains($value, '://')) {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:\/\/\S+$/', $value) === 1;
    }
}
