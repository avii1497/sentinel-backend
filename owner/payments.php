<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_log("SESSION USER_ID = " . $_SESSION['user_id']);

try {
    if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
        throw new Exception("Unauthorized");
    }

    $pdo = (new Database())->getPdo();

    // 🔁 USER → OWNER
    $stmtOwner = $pdo->prepare("
        SELECT id
        FROM owners
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmtOwner->execute([$_SESSION['user_id']]);
    $owner = $stmtOwner->fetch(PDO::FETCH_ASSOC);

    if (!$owner) {
        throw new Exception("Owner record not found");
    }

    $owner_id = (int)$owner['id'];

   
    $stmt = $pdo->prepare("
        SELECT
            pay.id         AS payment_id,
            pay.amount,
            pay.currency,
            pay.method,
            pay.status,
            pay.created_at AS paid_at,

            pr.id          AS reservation_id,
            p.id           AS property_id,
            p.title        AS property_title,

            u.first_name   AS customer_name,
            u.email        AS customer_email

        FROM payments pay
        JOIN property_reservations pr ON pr.id = pay.reference_id
        JOIN properties p ON p.id = pr.property_id
        JOIN owners ow ON ow.id = p.owner_id
        JOIN users u ON u.id = pr.customer_id

        WHERE pay.type = 'property_reservation'
          AND pay.status = 'paid'
          AND ow.id = ?

        ORDER BY pay.created_at DESC
    ");

    $stmt->execute([$owner_id]);

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
