<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';


header("Content-Type: application/json");

requireLogin();
requireRole('owner');

$pdo = (new Database())->getPdo();

$stmt = $pdo->prepare("
    SELECT 
    r.id AS reservation_id,
    r.payment_status,
    r.decision_status,
    r.reservation_fee,
    r.created_at,

    p.title AS property_title,

    u.first_name,
    u.last_name,

    CASE
        WHEN m.id IS NULL THEN 'NOT_SCHEDULED'
        ELSE m.status
    END AS meeting_status

FROM property_reservations r
JOIN properties p ON p.id = r.property_id
JOIN users u ON u.id = r.customer_id

LEFT JOIN meetings m
    ON m.property_id = r.property_id
   AND m.owner_id = p.owner_id
   AND m.status IN ('accepted','completed')

WHERE p.owner_id = ?
ORDER BY r.created_at DESC;

");

$stmt->execute([$_SESSION['owner_id']]);

echo json_encode([
    "success" => true,
    "reservations" => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
