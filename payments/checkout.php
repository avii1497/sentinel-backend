<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../lib/validation.php';

use Stripe\Stripe;
use Stripe\Checkout\Session;

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (
        empty($_SESSION['user_id']) ||
        !in_array($_SESSION['role'], ['customer', 'premium_customer'], true)
    ) {
        throw new Exception("Unauthorized");
    }

    requireCsrf();

    $customer_id = (int)$_SESSION['user_id'];

    $input = json_decode(file_get_contents("php://input"), true) ?? [];
    $input = sanitize_array($input ?? []);
    $reservationRaw = $input['reservation_id'] ?? null;
    if ($reservationRaw === '') $reservationRaw = null;
    $reservation_id = v_int($reservationRaw, 'reservation id', 1, 2147483647, false);

    $db = new Database();
    $pdo = $db->getPdo();

    $stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
    if (!$stripeSecret) {
        throw new Exception("Missing STRIPE_SECRET_KEY");
    }
    Stripe::setApiKey($stripeSecret);

    if ($reservation_id) {
        $stmt = $pdo->prepare("
            SELECT r.*, p.title, p.id AS property_id
            FROM property_reservations r
            JOIN properties p ON p.id = r.property_id
            WHERE r.id = ? AND r.customer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$reservation_id, $customer_id]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reservation) throw new Exception("Reservation not found");
        if ($reservation['payment_status'] === 'paid') {
            throw new Exception("Already paid");
        }

        $session = Session::create([
            "mode" => "payment",
            "line_items" => [[
                "price_data" => [
                    "currency" => "mur",
                    "product_data" => [
                        "name" => "Property Reservation Fee",
                        "description" => "Reservation for: " . $reservation['title']
                    ],
                    "unit_amount" => (int)($reservation['reservation_fee'] * 100)
                ],
                "quantity" => 1
            ]],
            "metadata" => [
                "type" => "property_reservation",
                "reservation_id" => (string)$reservation_id,
                "property_id" => (string)$reservation['property_id']
            ],
            "success_url" => "http://localhost:5173/reservation/success",
            "cancel_url"  => "http://localhost:5173/reservation/cancel"
        ]);
    } else {
        if (($_SESSION['role'] ?? '') === 'premium_customer') {
            throw new Exception("Already premium");
        }

        $premiumAmount = 599.00; // TODO: adjust premium price if needed

        $session = Session::create([
            "mode" => "payment",
            "line_items" => [[
                "price_data" => [
                    "currency" => "mur",
                    "product_data" => [
                        "name" => "Premium Upgrade",
                        "description" => "Upgrade to premium account"
                    ],
                    "unit_amount" => (int)round($premiumAmount * 100)
                ],
                "quantity" => 1
            ]],
            "metadata" => [
                "type" => "premium_upgrade",
                "user_id" => (string)$customer_id
            ],
            "success_url" => "http://localhost:5173/premium/success",
            "cancel_url"  => "http://localhost:5173/premium"
        ]);
    }

    echo json_encode([
        "success" => true,
        "checkout_url" => $session->url
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
