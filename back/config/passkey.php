<?php

// SPDX-License-Identifier: AGPL-3.0-only

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party ID
    |--------------------------------------------------------------------------
    |
    | The effective domain for WebAuthn credentials. Must be a registrable
    | domain suffix of the origin the browser uses. Defaults to the host of
    | app.url.
    |
    */

    'rp_id' => env('PASSKEY_RP_ID', parse_url(env('APP_URL', 'http://localhost:8001'), PHP_URL_HOST) ?: 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | Relying Party Name
    |--------------------------------------------------------------------------
    */

    'rp_name' => env('PASSKEY_RP_NAME', env('APP_NAME', 'SSO Server')),

    /*
    |--------------------------------------------------------------------------
    | Challenge timeout (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => env('PASSKEY_TIMEOUT', 60),

];
