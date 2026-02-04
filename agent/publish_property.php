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
    
    // 🔒 REQUIRED OWNER DOCUMENTS BEFORE PUBLISH (by property type)
    $propStmt = $pdo->prepare("SELECT property_type_id FROM properties WHERE id = ?");
    $propStmt->execute([$property_id]);
    $propMeta = $propStmt->fetch(PDO::FETCH_ASSOC);
    $propertyTypeId = (int)($propMeta['property_type_id'] ?? 0);

    $requiredStmt = $pdo->prepare("
        SELECT document_key
        FROM property_required_documents
        WHERE property_type_id = ?
          AND is_mandatory = 1
    ");
    $requiredStmt->execute([$propertyTypeId]);
    $requiredDocs = $requiredStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($requiredDocs)) {
        $docStmt = $pdo->prepare("
            SELECT document_key, document_label, verified_by_agent
            FROM property_documents
            WHERE property_id = ?
              AND uploaded_by = 'owner'
        ");
        $docStmt->execute([$property_id]);
        $uploaded = $docStmt->fetchAll(PDO::FETCH_ASSOC);

        $normalize = static function (?string $value): string {
            $value = strtolower(trim((string)$value));
            $value = preg_replace('/[^a-z0-9]+/', '_', $value);
            return trim($value, '_');
        };

        $uploadedKeys = [];
        foreach ($uploaded as $row) {
            $key = $normalize($row['document_key'] ?? '');
            $label = $normalize($row['document_label'] ?? '');
            if ($key !== '') $uploadedKeys[] = $key;
            if ($label !== '') $uploadedKeys[] = $label;
        }

        foreach ($requiredDocs as $docKey) {
            $req = $normalize($docKey);
            if ($req === '') {
                continue;
            }
            if (!in_array($req, $uploadedKeys, true)) {
                throw new Exception(
                    "Cannot publish property. Missing required legal document: " . $docKey
                );
            }
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
