<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
        throw new Exception("Unauthorized");
    }

    $status = strtolower(trim($_GET['status'] ?? 'accepted'));
    $allowed = ['accepted', 'rejected', 'all'];
    if (!in_array($status, $allowed, true)) {
        $status = 'accepted';
    }

    $pdo = (new Database())->getPdo();

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

    $sql = "
        SELECT
            o.id AS offer_id,
            o.status AS offer_status,
            o.offer_price,
            o.message AS offer_message,
            o.created_at AS offer_created_at,

            p.id AS property_id,
            p.title AS property_title,
            p.location AS property_location,

            u.id AS customer_id,
            CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
            u.email AS customer_email,

            r.id AS reservation_id,
            r.payment_status AS reservation_payment_status,
            r.reservation_status AS reservation_status,
            r.expires_at AS reservation_expires_at,
            r.cancelled_at AS reservation_cancelled_at
        FROM property_offers o
        JOIN properties p ON p.id = o.property_id
        JOIN owners ow ON ow.id = p.owner_id
        JOIN users u ON u.id = o.customer_id
        LEFT JOIN property_reservations r ON r.offer_id = o.id
        WHERE ow.id = ?
    ";

    $params = [$owner_id];

    if ($status === 'all') {
        $sql .= " AND o.status IN ('accepted','rejected') ";
    } else {
        $sql .= " AND o.status = ? ";
        $params[] = $status;
    }

    $sql .= " ORDER BY o.created_at DESC ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

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
