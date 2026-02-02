<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

requireLogin();
requireRole(['customer', 'premium_customer']);
requireCsrf();

$db = new Database();
$pdo = $db->getPdo();
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE users SET status = 0 WHERE id = ?");
    $stmt->execute([$userId]);

    session_unset();
    session_destroy();

    echo json_encode(['success' => true, 'message' => 'Account deactivated successfully.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
