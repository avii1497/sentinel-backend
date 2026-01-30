<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
    requireRole('owner');
    requireCsrf();

    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new Exception("Invalid JSON format.");
    }

    // Required
    $property_id = (int)($data["property_id"] ?? 0);
    $rental_type = $data["rental_type"] ?? null;
    $id          = isset($data["id"]) ? (int)$data["id"] : null;

    if ($property_id <= 0 || !$rental_type) {
        throw new Exception("Missing required fields.");
    }

    // Optional (safe defaults)
    $is_active      = (int)($data["is_active"] ?? 0);
    $price_daily    = $data["price_daily"] ?? null;
    $price_nightly  = $data["price_nightly"] ?? null;
    $price_monthly  = $data["price_monthly"] ?? null;
    $price_yearly   = $data["price_yearly"] ?? null;
    $min_stay_days  = $data["min_stay_days"] ?? null;
    $max_stay_days  = $data["max_stay_days"] ?? null;
    $max_guests     = $data["max_guests"] ?? null;
    $notes          = $data["notes"] ?? null;

    $db  = new Database();
    $pdo = $db->getPdo();

    // Resolve owner_id from session (do not trust client input)
    $owner_id = (int)($_SESSION['owner_id'] ?? 0);
    if ($owner_id <= 0) {
        $oStmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $oStmt->execute([(int)$_SESSION['user_id']]);
        $owner_id = (int)$oStmt->fetchColumn();
        if ($owner_id <= 0) {
            throw new Exception("Owner profile not found.");
        }
        $_SESSION['owner_id'] = $owner_id;
    }

    // Validate property owner
    $stmt = $pdo->prepare("SELECT owner_id FROM properties WHERE id = ?");
    $stmt->execute([$property_id]);
    $prop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prop) throw new Exception("Property not found.");
    if ((int)$prop["owner_id"] !== $owner_id) {
        throw new Exception("Unauthorized.");
    }

    if ($id) {
        // UPDATE
        $stmt = $pdo->prepare("
            UPDATE rental_properties SET
                rental_type = :rental_type,
                is_active = :is_active,
                price_daily = :price_daily,
                price_nightly = :price_nightly,
                price_monthly = :price_monthly,
                price_yearly = :price_yearly,
                min_stay_days = :min_stay_days,
                max_stay_days = :max_stay_days,
                max_guests = :max_guests,
                notes = :notes
            WHERE id = :id AND property_id = :property_id
        ");

        $stmt->execute([
            ":id" => $id,
            ":property_id" => $property_id,
            ":rental_type" => $rental_type,
            ":is_active" => $is_active,
            ":price_daily" => $price_daily,
            ":price_nightly" => $price_nightly,
            ":price_monthly" => $price_monthly,
            ":price_yearly" => $price_yearly,
            ":min_stay_days" => $min_stay_days,
            ":max_stay_days" => $max_stay_days,
            ":max_guests" => $max_guests,
            ":notes" => $notes
        ]);

        $statusStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM rental_properties
            WHERE property_id = ?
              AND is_active = 1
        ");
        $statusStmt->execute([$property_id]);
        $rentalStatus = ((int)$statusStmt->fetchColumn() > 0) ? 'Published' : 'Draft';
        if ($rentalStatus === 'Published') {
            $pdo->prepare("UPDATE properties SET rental_status = ?, listing_type_id = 2 WHERE id = ?")
                ->execute([$rentalStatus, $property_id]);
        } else {
            $pdo->prepare("UPDATE properties SET rental_status = ? WHERE id = ?")
                ->execute([$rentalStatus, $property_id]);
        }

        echo json_encode(["success" => true, "id" => $id]);

    } else {
        // INSERT
        $stmt = $pdo->prepare("
            INSERT INTO rental_properties
            (property_id, rental_type, is_active, price_daily, price_nightly,
             price_monthly, price_yearly, min_stay_days, max_stay_days, max_guests, notes)
            VALUES
            (:property_id, :rental_type, :is_active, :price_daily, :price_nightly,
             :price_monthly, :price_yearly, :min_stay_days, :max_stay_days, :max_guests, :notes)
        ");

        $stmt->execute([
            ":property_id" => $property_id,
            ":rental_type" => $rental_type,
            ":is_active" => $is_active,
            ":price_daily" => $price_daily,
            ":price_nightly" => $price_nightly,
            ":price_monthly" => $price_monthly,
            ":price_yearly" => $price_yearly,
            ":min_stay_days" => $min_stay_days,
            ":max_stay_days" => $max_stay_days,
            ":max_guests" => $max_guests,
            ":notes" => $notes
        ]);

        $statusStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM rental_properties
            WHERE property_id = ?
              AND is_active = 1
        ");
        $statusStmt->execute([$property_id]);
        $rentalStatus = ((int)$statusStmt->fetchColumn() > 0) ? 'Published' : 'Draft';
        if ($rentalStatus === 'Published') {
            $pdo->prepare("UPDATE properties SET rental_status = ?, listing_type_id = 2 WHERE id = ?")
                ->execute([$rentalStatus, $property_id]);
        } else {
            $pdo->prepare("UPDATE properties SET rental_status = ? WHERE id = ?")
                ->execute([$rentalStatus, $property_id]);
        }

        echo json_encode([
            "success" => true,
            "id" => (int)$pdo->lastInsertId()
        ]);
    }

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
