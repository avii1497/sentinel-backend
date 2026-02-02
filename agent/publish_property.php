<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json; charset=UTF-8");

try {
    $data = json_decode(file_get_contents("php://input"), true);
    $data = sanitize_array($data ?? []);
    $property_id = v_int($data['property_id'] ?? null, 'property id');

    $db = new Database();
    $pdo = $db->getPdo();

    // ✅ Check if property exists
    $check = $pdo->prepare("SELECT is_published, is_premium_listing FROM properties WHERE id = ?");
    $check->execute([$property_id]);
    $property = $check->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        throw new Exception("Property not found");
    }
    
// 🔒 REQUIRED OWNER DOCUMENTS BEFORE PUBLISH
$requiredDocs = [
    'title_deed',
    'land_survey'
];

$docStmt = $pdo->prepare("
    SELECT document_key
    FROM property_documents
    WHERE property_id = ?
      AND uploaded_by = 'owner'
");
$docStmt->execute([$property_id]);
$uploadedDocs = $docStmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($requiredDocs as $docKey) {
    if (!in_array($docKey, $uploadedDocs)) {
        throw new Exception(
            "Cannot publish property. Missing required legal document: " . $docKey
        );
    }
}

    if ((int)$property["is_published"] !== 1) {
        // ✅ Publish property
        $stmt = $pdo->prepare("UPDATE properties SET is_published = 1 WHERE id = ?");
        $stmt->execute([$property_id]);
    }

    // ✅ If rental packages are active, ensure rental listing fields are set
    $rpStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM rental_properties
        WHERE property_id = ?
          AND is_active = 1
    ");
    $rpStmt->execute([$property_id]);
    if ((int)$rpStmt->fetchColumn() > 0) {
        $pdo->prepare("
            UPDATE properties
            SET rental_status = 'Published',
                listing_type_id = 2
            WHERE id = ?
        ")->execute([$property_id]);
    }

    echo json_encode([
        "success" => true,
        "message" => ((int)$property["is_published"] === 1)
            ? "Property already published"
            : "Property published successfully",
        "property_id" => $property_id,
        "is_published" => 1,
        "is_premium_listing" => $property["is_premium_listing"]
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
