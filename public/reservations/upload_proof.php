<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy reservation proof upload.
// Safe to remove after: 2026-06-30 (if uploads are handled elsewhere).
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header('Content-Type: application/json');

try {
    requireLogin();
    requireRole(['customer', 'premium_customer']);
    requireCsrf();

    $customer_id = (int)$_SESSION['user_id'];
    $reservation_id = v_int($_POST['reservation_id'] ?? null, 'reservation id');
    if (empty($_FILES['proof'])) throw new Exception("No file uploaded");

    $db = new Database();
    $pdo = $db->getPdo();

    // Verify reservation belongs to customer and method is bank_transfer
    $stmt = $pdo->prepare("
        SELECT * FROM property_reservations
        WHERE id = ? AND customer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$reservation_id, $customer_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) throw new Exception("Reservation not found");
    if ($res['payment_method'] !== 'bank_transfer') throw new Exception("Not bank transfer reservation");

    // upload directory
    $uploadDir = __DIR__ . "/../../uploads/payments/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $uploadInfo = validateUpload(
        $_FILES['proof'],
        ['pdf', 'jpg', 'jpeg', 'png'],
        ['application/pdf', 'image/jpeg', 'image/png'],
        10 * 1024 * 1024
    );
    $safeName = "proof_" . $reservation_id . "_" . time() . "." . $uploadInfo['ext'];
    $targetPath = $uploadDir . $safeName;

    if (!move_uploaded_file($_FILES['proof']['tmp_name'], $targetPath)) {
        throw new Exception("Upload failed");
    }

    // URL path (store relative URL)
    $fileUrl = "uploads/payments/" . $safeName;

    $pdo->beginTransaction();

    // Update reservation proof + mark paid
    $stmtUp = $pdo->prepare("
        UPDATE property_reservations
        SET proof_url = ?, payment_status = 'paid', reservation_status = 'PAID_CONFIRMED', ready_for_final = 1
        WHERE id = ?
    ");
    $stmtUp->execute([$fileUrl, $reservation_id]);

    // Lock property after payment
    $pdo->prepare("
        UPDATE properties
        SET status = 'Pending',
            reserved_by_customer_id = ?,
            reserved_until = NULL
        WHERE id = ? AND status <> 'Sold'
    ")->execute([
        $customer_id,
        (int)$res['property_id']
    ]);

    // Update payments
    $stmtPay = $pdo->prepare("
        UPDATE payments
        SET status = 'paid'
        WHERE reservation_id = ?
          AND status <> 'paid'
    ");
    $stmtPay->execute([$reservation_id]);

    if ($stmtPay->rowCount() === 0) {
        $pdo->prepare("
            INSERT INTO payments (
                type, reference_id, reservation_id, amount, currency,
                method, stripe_session_id, status
            ) VALUES (
                'property_reservation', ?, ?, ?, 'MUR',
                'bank_transfer', NULL, 'paid'
            )
        ")->execute([
            $reservation_id,
            $reservation_id,
            $res['reservation_fee'] ?? 0
        ]);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Proof uploaded and payment marked as paid",
        "proof_url" => $fileUrl
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
