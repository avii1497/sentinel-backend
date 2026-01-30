<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/env.php';

use Stripe\Stripe;
use Stripe\Checkout\Session;

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // Auth
    if (empty($_SESSION['user_id'])) {
        throw new Exception("Unauthorized");
    }

    requireRole(['customer', 'premium_customer']);
    requireCsrf();

    $user_id = (int)$_SESSION['user_id'];

    // Input
    $input = json_decode(file_get_contents("php://input"), true);
    $reservation_id = $input['reservation_id']
        ?? ($_POST['reservation_id'] ?? null)
        ?? ($_GET['reservation_id'] ?? null);

    if (!$reservation_id || !is_numeric($reservation_id)) {
        throw new Exception("Invalid reservation_id");
    }

    $db = new Database();
    $pdo = $db->getPdo();

    // Load reservation
    $stmt = $pdo->prepare("
        SELECT
            r.*,
            p.title,
            p.id AS property_id,
            p.status AS property_status
        FROM property_reservations r
        JOIN properties p ON p.id = r.property_id
        WHERE r.id = ? AND r.customer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$reservation_id, $user_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        throw new Exception("Reservation not found");
    }

    if ($reservation['payment_status'] === 'paid') {
        throw new Exception("Reservation already paid");
    }
    if ($reservation['payment_status'] !== 'pending') {
        throw new Exception("Reservation not payable");
    }
    if (($reservation['reservation_status'] ?? '') === 'PAID_CONFIRMED') {
        throw new Exception("Reservation already confirmed");
    }
    if (!empty($reservation['reservation_status']) && $reservation['reservation_status'] !== 'ACCEPTED_AWAITING_PAYMENT') {
        throw new Exception("Reservation not payable");
    }

    if (!empty($reservation['expires_at']) && strtotime($reservation['expires_at']) < strtotime('now')) {
        throw new Exception("Reservation has expired");
    }
    if ($reservation['property_status'] === 'Sold') {
        throw new Exception("Property not available");
    }

    $conflict = $pdo->prepare("
        SELECT id
        FROM property_reservations
        WHERE property_id = ?
          AND id <> ?
          AND cancelled_at IS NULL
          AND (reservation_status = 'PAID_CONFIRMED' OR payment_status = 'paid')
        LIMIT 1
    ");
    $conflict->execute([(int)$reservation['property_id'], (int)$reservation_id]);
    if ($conflict->fetch()) {
        throw new Exception("Property already confirmed by another reservation");
    }

    $offerStmt = $pdo->prepare("
        SELECT status
        FROM property_offers
        WHERE id = ?
        LIMIT 1
    ");
    $offerStmt->execute([$reservation['offer_id']]);
    $offerStatus = $offerStmt->fetchColumn();
    if ($offerStatus !== 'accepted') {
        throw new Exception("Offer not accepted");
    }

    // Stripe
    $stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
    if (!$stripeSecret) {
        throw new Exception("Missing STRIPE_SECRET_KEY");
    }
    Stripe::setApiKey($stripeSecret);

    // Prevent duplicate payment (reuse existing session if possible)
    $dup = $pdo->prepare("
        SELECT id, status, stripe_session_id
        FROM payments
        WHERE type = 'property_reservation'
          AND reference_id = ?
          AND status IN ('initiated','paid')
        ORDER BY id DESC
        LIMIT 1
    ");
    $dup->execute([$reservation_id]);
    $dupRow = $dup->fetch(PDO::FETCH_ASSOC);
    if ($dupRow) {
        if ($dupRow['status'] === 'paid') {
            throw new Exception("Reservation already paid");
        }
        if (!empty($dupRow['stripe_session_id'])) {
            try {
                $existing = Session::retrieve($dupRow['stripe_session_id']);
                if (!empty($existing->url)) {
                    echo json_encode([
                        "success" => true,
                        "checkout_url" => $existing->url,
                        "existing" => true
                    ]);
                    exit;
                }
            } catch (Throwable $e) {
                // Fall through to create a new session if retrieval fails.
            }
        }
    }

    $pdo->beginTransaction();

    if ((float)$reservation['reservation_fee'] > 999999.99) {
        throw new Exception("Reservation fee exceeds Stripe limit. Please contact support.");
    }

    $session = Session::create([
        "mode" => "payment",
        "line_items" => [[
            "price_data" => [
                "currency" => "mur",
                "product_data" => [
                    "name" => "Property Reservation Fee",
                    "description" => "Reservation for " . $reservation['title']
                ],
                "unit_amount" => (int) round($reservation['reservation_fee'] * 100)
            ],
            "quantity" => 1
        ]],
        "metadata" => [
            "type"           => "property_reservation",
            "reservation_id" => (string)$reservation_id,
            "property_id"    => (string)$reservation['property_id'],
            "user_id"        => (string)$user_id
        ],
        "success_url" => "http://localhost:5173/reservation/success",
        "cancel_url"  => "http://localhost:5173/reservation/cancel"
    ]);

    $pdo->prepare("
        INSERT INTO payments (
            type, reference_id, reservation_id, amount, currency,
            method, stripe_session_id, status
        ) VALUES (
            'property_reservation', ?, ?, ?, 'MUR',
            'stripe', ?, 'initiated'
        )
    ")->execute([
        $reservation_id,
        $reservation_id,
        $reservation['reservation_fee'],
        $session->id
    ]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "checkout_url" => $session->url
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
