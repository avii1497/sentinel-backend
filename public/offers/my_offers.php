<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

try {
    if (
        empty($_SESSION['user_id']) ||
        !in_array($_SESSION['role'], ['customer', 'premium_customer'], true)
    ) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $customer_id = (int) $_SESSION['user_id'];

    $pdo = (new Database())->getPdo();

    $stmt = $pdo->prepare("
        SELECT 
            o.id AS offer_id,
            o.property_id,
            o.offer_price,
            o.status AS offer_status,
            o.created_at,

            p.title,
            p.price AS listed_price,

            r.id AS reservation_id,
            r.reservation_status,
            r.payment_status,
            r.decision_status,

            m.status AS meeting_status

        FROM property_offers o
        JOIN properties p 
            ON p.id = o.property_id

        LEFT JOIN property_reservations r
            ON r.offer_id = o.id
            AND r.customer_id = o.customer_id

        LEFT JOIN meetings m
            ON m.reservation_id = r.id

        WHERE o.customer_id = ?
        ORDER BY o.created_at DESC
    ");

    $stmt->execute([$customer_id]);

    echo json_encode([
        'success' => true,
        'offers'  => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server error'
    ]);
}
