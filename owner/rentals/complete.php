<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy rental completion endpoint.
// Safe to remove after: 2026-06-30 (use /owner/rentals/list.php workflow).
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/owner_guard.php';
require_once __DIR__ . '/../../lib/validation.php';

header('Content-Type: application/json');

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$input = sanitize_array($input ?? []);
$bookingId = v_int(
    $input['booking_id'] ?? ($_POST['booking_id'] ?? $_GET['booking_id'] ?? null),
    'booking id'
);

try {
    requireCsrf();

    // =========================
    // LOAD BOOKING + VERIFY OWNER
    // =========================
    $stmt = $pdo->prepare("
        SELECT 
            rb.id,
            rb.status,
            rb.payment_status,
            rb.checkout
        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        WHERE rb.id = ?
          AND p.owner_id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId, $OWNER_ID]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        json_error("Booking not found", 404);
    }

    // =========================
    // VALIDATION
    // =========================
    if ($booking['status'] === 'completed') {
        echo json_encode([
            'success' => true,
            'booking_id' => $bookingId,
            'status' => 'completed'
        ]);
        exit;
    }

    if (!in_array($booking['status'], ['accepted','ongoing'], true) || $booking['payment_status'] !== 'paid') {
        json_error("Only accepted & paid rentals can be completed", 409);
    }

    $today = date('Y-m-d');
    if (!empty($booking['checkout']) && $booking['checkout'] > $today) {
        json_error("Cannot complete rental before checkout date", 409);
    }

    // =========================
    // COMPLETE RENTAL
    // =========================
    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE rental_bookings_backup
        SET status = 'completed'
        WHERE id = ?
    ")->execute([$bookingId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'booking_id' => $bookingId,
        'status' => 'completed'
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error($e->getMessage(), 500);
}
