<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

try {
    requireLogin();
    requireRole(['customer', 'premium_customer']);
    requireCsrf();

    // 🔐 Authentication
    if (empty($_SESSION['user_id'])) {
        throw new Exception("Not authenticated");
    }

    $user_id = (int) $_SESSION['user_id'];

    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        $input = [];
    }
    $input = sanitize_array($input);

    $propertyId = v_int($input['property_id'] ?? null, 'property id');
    $rentalType = $input['rental_type'] ?? null;
    if ($rentalType === 'holiday') {
        $rentalType = 'hotel';
    }
    $rentalType = v_enum($rentalType, 'rental type', ['short_term', 'long_term', 'corporate', 'hotel']);
    $checkin    = v_date($input['checkin'] ?? null, 'checkin');
    $checkout   = v_date($input['checkout'] ?? null, 'checkout');
    $guests     = v_int($input['guests'] ?? 1, 'guests', 1, 50, false) ?? 1;

    if (!$propertyId || !$rentalType || !$checkin || !$checkout) {
        throw new Exception("Missing booking data");
    }

    $pdo = (new Database())->getPdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ✅ RESOLVE tenant_id = customers.id
    $stmt = $pdo->prepare("
        SELECT id
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        throw new Exception("Customer profile not found");
    }

    $tenant_id = (int)$customer['id'];

    // 📅 Validate dates (strict format + logical order)
    $checkinDate = DateTime::createFromFormat('Y-m-d', $checkin);
    $checkoutDate = DateTime::createFromFormat('Y-m-d', $checkout);
    if (
        !$checkinDate || $checkinDate->format('Y-m-d') !== $checkin ||
        !$checkoutDate || $checkoutDate->format('Y-m-d') !== $checkout
    ) {
        throw new Exception("Invalid date format");
    }

    $nights = (int)$checkinDate->diff($checkoutDate)->format('%r%a');
    if ($nights < 1) {
        throw new Exception("Invalid date range");
    }

    $pdo->beginTransaction();

    // 🔒 Lock property row to prevent race double-booking
    $lockStmt = $pdo->prepare("SELECT id FROM properties WHERE id = ? FOR UPDATE");
    $lockStmt->execute([$propertyId]);
    if (!$lockStmt->fetchColumn()) {
        throw new Exception("Property not found");
    }

    // 💰 Get rental pricing + rules (lock config row)
    $rpStmt = $pdo->prepare("
        SELECT price_daily, price_nightly, price_monthly, price_yearly,
               min_stay_days, max_stay_days, max_guests
        FROM rental_properties
        WHERE property_id = ? AND rental_type = ?
        LIMIT 1
        FOR UPDATE
    ");
    $rpStmt->execute([$propertyId, $rentalType]);
    $rp = $rpStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rp) {
        throw new Exception("Rental configuration not found");
    }

    // ✅ Enforce max guests
    if (!empty($rp['max_guests']) && $guests > (int)$rp['max_guests']) {
        throw new Exception("Maximum guests allowed is {$rp['max_guests']}");
    }

    // ✅ Enforce min/max stay
    if (!empty($rp['min_stay_days']) && $nights < (int)$rp['min_stay_days']) {
        throw new Exception("Minimum stay is {$rp['min_stay_days']} nights");
    }
    if (!empty($rp['max_stay_days']) && $nights > (int)$rp['max_stay_days']) {
        throw new Exception("Maximum stay is {$rp['max_stay_days']} nights");
    }

    // ✅ Availability check + overrides (lock rows in range)
    $aStmt = $pdo->prepare("
        SELECT date, is_available, price_override
        FROM rental_availability
        WHERE property_id = ?
          AND date >= ?
          AND date < ?
        FOR UPDATE
    ");
    $aStmt->execute([$propertyId, $checkin, $checkout]);
    $availabilityRows = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    $overrideByDate = [];
    foreach ($availabilityRows as $row) {
        if ((int)$row['is_available'] === 0) {
            throw new Exception("Selected dates are not available");
        }
        if ($row['price_override'] !== null && (float)$row['price_override'] > 0) {
            $overrideByDate[$row['date']] = (float)$row['price_override'];
        }
    }

    // ✅ Overlap check (backup table)
    $overlapStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM rental_bookings_backup
        WHERE property_id = ?
          AND rental_type = ?
          AND status IN ('accepted','ongoing')
          AND NOT (checkout <= ? OR checkin >= ?)
        FOR UPDATE
    ");
    $overlapStmt->execute([$propertyId, $rentalType, $checkin, $checkout]);
    if ((int)$overlapStmt->fetchColumn() > 0) {
        throw new Exception("Dates conflict with another accepted rental");
    }

    // ✅ Defensive overlap check (primary table)
    $overlapPrimaryStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM rental_bookings
        WHERE property_id = ?
          AND rental_type = ?
          AND status = 'accepted'
          AND NOT (checkout <= ? OR checkin >= ?)
        FOR UPDATE
    ");
    $overlapPrimaryStmt->execute([$propertyId, $rentalType, $checkin, $checkout]);
    if ((int)$overlapPrimaryStmt->fetchColumn() > 0) {
        throw new Exception("Dates conflict with another accepted rental");
    }

    // 💵 Price calculation (nightly <= 30, monthly > 30)
    $priceNightly = (float)($rp['price_nightly'] ?? 0);
    if ($priceNightly <= 0) {
        $priceNightly = (float)($rp['price_daily'] ?? 0);
    }
    $priceMonthly = (float)($rp['price_monthly'] ?? 0);
    if ($priceMonthly <= 0) {
        $priceYearly = (float)($rp['price_yearly'] ?? 0);
        if ($priceYearly > 0) {
            $priceMonthly = $priceYearly / 12.0;
        }
    }
    $total = 0.0;

    $useOverrides = !empty($overrideByDate);
    if ($useOverrides) {
        $missingBasePrice = false;
        $cursor = clone $checkinDate;
        while ($cursor < $checkoutDate) {
            $dateKey = $cursor->format('Y-m-d');
            $nightly = $overrideByDate[$dateKey] ?? $priceNightly;
            if ($nightly <= 0) {
                $missingBasePrice = true;
                break;
            }
            $total += (float)$nightly;
            $cursor->modify('+1 day');
        }
        if ($missingBasePrice) {
            throw new Exception("Nightly price not configured");
        }
    } else {
        if ($nights <= 30) {
            if ($priceNightly <= 0) {
                throw new Exception("Nightly price not configured");
            }
            $total = $nights * $priceNightly;
        } else {
            if ($priceMonthly <= 0) {
                throw new Exception("Monthly price not configured");
            }
            $months = (int)ceil($nights / 30.0);
            $total = $months * $priceMonthly;
        }
    }

    if ($total <= 0) {
        throw new Exception("Invalid booking amount");
    }

    // 🧾 INSERT BOOKING (PENDING)
    $stmt = $pdo->prepare("
        INSERT INTO rental_bookings_backup (
            tenant_id,
            property_id,
            rental_type,
            checkin,
            checkout,
            guests,
            total_price,
            status,
            payment_status
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid'
        )
    ");

    $stmt->execute([
        $tenant_id,
        $propertyId,
        $rentalType,
        $checkin,
        $checkout,
        $guests,
        $total
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
            $tenant_id,
            $propertyId,
            $rentalType,
            $checkin,
            $checkout,
            $guests,
            $total
        ]);
    } catch (Throwable $e) {
        // Ignore mirror errors to avoid breaking booking flow
    }

    $pdo->commit();

    echo json_encode([
        'success'    => true,
        'booking_id' => $backupId,
        'status'     => 'pending'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
