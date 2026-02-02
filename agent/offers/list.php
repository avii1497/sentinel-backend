<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

// ✅ ALWAYS start session before accessing $_SESSION
try {
    requireLogin();
    requireRole('agent');

    $agent_id = (int) $_SESSION['agent_id'];

    $db = new Database();
    $pdo = $db->getPdo();

    $stmt = $pdo->prepare("
        SELECT 
            o.id,
             o.property_id,   
            o.offer_price,
            o.status,
            o.created_at,
            p.title,
            p.price AS listed_price,
            u.first_name,
            u.last_name
        FROM property_offers o
        JOIN properties p ON p.id = o.property_id
        JOIN users u ON u.id = o.customer_id
        WHERE o.agent_id = ?
        ORDER BY o.created_at DESC
    ");

    $stmt->execute([$agent_id]);

    echo json_encode([
        'success' => true,
        'offers'  => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
    exit;
}
