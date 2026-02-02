<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $data = is_array($data) ? sanitize_array($data) : [];
    $userId = v_int($data['user_id'] ?? null, 'user id', 1, 2147483647, false) ?? 0;
    $email = v_email($data['email'] ?? null, 'email', false);
    $status = v_int($data['status'] ?? 1, 'status', 0, 1);

    if ($userId <= 0 && ($email === null || $email === '')) {
        throw new RuntimeException('User id or email required.');
    }
    $email = $email ?? '';

    $db = new Database();
    $pdo = $db->getPdo();

    if ($userId <= 0 && $email !== '') {
        $lookup = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $lookup->execute([':email' => $email]);
        $userId = (int)$lookup->fetchColumn();
        if ($userId <= 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id");
    $stmt->execute([
        ':status' => $status,
        ':id' => $userId,
    ]);

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT status FROM users WHERE id = :id");
        $check->execute([':id' => $userId]);
        $existingStatus = $check->fetchColumn();
        if ($existingStatus === false) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'status' => $status,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
