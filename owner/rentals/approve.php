<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/owner_guard.php';
require_once __DIR__ . '/../../lib/mailer.php';

header('Content-Type: application/json');

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$bookingId = (int)($input['booking_id'] ?? ($_POST['booking_id'] ?? $_GET['booking_id'] ?? 0));

if ($bookingId <= 0) {
    json_error("Missing booking_id");
}

try {
    requireCsrf();

    $pdo->beginTransaction();

    // =========================
    // LOAD + LOCK BOOKING (BACKUP TABLE)
    // =========================
    $stmt = $pdo->prepare("
        SELECT rb.*, p.title, p.location, u.email AS tenant_email
        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        LEFT JOIN customers c ON c.id = rb.tenant_id
        LEFT JOIN users u ON u.id = c.user_id
        WHERE rb.id = ?
          AND p.owner_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$bookingId, $OWNER_ID]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$b) {
        $pdo->rollBack();
        json_error("Booking not found", 404);
    }

    if ($b['status'] === 'accepted') {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'booking_id' => $bookingId,
            'status' => 'accepted'
        ]);
        exit;
    }

    if ($b['status'] !== 'pending') {
        $pdo->rollBack();
        json_error("Only pending bookings can be approved", 409);
    }

    // =========================
    // OVERLAP CHECK (LOCK CONFLICTS)
    // =========================
    $overlap = $pdo->prepare("
        SELECT id
        FROM rental_bookings_backup
        WHERE property_id = ?
          AND status IN ('accepted','ongoing')
          AND checkin < ?
          AND checkout > ?
        FOR UPDATE
    ");
    $overlap->execute([
        $b['property_id'],
        $b['checkout'],
        $b['checkin']
    ]);

    if ($overlap->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Booking conflicts with an existing approved booking'
        ]);
        exit;
    }

    // =========================
    // ACCEPT BOOKING
    // =========================
    $pdo->prepare("
        UPDATE rental_bookings_backup
        SET status = 'accepted'
        WHERE id = ?
    ")->execute([$bookingId]);

    // Lock availability
    $pdo->prepare("
        UPDATE rental_availability
        SET is_available = 0
        WHERE property_id = ?
          AND date >= ?
          AND date < ?
    ")->execute([
        $b['property_id'],
        $b['checkin'],
        $b['checkout']
    ]);

    // Update property rental_status if any accepted/ongoing exists
    $statusStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM rental_bookings_backup
        WHERE property_id = ?
          AND status IN ('accepted','ongoing')
    ");
    $statusStmt->execute([$b['property_id']]);
    if ((int)$statusStmt->fetchColumn() > 0) {
        $pdo->prepare("UPDATE properties SET rental_status = 'Unavailable' WHERE id = ?")
            ->execute([$b['property_id']]);
    }

    $pdo->commit();

    // =========================
    // EMAIL (NON-BLOCKING)
    // =========================
    if (!empty($b['tenant_email'])) {
        try {
            sendMail(
                $b['tenant_email'],
                "✅ Your rental booking was approved",
                "
                <h3>Booking Approved</h3>
                <p><b>{$b['title']}</b> ({$b['location']})</p>
                <p>{$b['checkin']} → {$b['checkout']}</p>
                <p>Please log in to complete payment.</p>
                "
            );
        } catch (Throwable $e) {
            error_log("Mail failed: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'booking_id' => $bookingId,
        'status' => 'accepted'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
