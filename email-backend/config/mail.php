<?php

declare(strict_types=1);

/**
 * Application settings, read from .env. Edit .env rather than this file.
 * Env::load() must have run first.
 */

// Exact origins. "null" is dropped. A lone "*" in ALLOWED_ORIGINS allows any site.
$allowedOrigins = [];
foreach (explode(',', Env::get('ALLOWED_ORIGINS')) as $origin) {
    $origin = strtolower(rtrim(trim($origin), '/'));
    if ($origin !== '' && $origin !== 'null') {
        $allowedOrigins[] = $origin;
    }
}

return [
    'smtp' => [
        'host' => Env::get('SMTP_HOST'),
        'port' => (int) Env::get('SMTP_PORT', '587'),
        'username' => Env::get('SMTP_USERNAME'),
        'password' => Env::get('SMTP_PASSWORD'),
        'encryption' => strtolower(Env::get('SMTP_ENCRYPTION', 'tls')),
    ],
    'from' => [
        'address' => Env::get('MAIL_FROM_ADDRESS'),
        'name' => Env::get('MAIL_FROM_NAME', 'Website Enquiries'),
    ],
    'allowed_origins' => $allowedOrigins,
    'rate_limit' => [
        'max' => max(1, (int) Env::get('RATE_LIMIT_MAX', '5')),
        'window' => max(1, (int) Env::get('RATE_LIMIT_WINDOW_SECONDS', '900')),
    ],
];
