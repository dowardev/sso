<?php

// SPDX-License-Identifier: AGPL-3.0-only

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'DPoP', 'DPoP-Public-Key', 'Accept'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => true,
];
