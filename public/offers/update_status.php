<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    requireLogin();
    requireRole('agent');
    requireCsrf();

    $input = json_decode(file_get_contents("php://input"), true);
    $offer_id = $input['offer_id'] ?? null;
    $status   = $input['status'] ?? null;
    $counter  = $input['counter_price'] ?? null;
    $message  = $input['message'] ?? null;

    if (!$offer_id || !is_numeric($offer_id)) {
        throw new Exception('Invalid offer');
    }

    if (!in_array($status, ['accepted','rejected','countered'], true)) {
        throw new Exception('Invalid status');
    }

    $db = new Database();
    $pdo = $db->getPdo();

    $agent_id = (int)$_SESSION['agent_id'];
    $agent_user_id = (int)$_SESSION['user_id'];
    if ($agent_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([$agent_user_id]);
        $agent_id = (int)$stmt->fetchColumn();
    }
    if ($agent_id <= 0) {
        throw new Exception('Unauthorized');
    }

    $stmt = $pdo->prepare("
        SELECT
            o.*,
            p.status AS property_status,
            p.price AS listed_price,
            u.role AS customer_role,
            u.is_premium AS customer_is_premium
        FROM property_offers o
        INNER JOIN properties p ON p.id = o.property_id
        INNER JOIN users u ON u.id = o.customer_id
        WHERE o.id = ? AND o.agent_id = ?
        LIMIT 1
    ");
    $stmt->execute([$offer_id, $agent_id]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer) throw new Exception('Offer not found');

    if ((int)$offer['customer_id'] === $agent_user_id) {
        throw new Exception('Agent cannot accept own offer');
    }

    if ($status === 'accepted' && $offer['status'] !== 'pending') {
        throw new Exception('Only pending offers can be accepted');
    }

    if ($status === 'rejected' && $offer['status'] !== 'pending') {
        throw new Exception('Only pending offers can be rejected');
    }

    if ($status === 'countered' && !in_array($offer['status'], ['pending','countered'], true)) {
        throw new Exception('Offer cannot be countered in current state');
    }

    if (!empty($offer['offer_expires_at']) && $status === 'accepted') {
        if (strtotime($offer['offer_expires_at']) < strtotime('now')) {
            throw new Exception('Offer has expired');
        }
    }

    if ($status === 'accepted' && $offer['property_status'] === 'Sold') {
        throw new Exception('Property is not available');
    }
    if ($status === 'accepted') {
        $paidCheck = $pdo->prepare("
            SELECT 1
            FROM property_reservations
            WHERE property_id = ?
              AND cancelled_at IS NULL
              AND (reservation_status = 'PAID_CONFIRMED' OR payment_status = 'paid')
            LIMIT 1
        ");
        $paidCheck->execute([$offer['property_id']]);
        if ($paidCheck->fetchColumn()) {
            throw new Exception('Property is not available');
        }
    }

    $offerIsPremium = ($offer['customer_role'] === 'premium_customer' || (int)$offer['customer_is_premium'] === 1);

    if ($status === 'accepted' && !$offerIsPremium) {
        $premiumPending = $pdo->prepare("
            SELECT 1
            FROM property_offers o
            INNER JOIN users u ON u.id = o.customer_id
            WHERE o.property_id = ?
              AND o.id <> ?
              AND o.status IN ('pending','countered')
              AND (o.offer_expires_at IS NULL OR o.offer_expires_at >= NOW())
              AND (u.role = 'premium_customer' OR u.is_premium = 1)
            LIMIT 1
        ");
        $premiumPending->execute([$offer['property_id'], $offer_id]);
        if ($premiumPending->fetch()) {
            throw new Exception('Premium customer has priority for this property');
        }
    }

    if ($status === 'accepted') {
        $checkAccepted = $pdo->prepare("
            SELECT id
            FROM property_offers
            WHERE property_id = ? AND status = 'accepted'
            LIMIT 1
        ");
        $checkAccepted->execute([$offer['property_id']]);
        if ($checkAccepted->fetch()) {
            throw new Exception('Another offer is already accepted for this property');
        }
    }

    $pdo->beginTransaction();

    if ($status === 'countered') {
        if (!$counter || !is_numeric($counter)) {
            throw new Exception('Counter price required');
        }
        if ((float)$counter < (float)$offer['listed_price']) {
            throw new Exception('Counter offer must be at least the listed price');
        }

        $stmt = $pdo->prepare("
            UPDATE property_offers
            SET offer_price = ?, message = COALESCE(?, message), status = 'countered'
            WHERE id = ?
        ");
        $stmt->execute([$counter, $message, $offer_id]);
    }

    if ($status === 'rejected') {
        $stmt = $pdo->prepare("
            UPDATE property_offers
            SET message = COALESCE(?, message), status = 'rejected'
            WHERE id = ?
        ");
        $stmt->execute([$message, $offer_id]);
    }

    if ($status === 'accepted') {
        if ((float)$offer['offer_price'] < (float)$offer['listed_price']) {
            throw new Exception('Offer must be at least the listed price');
        }
        $stmt = $pdo->prepare("
            UPDATE property_offers
            SET message = COALESCE(?, message), status = 'accepted'
            WHERE id = ?
        ");
        $stmt->execute([$message, $offer_id]);

        // reject other offers
        $stmt = $pdo->prepare("
            UPDATE property_offers
            SET status = 'rejected'
            WHERE property_id = ? AND id <> ? AND status IN ('pending','countered')
        ");
        $stmt->execute([$offer['property_id'], $offer_id]);

        // Reservation remains available until payment is confirmed.
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Offer updated successfully'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
