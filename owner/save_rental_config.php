<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy rental config save endpoint.
// Safe to remove after: 2026-06-30 (rental packages now use /rental/save_rental_package.php).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    requireLogin();
    requireRole('owner');
    requireCsrf();

    $userId = (int) $_SESSION['user_id'];

    $raw = file_get_contents("php://input");
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        throw new Exception("Invalid JSON payload");
    }
    $payload = sanitize_array($payload ?? []);

    $propertyId = v_int($payload['property_id'] ?? null, 'property id');
    $rentalConfig = $payload['rental_config'] ?? [];
    if (!is_array($rentalConfig)) {
        bad_request('rental_config must be an object.');
    }

    $pdo = (new Database())->getPdo();

    // 1) Get owner ID
    $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ?");
    $stmt->execute([$userId]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$owner) {
        throw new Exception("Not registered as an owner.");
    }

    $ownerId = (int) $owner['id'];

    // 2) Validate that the property belongs to this owner
    $stmt = $pdo->prepare("SELECT * FROM properties WHERE id = ? AND owner_id = ?");
    $stmt->execute([$propertyId, $ownerId]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        throw new Exception("Property not found or not owned by you.");
    }

    $pdo->beginTransaction();

    // 3) Delete older rental configuration
    $del = $pdo->prepare("DELETE FROM rental_properties WHERE property_id = ?");
    $del->execute([$propertyId]);

    // 4) Insert new configuration
    $insert = $pdo->prepare("
        INSERT INTO rental_properties (
            property_id, rental_type,
            price_nightly, price_daily,
            price_monthly, price_yearly,
            min_stay_nights, max_stay_nights,
            max_guests, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $allowedTypes = ['short_term','long_term','corporate','hotel'];
    $activeCount = 0;

    foreach ($rentalConfig as $type => $cfg) {
        if (!in_array($type, $allowedTypes, true)) {
            continue;
        }
        if (!is_array($cfg)) {
            continue;
        }
        $cfg = sanitize_array($cfg);

        $isActive = v_bool($cfg['is_active'] ?? 0, 'is active', false) ?? 0;
        if ($isActive) {
            $activeCount++;
        }

        $priceNightlyRaw = $cfg['price_nightly'] ?? null;
        if ($priceNightlyRaw === '') $priceNightlyRaw = null;
        $priceDailyRaw = $cfg['price_daily'] ?? null;
        if ($priceDailyRaw === '') $priceDailyRaw = null;
        $priceMonthlyRaw = $cfg['price_monthly'] ?? null;
        if ($priceMonthlyRaw === '') $priceMonthlyRaw = null;
        $priceYearlyRaw = $cfg['price_yearly'] ?? null;
        if ($priceYearlyRaw === '') $priceYearlyRaw = null;
        $minStayRaw = $cfg['min_stay_nights'] ?? null;
        if ($minStayRaw === '') $minStayRaw = null;
        $maxStayRaw = $cfg['max_stay_nights'] ?? null;
        if ($maxStayRaw === '') $maxStayRaw = null;
        $maxGuestsRaw = $cfg['max_guests'] ?? null;
        if ($maxGuestsRaw === '') $maxGuestsRaw = null;

        $priceNightly = v_float($priceNightlyRaw, 'price nightly', 0, 1000000000, false);
        $priceDaily = v_float($priceDailyRaw, 'price daily', 0, 1000000000, false);
        $priceMonthly = v_float($priceMonthlyRaw, 'price monthly', 0, 1000000000, false);
        $priceYearly = v_float($priceYearlyRaw, 'price yearly', 0, 1000000000, false);
        $minStay = v_int($minStayRaw, 'min stay nights', 0, 3650, false);
        $maxStay = v_int($maxStayRaw, 'max stay nights', 0, 3650, false);
        $maxGuests = v_int($maxGuestsRaw, 'max guests', 0, 1000, false);

        $insert->execute([
            $propertyId,
            $type,
            $priceNightly,
            $priceDaily,
            $priceMonthly,
            $priceYearly,
            $minStay,
            $maxStay,
            $maxGuests,
            $isActive
        ]);
    }

    // 5) If ANY rental type enabled → set listing_type = "For Rent" (ID = 2)
    if ($activeCount > 0) {
        $update = $pdo->prepare("UPDATE properties SET listing_type_id = 2 WHERE id = ?");
        $update->execute([$propertyId]);
    }

    $rentalStatus = $activeCount > 0 ? 'Published' : 'Draft';
    $statusStmt = $pdo->prepare("UPDATE properties SET rental_status = ? WHERE id = ?");
    $statusStmt->execute([$rentalStatus, $propertyId]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Rental settings saved successfully"
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
