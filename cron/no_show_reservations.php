<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/mailer.php';

use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Refund;

// Auto-cancel meetings if the client did not show up after the meeting end time.
// Refund full reservation fee and release the property.

$graceMinutes = 60;

$stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
if ($stripeSecret) {
    Stripe::setApiKey($stripeSecret);
}

$pdo = (new Database())->getPdo();

try {
    $stmt = $pdo->prepare("
        SELECT
            m.id AS meeting_id,
            m.meeting_date,
            m.end_time,
            m.status AS meeting_status,

            r.id AS reservation_id,
            r.property_id,
            r.customer_id,
            r.payment_status,
            r.reservation_fee,
            r.cancelled_at,
            r.refund_status

        FROM meetings m
        INNER JOIN property_reservations r ON r.id = m.reservation_id
        WHERE m.status IN ('pending','accepted')
          AND (r.reservation_status = 'PAID_CONFIRMED' OR r.payment_status = 'paid')
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $processed = 0;

    foreach ($rows as $row) {
        if (!empty($row['cancelled_at'])) {
            continue;
        }
        if (in_array($row['refund_status'], ['processed','rejected'], true)) {
            continue;
        }

        $meetingEnd = new DateTime($row['meeting_date'] . ' ' . $row['end_time']);
        $cutoff = (clone $meetingEnd)->modify('+' . $graceMinutes . ' minutes');
        $now = new DateTime('now');

        if ($now <= $cutoff) {
            continue;
        }

        $refundAmount = (float)$row['reservation_fee'];
        $refundStatus = 'pending';
        $refundReference = null;
        $refundedAt = null;

        if ($stripeSecret) {
            $pay = $pdo->prepare("
                SELECT stripe_session_id
                FROM payments
                WHERE type = 'property_reservation'
                  AND reference_id = ?
                  AND status = 'paid'
                ORDER BY id DESC
                LIMIT 1
            ");
            $pay->execute([(int)$row['reservation_id']]);
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
                    error_log("Refund failed for reservation {$row['reservation_id']}: " . $refundErr->getMessage());
                }
            }
        }

        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE meetings
            SET status = 'cancelled'
            WHERE id = ?
        ")->execute([(int)$row['meeting_id']]);

        $pdo->prepare("
            UPDATE property_reservations
            SET cancelled_at = NOW(),
                cancelled_by = 'system',
                cancel_reason = 'no_show',
                reservation_status = 'CANCELLED',
                refund_status = ?,
                refund_amount = ?,
                refund_currency = 'MUR',
                refund_reference = ?,
                refunded_at = ?
            WHERE id = ?
        ")->execute([
            $refundStatus,
            $refundAmount,
            $refundReference,
            $refundedAt,
            (int)$row['reservation_id']
        ]);

        $pdo->prepare("
            UPDATE properties
            SET status = 'Available',
                reserved_by_customer_id = NULL,
                reserved_until = NULL
            WHERE id = ?
        ")->execute([(int)$row['property_id']]);

        $pdo->commit();

        // Notify agent (non-blocking)
        try {
            $stmtAgent = $pdo->prepare("
                SELECT u.email, CONCAT(u.first_name, ' ', u.last_name) AS name
                FROM properties p
                INNER JOIN agents a ON a.id = p.assigned_agent_id
                INNER JOIN users u ON u.id = a.user_id
                WHERE p.id = ?
                LIMIT 1
            ");
            $stmtAgent->execute([(int)$row['property_id']]);
            $agentRow = $stmtAgent->fetch(PDO::FETCH_ASSOC);
            if ($agentRow && !empty($agentRow['email'])) {
                $safeName = htmlspecialchars($agentRow['name'] ?? 'Agent');
                $html = "
                  <div style='font-family:Arial,sans-serif;line-height:1.5'>
                    <h2 style='margin:0 0 8px'>No-Show Reservation Cancelled</h2>
                    <p style='margin:0 0 12px'>Hello <b>{$safeName}</b>,</p>
                    <p style='margin:0 0 12px'>Reservation #{$row['reservation_id']} was cancelled due to no-show and the property was released.</p>
                    <p style='margin:0'>Sentinel Team</p>
                  </div>
                ";
                sendMail($agentRow['email'], 'No-Show Reservation Cancelled', $html);
            }
        } catch (Throwable $mailErr) {
            error_log("No-show email failed: " . $mailErr->getMessage());
        }

        $processed++;
    }

    echo "No-show processed: " . $processed;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("CRON ERROR (no_show_reservations): " . $e->getMessage());
}
