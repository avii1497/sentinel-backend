<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

try {
    $property_type_id = $_GET['property_type_id'] ?? null;

    if (!$property_type_id || !is_numeric($property_type_id)) {
        throw new Exception("Missing or invalid property_type_id");
    }

    $db = new Database();
    $pdo = $db->getPdo();

    $stmt = $pdo->prepare("
        SELECT id, property_type_id, document_key, document_label, is_mandatory
        FROM property_required_documents
        WHERE property_type_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$property_type_id]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => $docs
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
?>
