<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = (int)($data['user_id'] ?? 0);
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $status = isset($data['status']) ? (int)$data['status'] : 1;

    if ($userId <= 0 && $email === '') {
        throw new RuntimeException('User id or email required.');
    }
    if (!in_array($status, [0, 1], true)) {
        throw new RuntimeException('Invalid status. Use 0 or 1.');
    }

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
