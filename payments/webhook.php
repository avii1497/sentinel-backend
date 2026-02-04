<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/validation.php';

use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Refund;

$stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
$endpoint_secret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? getenv('STRIPE_WEBHOOK_SECRET');
if (!$stripeSecret || !$endpoint_secret) {
    http_response_code(500);
    error_log("Missing Stripe webhook configuration");
    exit;
}

Stripe::setApiKey($stripeSecret);


// Read & verify payload

$payload = file_get_contents("php://input");
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = Webhook::constructEvent($payload, $sig, $endpoint_secret);
} catch (Throwable $e) {
    error_log("âŒ Stripe signature error: " . $e->getMessage());
    http_response_code(400);
    exit;
}

// Only care about completed checkout
if ($event->type !== 'checkout.session.completed') {
    http_response_code(200);
    exit;
}

$session  = $event->data->object;
$metadata = $session->metadata ?? null;
$type     = $metadata->type ?? null;

if (!$type) {
    bad_request('type is required.');
}
$type = v_string($type, 'type', 50);

$pdo = (new Database())->getPdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


function createSystemDocument(PDO $pdo, int $propertyId, string $key, string $label): void
{
    $check = $pdo->prepare("
        SELECT id
        FROM property_documents
        WHERE property_id = ?
          AND document_key = ?
          AND uploaded_by = 'system'
        LIMIT 1
    ");
    $check->execute([$propertyId, $key]);

    // Idempotent: do not duplicate
    if ($check->fetchColumn()) return;

    $pdo->prepare("
        INSERT INTO property_documents (
            property_id,
            document_key,
            document_label,
            uploaded_by,
            uploaded_at
        ) VALUES (?, ?, ?, 'system', NOW())
    ")->execute([$propertyId, $key, $label]);
}


// ==============================
// Helper: mark payment as PAID
// ==============================
function markPaid(PDO $pdo, string $stripeSessionId, string $type, int $refId, float $amount): void
{
    $exists = $pdo->prepare("
        SELECT id
        FROM payments
        WHERE type = ?
          AND reference_id = ?
          AND status = 'paid'
        LIMIT 1
    ");
    $exists->execute([$type, $refId]);
    if ($exists->fetchColumn()) return;

    // 1ï¸âƒ£ Mark latest initiated payment for this reference as paid
    $upd = $pdo->prepare("
        UPDATE payments
        SET status = 'paid',
            stripe_session_id = ?
        WHERE type = ?
          AND reference_id = ?
          AND status = 'initiated'
        ORDER BY id DESC
        LIMIT 1
    ");
    $upd->execute([$stripeSessionId, $type, $refId]);

    if ($upd->rowCount() > 0) return;

    // 2ï¸âƒ£ Fallback insert (idempotent)
    $pdo->prepare("
        INSERT INTO payments (
            type, reference_id, amount, currency, method, stripe_session_id, status
        ) VALUES (?, ?, ?, 'MUR', 'stripe', ?, 'paid')
    ")->execute([$type, $refId, $amount, $stripeSessionId]);
}

// ==============================
// RENTAL BOOKING PAYMENT
// ==============================
if ($type === 'rental_booking') {

    $booking_id = v_int($metadata->booking_id ?? null, 'booking id');
    $amountCents = v_int($session->amount_total ?? null, 'amount total', 1, 100000000000);
    $amount = $amountCents / 100;

    if (!$booking_id || $amount <= 0) {
        error_log("âŒ Invalid rental payload");
        http_response_code(200);
        exit;
    }

    try {
        $pdo->beginTransaction();

        markPaid($pdo, $session->id, 'rental_booking', $booking_id, $amount);

        $pdo->prepare("
            UPDATE rental_bookings_backup
            SET payment_status = 'paid'
            WHERE id = ?
              AND status IN ('accepted','ongoing')
        ")->execute([$booking_id]);

        $pdo->commit();
        error_log("âœ… Rental booking {$booking_id} PAID");

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("âŒ Rental webhook DB error: " . $e->getMessage());
    }

    http_response_code(200);
    exit;
}

// ==============================
// PROPERTY RESERVATION PAYMENT
// ==============================
if ($type === 'property_reservation') {

    $reservation_id = v_int($metadata->reservation_id ?? null, 'reservation id');
    $property_id    = v_int($metadata->property_id ?? null, 'property id');
    $amountCents = v_int($session->amount_total ?? null, 'amount total', 1, 100000000000);
    $amount = $amountCents / 100;

    if (!$reservation_id || !$property_id || $amount <= 0) {
        http_response_code(200);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $resStmt = $pdo->prepare("
            SELECT id, property_id, customer_id, payment_status, reservation_status, cancelled_at, refund_status
            FROM property_reservations
            WHERE id = ?
            FOR UPDATE
        ");
        $resStmt->execute([$reservation_id]);
        $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);

        if (!$reservation || (int)$reservation['property_id'] !== $property_id) {
            $pdo->rollBack();
            markPaid($pdo, $session->id, 'property_reservation', $reservation_id, $amount);
            error_log("Reservation webhook mismatch for reservation {$reservation_id}");
            http_response_code(200);
            exit;
        }

        if (!empty($reservation['cancelled_at'])
            || $reservation['refund_status'] === 'processed'
            || in_array($reservation['reservation_status'] ?? '', ['CANCELLED','EXPIRED'], true)) {
            $pdo->rollBack();
            markPaid($pdo, $session->id, 'property_reservation', $reservation_id, $amount);
            error_log("Reservation {$reservation_id} is cancelled/refunded; skipping confirm");
            http_response_code(200);
            exit;
        }

        $propStmt = $pdo->prepare("
            SELECT id, status, reserved_by_customer_id, reserved_until
            FROM properties
            WHERE id = ?
            FOR UPDATE
        ");
        $propStmt->execute([$property_id]);
        $property = $propStmt->fetch(PDO::FETCH_ASSOC);

        if (!$property) {
            $pdo->rollBack();
            markPaid($pdo, $session->id, 'property_reservation', $reservation_id, $amount);
            error_log("Property {$property_id} not found for reservation {$reservation_id}");
            http_response_code(200);
            exit;
        }

        $conflictStmt = $pdo->prepare("
            SELECT id
            FROM property_reservations
            WHERE property_id = ?
              AND id <> ?
              AND cancelled_at IS NULL
              AND (
                reservation_status = 'PAID_CONFIRMED'
                OR payment_status = 'paid'
              )
            LIMIT 1
            FOR UPDATE
        ");
        $conflictStmt->execute([$property_id, $reservation_id]);
        if ($conflictStmt->fetch()) {
            $pdo->rollBack();
            markPaid($pdo, $session->id, 'property_reservation', $reservation_id, $amount);
            error_log("Reservation {$reservation_id} conflicts with another active reservation for property {$property_id}");
            http_response_code(200);
            exit;
        }

        markPaid($pdo, $session->id, 'property_reservation', $reservation_id, $amount);

        $pdo->prepare("
            UPDATE property_reservations
            SET payment_status = 'paid',
                reservation_status = 'PAID_CONFIRMED',
                ready_for_final = 1
            WHERE id = ?
              AND (payment_status <> 'paid' OR reservation_status <> 'PAID_CONFIRMED')
        ")->execute([$reservation_id]);


        createSystemDocument(
    $pdo,
    $property_id,
    'reservation_confirmation',
    'Reservation Confirmation'
);
        $pdo->prepare("
            UPDATE properties
            SET status = 'Pending',
                reserved_by_customer_id = ?,
                reserved_until = CASE
                    WHEN reserved_until IS NULL OR reserved_until < NOW()
                    THEN DATE_ADD(NOW(), INTERVAL 48 HOUR)
                    ELSE reserved_until
                END
            WHERE id = ?
        ")->execute([$reservation['customer_id'], $property_id]);

        $pdo->commit();
        error_log("âœ… Reservation {$reservation_id} PAID");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("âŒ Reservation webhook DB error: " . $e->getMessage());
    }

    http_response_code(200);
    exit;
}

// ==============================
// PREMIUM UPGRADE
// ==============================
if ($type === 'premium_upgrade') {

    $user_id = v_int($metadata->user_id ?? null, 'user id');
    $amountCents = v_int($session->amount_total ?? null, 'amount total', 1, 100000000000);
    $amount = $amountCents / 100;

    if (!$user_id || $amount <= 0) {
        http_response_code(200);
        exit;
    }

    try {
        $pdo->beginTransaction();

        markPaid($pdo, $session->id, 'premium_upgrade', $user_id, $amount);

        $pdo->prepare("
            UPDATE users
            SET is_premium = 1,
                role = 'premium_customer'
            WHERE id = ?
        ")->execute([$user_id]);

        $pdo->commit();
        error_log("â­ User {$user_id} upgraded");

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("âŒ Premium webhook DB error: " . $e->getMessage());
    }

    http_response_code(200);
    exit;
}


// ==============================
// PROPERTY RESERVATION REFUND (OWNER CANCEL)
// ==============================
if ($type === 'property_reservation_refund') {

    $reservation_id = v_int($metadata->reservation_id ?? null, 'reservation id');
    $paymentIntent = v_string($session->payment_intent ?? null, 'payment intent', 200);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT
                id,
                refund_status,
                decision_status,
                payment_status
            FROM property_reservations
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$reservation_id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            $pdo->rollBack();
            http_response_code(200);
            exit;
        }

        // ✅ Only refund if owner rejected
        if (
            $res['decision_status'] !== 'REJECTED' ||
            $res['refund_status'] !== 'pending' ||
            $res['payment_status'] !== 'paid'
        ) {
            $pdo->rollBack();
            http_response_code(200);
            exit;
        }

        // 🔁 Issue Stripe refund
        Refund::create([
            'payment_intent' => $paymentIntent,
        ]);

        // ✅ Mark refund as processed
        $pdo->prepare("
            UPDATE property_reservations
            SET
                refund_status = 'processed',
                refunded_at = NOW()
            WHERE id = ?
        ")->execute([$reservation_id]);

        $pdo->commit();
        error_log("💸 Refund processed for reservation {$reservation_id}");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("❌ Refund webhook error: " . $e->getMessage());
    }

    http_response_code(200);
    exit;
}


http_response_code(200);
exit;
