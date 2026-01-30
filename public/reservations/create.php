<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    requireLogin();
    requireRole(['customer', 'premium_customer']);
    requireCsrf();

    $customer_id = (int)$_SESSION['user_id'];

    $input = json_decode(file_get_contents("php://input"), true);

    $offer_id    = $input['offer_id'] ?? null;
    $property_id = $input['property_id'] ?? null;

    if (!$offer_id || !is_numeric($offer_id) || !$property_id || !is_numeric($property_id)) {
        throw new Exception("Missing data");
    }

    $db = new Database();
    $pdo = $db->getPdo();

    $expiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));

    $pdo->beginTransaction();

    $conflict = function (string $message) use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "error" => $message
        ]);
        exit;
    };

    // Lock property row
    $propStmt = $pdo->prepare("
        SELECT id, status, is_published, title, location, price
        FROM properties
        WHERE id = ?
        FOR UPDATE
    ");
    $propStmt->execute([$property_id]);
    $property = $propStmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        throw new Exception("Property not found");
    }

    // Lock offer row
    $offerStmt = $pdo->prepare("
        SELECT id, status, property_id, customer_id, offer_expires_at, offer_price
        FROM property_offers
        WHERE id = ?
        FOR UPDATE
    ");
    $offerStmt->execute([$offer_id]);
    $offer = $offerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer) {
        throw new Exception("Offer not found");
    }
    if ((int)$offer['customer_id'] !== (int)$customer_id) {
        throw new Exception("Offer does not belong to this customer");
    }
    if ((int)$offer['property_id'] !== (int)$property_id) {
        throw new Exception("Offer does not match property");
    }
    if ($offer['status'] !== 'accepted') {
        throw new Exception("Reservation allowed only after offer accepted");
    }
    if (!empty($offer['offer_expires_at']) && strtotime($offer['offer_expires_at']) < time()) {
        throw new Exception("Offer has expired");
    }
    if (trim((string)($property['title'] ?? '')) === '' || trim((string)($property['location'] ?? '')) === '') {
        throw new Exception("Property data is incomplete");
    }
    if (empty($property['price']) || (float)$property['price'] <= 0) {
        throw new Exception("Property price must be greater than 0");
    }
    if ((int)$property['is_published'] !== 1) {
        throw new Exception("Property not published");
    }
    if ($property['status'] === 'Sold') {
        throw new Exception("Property not available for reservation");
    }

    $reservationFee = round(((float)$offer['offer_price']) * 0.02, 2);
    // Stripe Checkout limit: max 999,999.99 per line item
    $maxStripeAmount = 999999.99;
    if ($reservationFee > $maxStripeAmount) {
        $reservationFee = $maxStripeAmount;
    }
    if ($reservationFee <= 0) {
        throw new Exception("Invalid reservation fee");
    }

    // Check active reservation row
    $check = $pdo->prepare("
        SELECT id
        FROM property_reservations
        WHERE property_id = ?
          AND cancelled_at IS NULL
          AND (
              reservation_status = 'PAID_CONFIRMED'
              OR payment_status = 'paid'
          )
        LIMIT 1
        FOR UPDATE
    ");
    $check->execute([$property_id]);
    if ($check->fetch()) {
        $conflict("Property already reserved");
    }

    // Prevent duplicate reservation
    $check = $pdo->prepare("
        SELECT id FROM property_reservations
        WHERE offer_id = ? AND customer_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $check->execute([$offer_id, $customer_id]);

    if ($check->fetch()) {
        $conflict("Reservation already exists");
    }

    $stmt = $pdo->prepare("
        INSERT INTO property_reservations
        (property_id, offer_id, customer_id, reservation_fee, payment_status, reservation_status, expires_at)
        VALUES (?, ?, ?, ?, 'pending', 'ACCEPTED_AWAITING_PAYMENT', ?)
    ");

    $stmt->execute([
        $property_id,
        $offer_id,
        $customer_id,
        $reservationFee,
        $expiresAt
    ]);

    $pdo->commit();

    $reservationId = (int)$pdo->lastInsertId();
    if ($reservationId <= 0) {
        // Fallback for tables without AUTO_INCREMENT id
        $idStmt = $pdo->prepare("
            SELECT id
            FROM property_reservations
            WHERE offer_id = ? AND customer_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $idStmt->execute([$offer_id, $customer_id]);
        $reservationId = (int)$idStmt->fetchColumn();
    }

    echo json_encode([
        "success" => true,
        "reservation_id" => $reservationId
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "error" => "Property already reserved"
        ]);
        exit;
    }
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
