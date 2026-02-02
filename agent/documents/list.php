<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';

header("Content-Type: application/json");
try {
    if (($_SESSION['role'] ?? '') !== 'agent') {
        throw new Exception("Unauthorized");
    }

    $property_id = (int)($_GET['property_id'] ?? 0);
    if ($property_id <= 0) {
        throw new Exception("Missing property_id");
    }

    $pdo = (new Database())->getPdo();

    $stmt = $pdo->prepare("
        SELECT
            id,
            document_key,
            document_label,
            file_url,
            verified_by_agent,
            verified_at,
            uploaded_at
        FROM property_documents
        WHERE property_id = ?
          AND uploaded_by = 'owner'
        ORDER BY uploaded_at DESC
    ");

    $stmt->execute([$property_id]);

    echo json_encode([
        "success" => true,
        "documents" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
