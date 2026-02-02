<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy rental booking endpoint.
// Safe to remove after: 2026-06-30 (use /public/rentals_create_booking.php).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');
try {
    requireLogin();
    requireRole(['customer', 'premium_customer']);
    requireCsrf();

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception("Not authenticated");
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $input = sanitize_array($input ?? []);

    $propertyId = v_int($input['property_id'] ?? null, 'property id');
    $rentalType = v_enum($input['rental_type'] ?? null, 'rental type', ['short_term', 'long_term', 'corporate', 'hotel']);
    $checkin    = v_date($input['checkin'] ?? null, 'checkin');
    $checkout   = v_date($input['checkout'] ?? null, 'checkout');
    $guests     = v_int($input['guests'] ?? 1, 'guests', 1, 50, false) ?? 1;

    if (!$propertyId || !$rentalType || !$checkin || !$checkout) {
        throw new Exception("Missing booking data");
    }

    $pdo = (new Database())->getPdo();

    // Validate dates
    $daysStmt = $pdo->prepare("SELECT DATEDIFF(?, ?) AS nights");
    $daysStmt->execute([$checkout, $checkin]);
    $nights = (int)$daysStmt->fetchColumn();
    if ($nights <= 0) {
        throw new Exception("Invalid date range");
    }

    // Get rental config
    $rpStmt = $pdo->prepare("
        SELECT price_nightly, price_monthly, price_yearly, min_stay_days, max_stay_days
        FROM rental_properties
        WHERE property_id = ? AND rental_type = ?
        LIMIT 1
    ");
    $rpStmt->execute([$propertyId, $rentalType]);
    $rp = $rpStmt->fetch(PDO::FETCH_ASSOC);
    if (!$rp) {
        throw new Exception("Rental configuration not found for this type");
    }

    // Check min/max stay if set
    if (!empty($rp['min_stay_days']) && $nights < (int)$rp['min_stay_days']) {
        throw new Exception("Minimum stay is {$rp['min_stay_days']} nights for this rental.");
    }
    if (!empty($rp['max_stay_days']) && $nights > (int)$rp['max_stay_days']) {
        throw new Exception("Maximum stay is {$rp['max_stay_days']} nights for this rental.");
    }

    // Basic pricing logic
    $totalPrice = 0.0;

    if ($rentalType === 'short_term' || $rentalType === 'hotel') {
        $pricePerNight = (float)$rp['price_nightly'];
        if ($pricePerNight <= 0) throw new Exception("Nightly price not configured.");
        $totalPrice = $pricePerNight * $nights;
    } elseif ($rentalType === 'long_term' || $rentalType === 'corporate') {
        // Approximate months = ceil(nights / 30)
        $months = (int)ceil($nights / 30.0);
        if ($rp['price_monthly'] !== null && (float)$rp['price_monthly'] > 0) {
            $totalPrice = $months * (float)$rp['price_monthly'];
        } elseif ($rp['price_yearly'] !== null && (float)$rp['price_yearly'] > 0) {
            // Approx. years
            $years = $months / 12.0;
            $totalPrice = ceil($years * (float)$rp['price_yearly']);
        } else {
            throw new Exception("Monthly/yearly price not configured.");
        }
    }

    // Insert booking (source of truth)
    $stmt = $pdo->prepare("
        INSERT INTO rental_bookings_backup
        (tenant_id, property_id, rental_type, checkin, checkout, guests, total_price, status, payment_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid')
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $propertyId,
        $rentalType,
        $checkin,
        $checkout,
        $guests,
        $totalPrice
    ]);
    $backupId = (int)$pdo->lastInsertId();

    // Compatibility mirror (best effort)
    try {
        $mirror = $pdo->prepare("
            INSERT INTO rental_bookings
            (tenant_id, property_id, rental_type, checkin, checkout, guests, total_price, status, payment_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid')
        ");
        $mirror->execute([
            $_SESSION['user_id'],
            $propertyId,
            $rentalType,
            $checkin,
            $checkout,
            $guests,
            $totalPrice
        ]);
    } catch (Throwable $e) {
        // Ignore mirror errors to avoid breaking booking flow
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'booking_id' => $backupId,
            'total_price' => $totalPrice,
            'status' => 'pending'
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
