<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/owner_guard.php';

header('Content-Type: application/json');

// Normalize stage
$stage = strtolower(trim($_GET['stage'] ?? ''));

// Valid stage conditions
$conditions = [
    // Pending approval
    'requests' => "
        rb.status = 'pending'
    ",

    // Approved but unpaid
    'approved' => "
        rb.status = 'accepted'
        AND COALESCE(rb.payment_status,'unpaid') = 'unpaid'
    ",

    // 🔜 UPCOMING (paid, not started yet)
    'upcoming' => "
        rb.status = 'accepted'
        AND rb.payment_status = 'paid'
        AND rb.checkin > CURDATE()
    ",

    // 🟢 ACTIVE (paid + ongoing)
    'active' => "
        rb.status IN ('accepted','ongoing')
        AND rb.payment_status = 'paid'
        AND rb.checkin <= CURDATE()
        AND rb.checkout >= CURDATE()
    ",

    // 🧾 HISTORY / PAST
    'history' => "
        rb.checkout < CURDATE()
        OR rb.status IN ('completed','rejected','cancelled')
    ",
];


if (!isset($conditions[$stage])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid stage'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            rb.id AS booking_id,
            rb.checkin,
            rb.checkout,
            rb.guests,
            rb.total_price,
            rb.rental_type,
            rb.status,
            COALESCE(rb.payment_status,'unpaid') AS payment_status,

            p.title    AS property_title,
            p.location AS property_location,

            u.first_name AS tenant_first_name,
            u.last_name  AS tenant_last_name,
            u.email      AS tenant_email

        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        LEFT JOIN customers c ON c.id = rb.tenant_id
        LEFT JOIN users u ON u.id = c.user_id

        WHERE 
            p.owner_id = :owner_id
            AND {$conditions[$stage]}

        ORDER BY rb.created_at DESC
    ");

    $stmt->execute([
        ':owner_id' => $OWNER_ID
    ]);

    echo json_encode([
        'success' => true,
        'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
    exit;
}
