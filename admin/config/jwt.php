<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/* ==========================
   ADMIN JWT CONFIG
========================== */

define('ADMIN_JWT_SECRET', getenv('ADMIN_JWT_SECRET') ?: 'SENTINEL_ADMIN_SECRET_2025');
define('ADMIN_JWT_TTL', (int)(getenv('ADMIN_JWT_TTL') ?: (60 * 60 * 6))); // 6 hours

/* ==========================
   TOKEN CREATION
========================== */

function createAdminToken(array $admin): string
{
    $payload = [
        'admin_id' => $admin['id'],
        'email'    => $admin['email'],
        'role'     => 'admin',
        'iat'      => time(),
        'exp'      => time() + ADMIN_JWT_TTL,
    ];

    return JWT::encode($payload, ADMIN_JWT_SECRET, 'HS256');
}

/* ==========================
   TOKEN VERIFICATION
========================== */

function verifyAdminToken(string $token): object
{
    return JWT::decode($token, new Key(ADMIN_JWT_SECRET, 'HS256'));
}
