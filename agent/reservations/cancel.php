<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../lib/mailer.php';
require_once __DIR__ . '/../../lib/validation.php';

use Stripe\Stripe;
use Stripe\Checkout\Session;
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
    requireRole('agent');
    requireCsrf();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $input = sanitize_array($input);

    $reservationId = v_int($input['reservation_id'] ?? ($_POST['reservation_id'] ?? null), 'reservation id');
    $reason = v_string($input['reason'] ?? ($_POST['reason'] ?? 'not_interested'), 'reason', 500, 0, false);

    $pdo = (new Database())->getPdo();

    $agentId = (int)($_SESSION['agent_id'] ?? 0);
    if ($agentId <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $agentId = (int)$stmt->fetchColumn();
    }
    if ($agentId <= 0) json_error('Unauthorized', 403);

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.property_id,
            r.customer_id,
            r.payment_status,
            r.reservation_fee,
            r.cancelled_at,
            p.assigned_agent_id
        FROM property_reservations r
        INNER JOIN properties p ON p.id = r.property_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$reservationId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$res) json_error('Reservation not found', 404);

    if ((int)$res['assigned_agent_id'] !== $agentId) {
        json_error('Not authorized for this reservation', 403);
    }

    if (!empty($res['cancelled_at'])) {
        echo json_encode(['success' => true, 'message' => 'Reservation already cancelled']);
        exit;
    }

    $refundPercent = 0.0;
    if ($reason === 'not_interested') {
        $refundPercent = 0.80;
    }

    $refundAmount = null;
    $refundStatus = 'none';
    $refundReference = null;
    $refundedAt = null;

    if ($res['payment_status'] === 'paid') {
        $refundAmount = round(((float)$res['reservation_fee']) * $refundPercent, 2);
        $refundStatus = 'pending';

        $stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
        if ($stripeSecret && $refundAmount > 0) {
            Stripe::setApiKey($stripeSecret);
            $pay = $pdo->prepare("
                SELECT stripe_session_id
                FROM payments
                WHERE type = 'property_reservation'
                  AND reference_id = ?
                  AND status = 'paid'
                ORDER BY id DESC
                LIMIT 1
            ");
            $pay->execute([$reservationId]);
            $sessionId = $pay->fetchColumn();
            if (!empty($sessionId)) {
                try {
                    $session = Session::retrieve($sessionId);
                    $refund = Refund::create([
                        'payment_intent' => $session->payment_intent,
                        'amount' => (int)round($refundAmount * 100)
                    ]);
                    $refundStatus = 'processed';
                    $refundReference = $refund->id;
                    $refundedAt = date('Y-m-d H:i:s');
                } catch (Throwable $refundErr) {
                    error_log('Refund failed: ' . $refundErr->getMessage());
                }
            }
        }
    }

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE property_reservations
        SET cancelled_at = NOW(),
            cancelled_by = 'agent',
            cancel_reason = ?,
            reservation_status = 'CANCELLED',
            refund_status = ?,
            refund_amount = ?,
            refund_currency = 'MUR',
            refund_reference = ?,
            refunded_at = ?
        WHERE id = ?
    ")->execute([
        $reason,
        $refundStatus,
        $refundAmount,
        $refundReference,
        $refundedAt,
        $reservationId
    ]);

    $pdo->prepare("
        UPDATE properties
        SET status = 'Available',
            reserved_by_customer_id = NULL,
            reserved_until = NULL
        WHERE id = ?
    ")->execute([(int)$res['property_id']]);

    $pdo->commit();

    // Email agent (non-blocking)
    try {
        $stmtAgent = $pdo->prepare("
            SELECT u.email, CONCAT(u.first_name, ' ', u.last_name) AS name
            FROM agents a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmtAgent->execute([$agentId]);
        $agentRow = $stmtAgent->fetch(PDO::FETCH_ASSOC);
        if ($agentRow && !empty($agentRow['email'])) {
            $safeName = htmlspecialchars($agentRow['name'] ?? 'Agent');
            $html = "
              <div style='font-family:Arial,sans-serif;line-height:1.5'>
                <h2 style='margin:0 0 8px'>Reservation Cancelled</h2>
                <p style='margin:0 0 12px'>Hello <b>{$safeName}</b>,</p>
                <p style='margin:0 0 12px'>Reservation #{$reservationId} has been cancelled ({$reason}).</p>
                <p style='margin:0'>Sentinel Team</p>
              </div>
            ";
            sendMail($agentRow['email'], 'Reservation Cancelled', $html);
        }
    } catch (Throwable $mailErr) {
        error_log('Cancellation email failed: ' . $mailErr->getMessage());
    }

    echo json_encode([
        'success' => true,
        'refund_status' => $refundStatus,
        'refund_amount' => $refundAmount
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
