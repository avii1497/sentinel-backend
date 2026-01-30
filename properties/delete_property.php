<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

try {
    requireLogin();
    requireRole('owner');
    requireCsrf();

    $data = json_decode(file_get_contents("php://input"), true);

    $propertyId = (int)($data['property_id'] ?? 0);
    $requested_owner_id = (int)($data['owner_id'] ?? 0);
    $owner_id   = $_SESSION['owner_id'] ?? null;

    if ($propertyId <= 0)  throw new Exception("Invalid property_id");
    if (!$owner_id)    throw new Exception("Missing owner_id");

    $db  = new Database();
    $pdo = $db->getPdo();

    if (!$owner_id) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $owner_id = $stmt->fetchColumn();
    }
    if (!$owner_id || ($requested_owner_id && $requested_owner_id !== (int)$owner_id)) {
        throw new Exception("Missing owner_id");
    }

    // -----------------------------------------------------
    // 1) VALIDATE OWNER (Type + Permissions)
    // -----------------------------------------------------
    $ownerStmt = $pdo->prepare("
        SELECT o.id, o.owner_type_id, o.is_primary_contact, ot.type_name
        FROM owners o
        LEFT JOIN owner_types ot ON ot.id = o.owner_type_id
        WHERE o.id = ?
        LIMIT 1
    ");
    $ownerStmt->execute([$owner_id]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$owner) {
        throw new Exception("Owner not found");
    }

    $ownerType = strtolower($owner['type_name'] ?? '');

    // Co-owner rule → ONLY primary allowed to delete
    if (strpos($ownerType, 'co') !== false) {
        if ((int)$owner['is_primary_contact'] !== 1) {
            throw new Exception("Only the PRIMARY co-owner can delete properties.");
        }
    }

    // -----------------------------------------------------
    // 2) VALIDATE PROPERTY BELONGS TO THIS OWNER
    // -----------------------------------------------------
    $q = $pdo->prepare("
        SELECT owner_id, image_url, model_3d_url 
        FROM properties 
        WHERE id = ?
    ");
    $q->execute([$propertyId]);
    $property = $q->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        throw new Exception("Property not found.");
    }

    if ((int)$property['owner_id'] !== $owner_id) {
        throw new Exception("Unauthorized: You do not own this property.");
    }

    // -----------------------------------------------------
    // 3) DELETE GALLERY FILES
    // -----------------------------------------------------
    $stmt = $pdo->prepare("SELECT image_url FROM property_gallery WHERE property_id = ?");
    $stmt->execute([$propertyId]);
    $gallery = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($gallery as $url) {
        $filePath = __DIR__ . '/' . $url;
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    $pdo->prepare("DELETE FROM property_gallery WHERE property_id = ?")->execute([$propertyId]);

    // -----------------------------------------------------
    // 4) DELETE MAIN IMAGE + 3D MODEL IF THEY EXIST
    // -----------------------------------------------------
    $filesToDelete = [$property['image_url'], $property['model_3d_url']];

    foreach ($filesToDelete as $url) {
        if ($url) {
            $filePath = __DIR__ . '/' . $url;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }

    // -----------------------------------------------------
    // 5) DELETE PROPERTY RECORD
    // -----------------------------------------------------
    $pdo->prepare("DELETE FROM properties WHERE id = ?")->execute([$propertyId]);

    echo json_encode([
        'success' => true,
        'message' => "Property deleted successfully."
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
