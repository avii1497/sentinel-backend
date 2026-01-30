<?php
require_once __DIR__ . '/../../../cors.php';
require_once __DIR__ . '/../../../Database.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    requireCsrf();

    // =========================
    // AUTH (OWNER ONLY)
    // =========================
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'owner') {
        json_error("Unauthorized", 401);
    }

    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        $input = [];
    }

    $booking_id = $input['booking_id'] ?? ($_POST['booking_id'] ?? $_GET['booking_id'] ?? null);
    if (!$booking_id || !is_numeric($booking_id)) {
        json_error("Invalid booking_id");
    }
    $booking_id = (int)$booking_id;

    // =========================
    // DB
    // =========================
    $pdo = (new Database())->getPdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // =========================
    // Resolve owner.id
    // =========================
    $oStmt = $pdo->prepare("
        SELECT id
        FROM owners
        WHERE user_id = ?
        LIMIT 1
    ");
    $oStmt->execute([(int)$_SESSION['user_id']]);
    $owner_id = (int)$oStmt->fetchColumn();

    if (!$owner_id) {
        json_error("Owner profile not found", 403);
    }

    $pdo->beginTransaction();

    // =========================
    // Load + lock booking + ownership
    // =========================
    $stmt = $pdo->prepare("
        SELECT rb.*
        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        WHERE rb.id = ?
          AND p.owner_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$booking_id, $owner_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();
        json_error("Booking not found or not your property", 404);
    }

    if ($booking['status'] === 'accepted') {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Booking already accepted',
            'data' => [
                'booking_id' => $booking_id,
                'status' => 'accepted'
            ]
        ]);
        exit;
    }

    if ($booking['status'] !== 'pending') {
        $pdo->rollBack();
        json_error("Only pending bookings can be accepted", 409);
    }

    // =========================
    // OVERLAP CHECK (LOCK CONFLICTS)
    // Block if accepted OR ongoing overlaps
    // =========================
    $overlapStmt = $pdo->prepare("
        SELECT id
        FROM rental_bookings_backup
        WHERE property_id = ?
          AND status IN ('accepted','ongoing')
          AND checkin < ?
          AND checkout > ?
        FOR UPDATE
    ");
    $overlapStmt->execute([
        $booking['property_id'],
        $booking['checkout'],
        $booking['checkin']
    ]);

    if ($overlapStmt->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Booking conflicts with an existing approved booking'
        ]);
        exit;
    }

    // =========================
    // ACCEPT + LOCK DATES
    // =========================
    // 1️⃣ Accept booking
    $pdo->prepare("
        UPDATE rental_bookings_backup
        SET status = 'accepted'
        WHERE id = ?
    ")->execute([$booking_id]);

    // 2️⃣ Lock availability
    $pdo->prepare("
        UPDATE rental_availability
        SET is_available = 0
        WHERE property_id = ?
          AND date >= ?
          AND date < ?
    ")->execute([
        $booking['property_id'],
        $booking['checkin'],
        $booking['checkout']
    ]);

    // Update property rental_status if any accepted/ongoing exists
    $statusStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM rental_bookings_backup
        WHERE property_id = ?
          AND status IN ('accepted','ongoing')
    ");
    $statusStmt->execute([$booking['property_id']]);
    if ((int)$statusStmt->fetchColumn() > 0) {
        $pdo->prepare("UPDATE properties SET rental_status = 'Unavailable' WHERE id = ?")
            ->execute([$booking['property_id']]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Booking accepted and dates locked',
        'data' => [
            'booking_id' => $booking_id,
            'status' => 'accepted'
        ]
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error($e->getMessage(), 500);
}
