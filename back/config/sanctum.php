<?php

// SPDX-License-Identifier: AGPL-3.0-only

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

/*
 | LBDS-252 + Sanctum guard: do NOT register
 | `Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful`
 | in this project's middleware groups or route chains. Its
 | `configureSecureCookieSessions()` hardcodes `session.same_site => 'lax'`
 | at runtime, which would silently override the LBDS-252 'strict' default
 | on every first-party stateful request and re-open the CSRF/cookie-theft
 | hole. Every refresh_token emission in this app (`AuthController` +
 | central `refreshTokenCookie()` helper) reads the SameSite flag from
 | `config/session.*` LAZILY at emit time; a Sanctum runtime mutation
 | would defeat that without any error.
 |
 | Sanctum is intentionally kept as a PersonalAccessToken data model only
 | (`HasApiTokens` on `User`, `PersonalAccessToken` relation on
 | `UserSession`); it does NOT authenticate requests here.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => env('SANCTUM_STATEFUL_DOMAINS')
            ? explode(',', env('SANCTUM_STATEFUL_DOMAINS'))
            : array_values(array_filter(array_merge(
                ['localhost', 'localhost:3000', '127.0.0.1', '127.0.0.1:8000', '::1'],
                [parse_url(config('app.url'), PHP_URL_HOST) ?: null],
                config('app.frontend_url')
                    ? [parse_url(config('app.frontend_url'), PHP_URL_HOST) ?: null]
                    : []
            ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
