<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy rental calculation endpoint.
// Safe to remove after: 2026-06-30 (if not used by pricing workflows).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');
session_start();

try {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception("Not authenticated");
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $input = sanitize_array($input ?? []);

    $propertyId = v_int($input['property_id'] ?? null, 'property id');
    $rentalType = v_enum($input['rental_type'] ?? null, 'rental type', ['short_term','long_term','corporate','hotel']);
    $checkin    = v_date($input['checkin'] ?? null, 'checkin');
    $checkout   = v_date($input['checkout'] ?? null, 'checkout');
    $guests     = v_int($input['guests'] ?? 1, 'guests', 1, 50, false) ?? 1;

    if (!$propertyId || !$rentalType || !$checkin || !$checkout) {
        throw new Exception("Missing booking data");
    }

    $pdo = (new Database())->getPdo();

    // Validate nights
    $daysStmt = $pdo->prepare("SELECT DATEDIFF(?, ?) AS nights");
    $daysStmt->execute([$checkout, $checkin]);
    $nights = (int)$daysStmt->fetchColumn();
    if ($nights <= 0) throw new Exception("Invalid date range");

    // Load rental config
    $rpStmt = $pdo->prepare("
        SELECT price_daily, price_nightly, price_monthly, price_yearly,
               min_stay_days, max_stay_days, max_guests
        FROM rental_properties
        WHERE property_id = ? AND rental_type = ?
        LIMIT 1
    ");
    $rpStmt->execute([$propertyId, $rentalType]);
    $rp = $rpStmt->fetch(PDO::FETCH_ASSOC);
    if (!$rp) throw new Exception("Rental configuration not found");

    // Validate guests
    if (!empty($rp['max_guests']) && $guests > (int)$rp['max_guests']) {
        throw new Exception("Maximum guests allowed is {$rp['max_guests']}");
    }

    // Validate min/max stay
    if (!empty($rp['min_stay_days']) && $nights < (int)$rp['min_stay_days']) {
        throw new Exception("Minimum stay is {$rp['min_stay_days']} nights");
    }
    if (!empty($rp['max_stay_days']) && $nights > (int)$rp['max_stay_days']) {
        throw new Exception("Maximum stay is {$rp['max_stay_days']} nights");
    }

    // Availability check (uses rental_availability table)
    $aStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM rental_availability
        WHERE property_id = ?
          AND date >= ?
          AND date < ?
          AND is_available = 0
    ");
    $aStmt->execute([$propertyId, $checkin, $checkout]);
    $blocked = (int)$aStmt->fetchColumn();
    if ($blocked > 0) {
        throw new Exception("Selected dates are not available");
    }

    // Pricing (simple baseline + optional override later)
    $totalPrice = 0.0;

    if ($rentalType === 'short_term' || $rentalType === 'hotel') {
        $pricePerNight = (float)($rp['price_nightly'] ?? 0);
        if ($pricePerNight <= 0) throw new Exception("Nightly price not configured");
        $totalPrice = $pricePerNight * $nights;
    } elseif ($rentalType === 'long_term' || $rentalType === 'corporate') {
        $months = (int)ceil($nights / 30.0);

        if (!empty($rp['price_monthly']) && (float)$rp['price_monthly'] > 0) {
            $totalPrice = $months * (float)$rp['price_monthly'];
        } elseif (!empty($rp['price_yearly']) && (float)$rp['price_yearly'] > 0) {
            $years = $months / 12.0;
            $totalPrice = ceil($years * (float)$rp['price_yearly']);
        } else {
            throw new Exception("Monthly/yearly price not configured");
        }
    } else {
        throw new Exception("Invalid rental type");
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'property_id'  => (int)$propertyId,
            'rental_type'  => $rentalType,
            'checkin'      => $checkin,
            'checkout'     => $checkout,
            'guests'       => $guests,
            'nights'       => $nights,
            'total_price'  => round($totalPrice, 2)
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
