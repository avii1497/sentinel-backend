<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/config/jwt.php';

header('Content-Type: application/json');

// Get Authorization header
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

    if (($decoded->role ?? '') !== 'admin') {
        throw new Exception('Invalid role');
    }

} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired admin token'
    ]);
    exit;
}
