<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

requireLogin();
requireRole(['customer', 'premium_customer']);

$db = new Database();
$pdo = $db->getPdo();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        u.id AS user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.role,
        u.is_premium,
        c.id AS customer_id,
        c.profile_photo,
        c.preferred_city,
        c.budget_min,
        c.budget_max,
        c.notes
    FROM users u
    LEFT JOIN customers c ON c.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$row = $stmt->fetch();

$csrfToken = $_SESSION['csrf_token'] ?? issueCsrfToken();

if (!$row) {
    echo json_encode([
        'success' => false,
        'error' => 'Profile not found.',
        'csrf_token' => $csrfToken
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'profile' => [
        'first_name' => $row['first_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'email' => $row['email'] ?? '',
        'phone' => $row['phone'] ?? '',
        'profile_photo' => $row['profile_photo'] ?? '',
        'preferred_city' => $row['preferred_city'] ?? '',
        'budget_min' => $row['budget_min'] ?? '',
        'budget_max' => $row['budget_max'] ?? '',
        'notes' => $row['notes'] ?? '',
    ],
    'csrf_token' => $csrfToken
]);
