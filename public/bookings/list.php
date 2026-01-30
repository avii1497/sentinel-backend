<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../customer_guard.php';

header('Content-Type: application/json');

$stage = strtolower(trim($_GET['stage'] ?? 'all'));

$conditions = [
    'pending'   => "rb.status = 'pending'",
    'approved'  => "rb.status = 'accepted' AND rb.payment_status = 'unpaid'",
    'active'    => "rb.status IN ('accepted','ongoing') AND rb.payment_status = 'paid' AND rb.checkin <= CURDATE() AND rb.checkout >= CURDATE()",
    'history'   => "rb.status IN ('completed','rejected','cancelled') OR (rb.payment_status = 'paid' AND rb.checkout < CURDATE())",
    'all'       => "1=1"
];

if (!isset($conditions[$stage])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid stage'
    ]);
    exit;
}

try {
    // 🔐 Resolve customer.id from logged-in user
    $stmt = $pdo->prepare("
        SELECT id FROM customers WHERE user_id = ? LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Not a customer'
        ]);
        exit;
    }

    $tenant_id = (int)$customer['id'];

    // 📦 Load bookings
    $stmt = $pdo->prepare("
        SELECT 
            rb.id AS booking_id,
            rb.checkin,
            rb.checkout,
            rb.guests,
            rb.total_price,
            rb.rental_type,
            rb.status,
            rb.payment_status,

            p.title AS property_title,
            p.location

        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id

        WHERE 
            rb.tenant_id = :tenant_id
            AND {$conditions[$stage]}

        ORDER BY rb.created_at DESC
    ");

    $stmt->execute([
        ':tenant_id' => $tenant_id
    ]);

    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
