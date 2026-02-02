<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../config/jwt.php';

header('Content-Type: application/json');

$headers = getallheaders();
$auth = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Missing admin token'
    ]);
    exit;
}

$token = $matches[1];

try {
    $decoded = verifyAdminToken($token);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired admin token'
    ]);
    exit;
}

if (($decoded->role ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Admin role required'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'admin' => [
        'id' => (int)($decoded->admin_id ?? 0),
        'email' => (string)($decoded->email ?? ''),
        'role' => 'admin'
    ]
]);
