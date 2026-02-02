<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy bookings listing endpoint.
// Safe to remove after: 2026-06-30 (customer bookings now use /customer/*).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
session_start();

try {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception("Not authenticated");
    }

    $pdo = (new Database())->getPdo();

    // Resolve customer id from logged-in user
    $cStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
    $cStmt->execute([$_SESSION['user_id']]);
    $customerId = (int)$cStmt->fetchColumn();
    if ($customerId <= 0) {
        http_response_code(403);
        throw new Exception("Not a customer");
    }

    $stmt = $pdo->prepare("
        SELECT
            rb.*,
            p.title,
            p.location,
            p.image_url
        FROM rental_bookings_backup rb
        JOIN properties p ON rb.property_id = p.id
        WHERE rb.tenant_id = ?
        ORDER BY rb.created_at DESC
    ");
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
