<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy agent document listing.
// Safe to remove after: 2026-06-30 (agent docs now use /agent/documents/*).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

try {
    if ($_SESSION['role'] !== 'agent') {
        throw new Exception("Unauthorized");
    }

    $property_id = $_GET['property_id'] ?? null;
    if (!$property_id) throw new Exception("Missing property_id");

    $db = new Database();
    $pdo = $db->getPdo();

    $stmt = $pdo->prepare("
        SELECT 
            d.id,
            d.document_key,
            d.document_label,
            d.file_url,
            d.uploaded_by,
            d.verified_by_agent,
            d.verified_at,
            d.uploaded_at
        FROM property_documents d
        WHERE d.property_id = ?
        ORDER BY d.uploaded_at DESC
    ");
    $stmt->execute([$property_id]);

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
