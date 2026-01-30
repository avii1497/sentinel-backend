<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

try {
    if (
        empty($_SESSION['user_id']) ||
        !in_array($_SESSION['role'], ['customer', 'premium_customer'], true)
    ) {
        throw new Exception("Unauthorized");
    }

    $customer_id = (int)$_SESSION['user_id'];

    $db = new Database();
    $pdo = $db->getPdo();

    $stmt = $pdo->prepare("
        SELECT r.*, p.title, p.location, p.status AS property_status
        FROM property_reservations r
        JOIN properties p ON p.id = r.property_id
        WHERE r.customer_id = ?
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customer_id]);

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
