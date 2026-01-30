<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
        throw new Exception('Unauthorized');
    }

    $customer_id = (int)$_SESSION['customer_id'];

    $db = new Database();
    $pdo = $db->getPdo();

    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.property_id,
            p.title,
            p.location,
            p.price AS listed_price,
            o.offer_price,
            o.message,
            o.status,
            o.created_at
        FROM property_offers o
        JOIN properties p ON p.id = o.property_id
        WHERE o.customer_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$customer_id]);

    echo json_encode([
        'success' => true,
        'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
