<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../lib/validation.php';

use Stripe\Stripe;
use Stripe\Checkout\Session;

header("Content-Type: application/json");
function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(["success" => false, "error" => $msg]);
    exit;
}

/* =========================
   AUTH
========================= */
if (empty($_SESSION['user_id'])) {
    json_error("Unauthorized", 401);
}

requireCsrf();

$user_id = (int)$_SESSION['user_id'];

/* =========================
   INPUT
========================= */
$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    json_error("Invalid JSON body");
}
$input = sanitize_array($input);

$booking_id = v_int($input['booking_id'] ?? null, 'booking id');

/* =========================
   DB
========================= */
$pdo = (new Database())->getPdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================
   Resolve customer
========================= */
$stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
$stmt->execute([$user_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    json_error("Not a customer", 403);
}

$tenant_id = (int)$customer['id'];

/* =========================
   Load booking (BACKUP TABLE)
========================= */
$stmt = $pdo->prepare("
    SELECT rb.*, p.title AS property_title
    FROM rental_bookings_backup rb
    INNER JOIN properties p ON p.id = rb.property_id
    WHERE rb.id = ?
      AND rb.tenant_id = ?
      AND rb.status = 'accepted'
      AND rb.payment_status = 'unpaid'
    LIMIT 1
");
$stmt->execute([$booking_id, $tenant_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    json_error("Booking not eligible for payment", 404);
}

$amount = (float)$booking['total_price'];
if ($amount <= 0) {
    json_error("Invalid booking amount");
}

/* =========================
   Prevent duplicate payment
========================= */
$dup = $pdo->prepare("
    SELECT id
    FROM payments
    WHERE type = 'rental_booking'
      AND reference_id = ?
      AND status IN ('initiated','paid')
    LIMIT 1
");
$dup->execute([$booking_id]);

if ($dup->fetch()) {
    json_error("Payment already initiated", 409);
}

/* =========================
   STRIPE
========================= */
$stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY');
if (!$stripeSecret) {
    json_error("Stripe is not configured", 500);
}
Stripe::setApiKey($stripeSecret);

try {
    $pdo->beginTransaction();

    $session = Session::create([
        "mode" => "payment",
        "line_items" => [[
            "price_data" => [
                "currency" => "mur",
                "product_data" => [
                    "name" => "Rental Payment",
                    "description" => "Rental for " . $booking['property_title']
                ],
                "unit_amount" => (int) round($amount * 100),
            ],
            "quantity" => 1,
        ]],
        "metadata" => [
            "type" => "rental_booking",
            "booking_id" => (string)$booking_id,
            "tenant_id"  => (string)$tenant_id,
        ],
        "success_url" => "http://localhost:5173/rentals/payment/success?booking_id={$booking_id}",
        "cancel_url"  => "http://localhost:5173/rentals/payment/cancel?booking_id={$booking_id}",
    ]);

    /* Insert initiated payment */
    $pdo->prepare("
        INSERT INTO payments (
            type, reference_id, amount, currency,
            method, stripe_session_id, status
        ) VALUES (
            'rental_booking', ?, ?, 'MUR',
            'stripe', ?, 'initiated'
        )
    ")->execute([
        $booking_id,
        $amount,
        $session->id
    ]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "checkout_url" => $session->url
    ]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error("Payment setup failed: " . $e->getMessage(), 500);
}
