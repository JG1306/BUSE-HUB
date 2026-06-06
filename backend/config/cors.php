<?php
declare(strict_types=1);

/**
 * Allow Next.js dev origins on localhost and private LAN IPs (any port).
 */
function is_allowed_origin(string $origin): bool
{
    return (bool) preg_match(
        '#^https?://'
        . '(localhost|127\.0\.0\.1'
        . '|192\.168\.\d{1,3}\.\d{1,3}'
        . '|10\.\d{1,3}\.\d{1,3}\.\d{1,3}'
        . '|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})'
        . '(:\d+)?$#',
        $origin
    );
}

function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin !== '' && is_allowed_origin($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Credentials: true');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

apply_cors();
