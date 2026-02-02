<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$csrfToken = $_SESSION['csrf_token'] ?? issueCsrfToken();

echo json_encode([
    'success' => true,
    'csrf_token' => $csrfToken
]);
