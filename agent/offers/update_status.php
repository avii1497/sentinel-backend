<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header('Content-Type: application/json');

try {
    requireLogin();
    requireRole('agent');
    requireCsrf();

    $agent_user_id = (int)$_SESSION['user_id'];
    $agent_id = (int)$_SESSION['agent_id'];
    if ($agent_id <= 0) {
        $stmt = (new Database())->getPdo()->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([$agent_user_id]);
        $agent_id = (int)$stmt->fetchColumn();
    }
    if ($agent_id <= 0) {
        throw new Exception('Unauthorized');
    }

    $input = json_decode(file_get_contents("php://input"), true);
    $input = sanitize_array($input ?? []);
    $offer_id = v_int($input['offer_id'] ?? null, 'offer id');
    $action = v_enum($input['action'] ?? null, 'action', ['accept', 'reject']);

    $db = new Database();
    $pdo = $db->getPdo();
    $pdo->beginTransaction();

    // Load offer
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
    ");
    $stmt->execute([$offer_id, $agent_id]);
    $offer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer) throw new Exception('Offer not found');

    $offerIsPremium = ($offer['customer_role'] === 'premium_customer' || (int)$offer['customer_is_premium'] === 1);

    if ($action === 'accept' && !$offerIsPremium) {
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

    if ($action === 'accept') {
        if ($offer['status'] !== 'pending') {
            throw new Exception('Only pending offers can be accepted');
        }

        if ((int)$offer['customer_id'] === $agent_user_id) {
            throw new Exception('Agent cannot accept own offer');
        }

        if ($offer['property_status'] === 'Sold') {
            throw new Exception('Property is not available');
        }

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

        if (!empty($offer['offer_expires_at']) && strtotime($offer['offer_expires_at']) < strtotime('now')) {
            throw new Exception('Offer has expired');
        }

        if ((float)$offer['offer_price'] < (float)$offer['listed_price']) {
            throw new Exception('Offer must be at least the listed price');
        }

        // Accept this offer
        $pdo->prepare("
            UPDATE property_offers 
            SET status = 'accepted' 
            WHERE id = ?
        ")->execute([$offer_id]);

        // Reject all other offers for this property
        $pdo->prepare("
            UPDATE property_offers 
            SET status = 'rejected'
            WHERE property_id = ? AND id != ?
        ")->execute([$offer['property_id'], $offer_id]);

    } else {
        if ($offer['status'] !== 'pending') {
            throw new Exception('Only pending offers can be rejected');
        }
        // Reject only this offer
        $pdo->prepare("
            UPDATE property_offers 
            SET status = 'rejected'
            WHERE id = ?
        ")->execute([$offer_id]);
    }

    $pdo->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if (isset($pdo)) $pdo->rollBack();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
