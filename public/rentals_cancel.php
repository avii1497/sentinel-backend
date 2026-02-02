<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/validation.php';

use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Refund;

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    requireLogin();
    requireRole(['customer', 'premium_customer']);
    requireCsrf();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $input = sanitize_array($input);

    $bookingId = v_int($input['booking_id'] ?? ($_POST['booking_id'] ?? null), 'booking id');
    $reason = v_string($input['reason'] ?? ($_POST['reason'] ?? ''), 'reason', 500, 0, false);
    $reason = $reason !== '' ? $reason : null;

    $pdo = (new Database())->getPdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Resolve customer id
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $customerId = (int)$stmt->fetchColumn();
    if (!$customerId) json_error("Not a customer", 403);

    // Load booking
    $stmt = $pdo->prepare("
        SELECT id, tenant_id, property_id, checkin, checkout,
               status, payment_status, payment_method, total_price
        FROM rental_bookings_backup
        WHERE id = ? AND tenant_id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId, $customerId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) json_error("Booking not found", 404);

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

    $today = new DateTime('today');
    $checkin = new DateTime($booking['checkin']);
    if ($checkin <= $today) {
        json_error("Cannot cancel after check-in date", 409);
    }

    if (!in_array($booking['status'], ['pending','accepted'], true)) {
        json_error("Booking is not cancellable", 409);
    }

    $refundStatus = 'none';
    $refundAmount = null;
    $refundCurrency = null;
    $shouldRefund = false;

    if ($booking['payment_status'] === 'paid') {
        $daysBefore = (int)$today->diff($checkin)->format('%r%a');
        if ($daysBefore >= 5) {
            $refundStatus = 'pending';
            $refundAmount = (float)$booking['total_price'];
            $refundCurrency = 'MUR';
            $shouldRefund = true;
        } else {
            $refundStatus = 'rejected';
            $refundAmount = 0.0;
            $refundCurrency = 'MUR';
        }
    }

    $pdo->beginTransaction();

    $upd = $pdo->prepare("
        UPDATE rental_bookings_backup
        SET status = 'cancelled',
            cancelled_at = NOW(),
            cancelled_by = 'customer',
            cancel_reason = ?,
            refund_status = ?,
            refund_amount = ?,
            refund_currency = ?,
            refund_reference = NULL,
            refunded_at = NULL
        WHERE id = ?
          AND tenant_id = ?
          AND status IN ('pending','accepted')
    ");
    $upd->execute([
        $reason,
        $refundStatus,
        $refundAmount,
        $refundCurrency,
        $bookingId,
        $customerId
    ]);

    if ($upd->rowCount() === 0) {
        $pdo->rollBack();
        json_error("Booking cancellation failed", 409);
    }

    if ($booking['status'] === 'accepted') {
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
    }

    $pdo->commit();

    $refundResult = null;
    if ($shouldRefund && $booking['payment_method'] === 'stripe') {
        try {
            $stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
            if (!$stripeSecret) {
                throw new RuntimeException("Stripe is not configured");
            }
            Stripe::setApiKey($stripeSecret);

            $payStmt = $pdo->prepare("
                SELECT stripe_session_id
                FROM payments
                WHERE type = 'rental_booking'
                  AND reference_id = ?
                  AND status = 'paid'
                ORDER BY id DESC
                LIMIT 1
            ");
            $payStmt->execute([$bookingId]);
            $stripeSessionId = $payStmt->fetchColumn();
            if (!$stripeSessionId) {
                throw new RuntimeException("Stripe session not found");
            }

            $session = StripeSession::retrieve($stripeSessionId);
            if (empty($session->payment_intent)) {
                throw new RuntimeException("Payment intent not found");
            }

            $refund = Refund::create([
                'payment_intent' => $session->payment_intent,
                'amount' => (int)round($refundAmount * 100),
            ]);

            $pdo->prepare("
                UPDATE rental_bookings_backup
                SET refund_status = 'refunded',
                    refund_reference = ?,
                    refunded_at = NOW()
                WHERE id = ?
            ")->execute([$refund->id, $bookingId]);

            $refundResult = 'refunded';
        } catch (Throwable $e) {
            $pdo->prepare("
                UPDATE rental_bookings_backup
                SET refund_status = 'failed'
                WHERE id = ?
            ")->execute([$bookingId]);
            $refundResult = 'failed';
            error_log("Stripe refund error: " . $e->getMessage());
        }
    } elseif ($shouldRefund) {
        $refundResult = 'pending';
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'booking_id' => $bookingId,
            'status' => 'cancelled',
            'refund_status' => $refundResult ?? $refundStatus
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error($e->getMessage(), 500);
}
