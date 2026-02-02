<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // -----------------------------
    // AUTH
    // -----------------------------
    requireLogin();

    $db = new Database();
    $pdo = $db->getPdo();

    $role = $_SESSION['role'] ?? null;
    if (!$role && !empty($_SESSION['user_id'])) {
        $userRow = $db->getUserById((int)$_SESSION['user_id']);
        if ($userRow && !empty($userRow['role'])) {
            $role = $userRow['role'];
            $_SESSION['role'] = $role;
        }
    }

    if (!in_array($role, ['customer', 'premium_customer'], true)) {
        http_response_code(403);
        echo json_encode(["success" => false, "error" => "Forbidden"]);
        exit;
    }

    requireCsrf();

    $customer_id = (int)$_SESSION['user_id'];

    // -----------------------------
    // INPUT
    // -----------------------------
    $input = json_decode(file_get_contents("php://input"), true);
    $input = sanitize_array($input ?? []);

    $property_id = v_int($input['property_id'] ?? null, 'property id');
    $offer_price = v_float($input['offer_price'] ?? null, 'offer price', 0.01, 1000000000);
    $message     = v_string($input['message'] ?? '', 'message', 2000, 0, false);

    // -----------------------------
    // PROPERTY VALIDATION
    // -----------------------------
    $stmt = $pdo->prepare("
        SELECT id, title, price, location, assigned_agent_id, status, is_published
        FROM properties
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$property_id]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        throw new Exception('Property not found');
    }

    if (trim((string)($property['title'] ?? '')) === '') {
        throw new Exception('Property title is required');
    }

    if (empty($property['price']) || (float)$property['price'] <= 0) {
        throw new Exception('Property price must be greater than 0');
    }

    if (trim((string)($property['location'] ?? '')) === '') {
        throw new Exception('Property location is required');
    }

    if ((int)$property['is_published'] !== 1) {
        throw new Exception('Property not published');
    }

    if ($property['status'] === 'Sold') {
        throw new Exception('Property not available');
    }

    $paidCheck = $pdo->prepare("
        SELECT 1
        FROM property_reservations
        WHERE property_id = ?
          AND cancelled_at IS NULL
          AND (reservation_status = 'PAID_CONFIRMED' OR payment_status = 'paid')
        LIMIT 1
    ");
    $paidCheck->execute([$property_id]);
    if ($paidCheck->fetchColumn()) {
        throw new Exception('Property not available');
    }

    if ((float)$offer_price < (float)$property['price']) {
        throw new Exception('Offer must be at least the listed price');
    }

    if (empty($property['assigned_agent_id'])) {
        throw new Exception('No agent assigned to property');
    }

    $agent_id = (int)$property['assigned_agent_id'];

    $offer_expires_at = $input['offer_expires_at'] ?? null;
    if (!empty($offer_expires_at)) {
        $offer_expires_at = v_string($offer_expires_at, 'offer expires at', 25, 1, true);
        $ts = strtotime($offer_expires_at);
        if ($ts === false) {
            throw new Exception('Invalid offer expiration date');
        }
        if ($ts < strtotime('today')) {
            throw new Exception('Offer expiry must be today or later');
        }
        $offer_expires_at = date('Y-m-d H:i:s', $ts);
    } else {
        $offer_expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
    }

    // -----------------------------
    // PREVENT DUPLICATE OFFER
    // -----------------------------
    $stmt = $pdo->prepare("
        SELECT id
        FROM property_offers
        WHERE property_id = ? AND customer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$property_id, $customer_id]);

    if ($stmt->fetch()) {
        throw new Exception('You already submitted an offer for this property');
    }

    // -----------------------------
    // INSERT OFFER
    // -----------------------------
    $stmt = $pdo->prepare("
        INSERT INTO property_offers
        (property_id, customer_id, agent_id, offer_price, message, status, offer_expires_at)
        VALUES (?, ?, ?, ?, ?, 'pending', ?)
    ");
    $stmt->execute([
        $property_id,
        $customer_id,
        $agent_id,
        $offer_price,
        $message,
        $offer_expires_at
    ]);

    echo json_encode([
        'success'  => true,
        'message'  => 'Offer submitted successfully',
        'offer_id' => (int)$pdo->lastInsertId()
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
