<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/owner_guard.php';
require_once __DIR__ . '/../../lib/mailer.php';
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
            rb.*,
            p.title,
            p.location,
            u.email AS tenant_email,
            u.first_name,
            u.last_name
        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        LEFT JOIN customers c ON c.id = rb.tenant_id
        LEFT JOIN users u ON u.id = c.user_id
        WHERE rb.id = ?
          AND p.owner_id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId, $OWNER_ID]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$b) {
        json_error("Booking not found", 404);
    }

    if ($b['status'] === 'rejected') {
        echo json_encode([
            'success' => true,
            'booking_id' => $bookingId,
            'status' => 'rejected'
        ]);
        exit;
    }

    if ($b['status'] !== 'pending') {
        json_error("Only pending bookings can be rejected", 409);
    }

    // =========================
    // REJECT BOOKING
    // =========================
    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE rental_bookings_backup
        SET status = 'rejected'
        WHERE id = ?
    ")->execute([$bookingId]);

    $pdo->commit();

    // =========================
    // EMAIL CUSTOMER (NON-BLOCKING)
    // =========================
    if (!empty($b['tenant_email'])) {
        try {
            $subject = "❌ Your rental booking was rejected";
            $html = "
                <h3>Booking Rejected</h3>
                <p>Your booking for <b>{$b['title']}</b> ({$b['location']}) was rejected.</p>
                <p><b>Dates:</b> {$b['checkin']} → {$b['checkout']}</p>
                <p>You may try another date or property.</p>
            ";
            sendMail($b['tenant_email'], $subject, $html);
        } catch (Throwable $mailErr) {
            error_log("Reject booking email failed: " . $mailErr->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'booking_id' => $bookingId,
        'status' => 'rejected'
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error($e->getMessage(), 500);
}
