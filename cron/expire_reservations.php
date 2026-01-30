<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

$db = new Database();
$pdo = $db->getPdo();

try {
    $pdo->beginTransaction();

    // Find expired reservations
    $stmt = $pdo->prepare("
        SELECT id, property_id, offer_id
        FROM property_reservations
        WHERE cancelled_at IS NULL
          AND (
            reservation_status = 'ACCEPTED_AWAITING_PAYMENT'
            OR payment_status = 'pending'
          )
          AND expires_at < NOW()
    ");
    $stmt->execute();
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expired as $res) {

        // 1️⃣ Cancel reservation
        $stmt = $pdo->prepare("
            UPDATE property_reservations
            SET payment_status = 'failed',
                reservation_status = 'EXPIRED',
                cancelled_at = NOW(),
                cancelled_by = 'system',
                cancel_reason = 'payment_expired',
                refund_status = 'none',
                refund_amount = NULL,
                refund_currency = NULL,
                refund_reference = NULL,
                refunded_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$res['id']]);

        // 2️⃣ Reject offer
        $stmt = $pdo->prepare("
            UPDATE property_offers
            SET status = 'rejected'
            WHERE id = ?
        ");
        $stmt->execute([$res['offer_id']]);

        // 3️⃣ Release property
        $stmt = $pdo->prepare("
            UPDATE properties
            SET status = 'Available',
                reserved_by_customer_id = NULL,
                reserved_until = NULL
            WHERE id = ?
        ");
        $stmt->execute([$res['property_id']]);
    }

    $pdo->commit();
    echo "Expired reservations processed: " . count($expired);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("CRON ERROR: " . $e->getMessage());
}
