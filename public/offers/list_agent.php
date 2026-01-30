<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'agent') {
        throw new Exception('Unauthorized');
    }

    $agent_id = (int)$_SESSION['agent_id'];
    $status   = $_GET['status'] ?? null;

    $db = new Database();
    $pdo = $db->getPdo();

    $sql = "
        SELECT 
            o.id,
            o.property_id,
            p.title,
            p.location,
            p.price AS listed_price,
            o.customer_id,
            CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
            u.email,
            o.offer_price,
            o.message,
            o.status,
            o.created_at
        FROM property_offers o
        JOIN properties p ON p.id = o.property_id
        JOIN users u ON u.id = o.customer_id
        WHERE o.agent_id = ?
    ";

    $params = [$agent_id];

    if ($status) {
        $sql .= " AND o.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY o.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

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
