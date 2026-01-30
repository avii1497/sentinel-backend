<?php
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json");

try {
    $pdo = (new Database())->getPdo();

    // Fetch all distinct locations from the properties table
    $stmt = $pdo->prepare("
        SELECT DISTINCT location
        FROM properties p
        WHERE p.is_published = 1
          AND p.status IN ('Available','Pending')
          AND NOT EXISTS (
              SELECT 1
              FROM property_reservations pr_paid
              WHERE pr_paid.property_id = p.id
                AND pr_paid.cancelled_at IS NULL
                AND (pr_paid.reservation_status = 'PAID_CONFIRMED' OR pr_paid.payment_status = 'paid')
          )
    ");
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $locations
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
