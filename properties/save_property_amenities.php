<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy amenities save endpoint.
// Safe to remove after: 2026-06-30 (if update_property covers amenities).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/property_amenities.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');

try {
    $db  = new Database();
    $pdo = $db->getPdo();

    requireLogin();
    requireRole('owner');
    requireCsrf();

    $propertyId = v_int($_POST['property_id'] ?? null, 'property id');

    $owner_id = $_SESSION['owner_id'] ?? null;
    if (!$owner_id) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id'] ?? 0]);
        $owner_id = $stmt->fetchColumn();
        if ($owner_id) {
            $_SESSION['owner_id'] = $owner_id;
        }
    }

    if (!$owner_id) {
        throw new Exception("Owner not identified.");
    }

    $check = $pdo->prepare("SELECT owner_id FROM properties WHERE id = ?");
    $check->execute([$propertyId]);
    $propOwner = $check->fetchColumn();

    if (!$propOwner) {
        throw new Exception("Property not found.");
    }
    if ((int)$propOwner !== (int)$owner_id) {
        throw new Exception("Unauthorized: You do not own this property.");
    }

    $provided = false;
    $amenityIds = getAmenityIdsFromRequest($provided);
    if (!$provided) {
        throw new Exception("amenity_ids required.");
    }

    $saved = syncPropertyAmenities($pdo, $propertyId, $amenityIds);

    echo json_encode([
        'success' => true,
        'amenity_ids' => $saved
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
