<?php

require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header("Content-Type: application/json");

// --------------------------------------------------
// ✅ CORS preflight
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --------------------------------------------------
// ✅ Session start
// --------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --------------------------------------------------
// ✅ Inline auth check (NO auth.php)
// --------------------------------------------------
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'owner') {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "error"   => "Unauthorized"
    ]);
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);
    $input = sanitize_array($input ?? []);
    $reservation_id = v_int($input['reservation_id'] ?? null, 'reservation id');

    $pdo = (new Database())->getPdo();

    // --------------------------------------------------
    // 1️⃣ Load reservation + property + meeting
    // OPTION B: accepted OR completed meeting is OK
    // --------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            p.owner_id,
            p.id AS property_id,
            m.status AS meeting_status
        FROM property_reservations r
        JOIN properties p ON p.id = r.property_id
        LEFT JOIN meetings m
            ON m.property_id = r.property_id
           AND m.owner_id = p.owner_id
        WHERE r.id = ?
        ORDER BY m.id DESC
        LIMIT 1
    ");
    $stmt->execute([$reservation_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) {
        throw new Exception("Reservation not found");
    }

    // --------------------------------------------------
    // 2️⃣ Ownership check
    // --------------------------------------------------
    if ((int)$res['owner_id'] !== (int)$_SESSION['owner_id']) {
        throw new Exception("Unauthorized owner");
    }

    // --------------------------------------------------
    // 3️⃣ Validations
    // --------------------------------------------------
    if ($res['payment_status'] !== 'paid') {
        throw new Exception("Reservation not paid");
    }

    if (empty($res['meeting_status'])) {
        throw new Exception("Meeting not scheduled");
    }

    if (!in_array($res['meeting_status'], ['accepted', 'completed'], true)) {
        throw new Exception("Meeting not accepted yet");
    }

    if ($res['decision_status'] !== 'PENDING') {
        throw new Exception("Decision already taken");
    }

    // --------------------------------------------------
    // 4️⃣ Transaction
    // --------------------------------------------------
    $pdo->beginTransaction();

    // Mark reservation as rejected
    $stmt = $pdo->prepare("
        UPDATE property_reservations
        SET
            decision_status = 'REJECTED',
            refund_status = 'pending',
            cancelled_at = NOW(),
            cancelled_by = 'owner'
        WHERE id = ?
    ");
    $stmt->execute([$reservation_id]);

    // Unlock property
    $stmt = $pdo->prepare("
        UPDATE properties
        SET
            status = 'Available',
            reserved_by_customer_id = NULL,
            reserved_until = NULL
        WHERE id = ?
    ");
    $stmt->execute([$res['property_id']]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Reservation cancelled. Refund will be processed."
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
