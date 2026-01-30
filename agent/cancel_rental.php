<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    requireLogin();
    requireRole('agent');
    requireCsrf();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $bookingId = (int)($input['booking_id'] ?? ($_POST['booking_id'] ?? 0));
    if ($bookingId <= 0) json_error("Invalid booking_id");

    $reason = trim((string)($input['reason'] ?? ($_POST['reason'] ?? '')));
    $reason = $reason !== '' ? $reason : null;

    $pdo = (new Database())->getPdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $agentId = (int)($_SESSION['agent_id'] ?? 0);
    if ($agentId <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $agentId = (int)$stmt->fetchColumn();
    }
    if ($agentId <= 0) json_error("Not an agent", 403);

    $stmt = $pdo->prepare("
        SELECT rb.id, rb.property_id, rb.checkin, rb.checkout,
               rb.status, rb.payment_status, rb.payment_method, rb.total_price,
               p.assigned_agent_id
        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        WHERE rb.id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) json_error("Booking not found", 404);

    if ((int)$booking['assigned_agent_id'] !== $agentId) {
        json_error("Not authorized for this booking", 403);
    }

    if (in_array($booking['status'], ['cancelled','rejected','completed'], true)) {
        echo json_encode([
            'success' => true,
            'message' => 'Booking already closed',
            'data' => [
                'booking_id' => $bookingId,
                'status' => $booking['status'],
            ]
        ]);
        exit;
    }

    if (!in_array($booking['status'], ['accepted','ongoing'], true)) {
        json_error("Booking is not cancellable", 409);
    }

    $refundStatus = $booking['payment_status'] === 'paid' ? 'rejected' : 'none';
    $refundAmount = $booking['payment_status'] === 'paid' ? 0.0 : null;
    $refundCurrency = $booking['payment_status'] === 'paid' ? 'MUR' : null;

    $pdo->beginTransaction();

    $upd = $pdo->prepare("
        UPDATE rental_bookings_backup
        SET status = 'cancelled',
            cancelled_at = NOW(),
            cancelled_by = 'agent',
            cancel_reason = ?,
            refund_status = ?,
            refund_amount = ?,
            refund_currency = ?,
            refund_reference = NULL,
            refunded_at = NULL
        WHERE id = ?
          AND status IN ('accepted','ongoing')
    ");
    $upd->execute([
        $reason,
        $refundStatus,
        $refundAmount,
        $refundCurrency,
        $bookingId
    ]);

    if ($upd->rowCount() === 0) {
        $pdo->rollBack();
        json_error("Booking cancellation failed", 409);
    }

    $pdo->prepare("
        UPDATE rental_availability
        SET is_available = 1
        WHERE property_id = ?
          AND date >= ?
          AND date < ?
    ")->execute([
        (int)$booking['property_id'],
        $booking['checkin'],
        $booking['checkout']
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'data' => [
            'booking_id' => $bookingId,
            'status' => 'cancelled',
            'refund_status' => $refundStatus
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error($e->getMessage(), 500);
}
