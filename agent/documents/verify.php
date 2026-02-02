<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header("Content-Type: application/json");
if (session_status() === PHP_SESSION_NONE) session_start();

try {
    if (($_SESSION['role'] ?? '') !== 'agent') {
        throw new Exception("Unauthorized");
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $data = sanitize_array($data ?? []);
    $doc_id = v_int($data['document_id'] ?? null, 'document id');

    $pdo = (new Database())->getPdo();

    $stmt = $pdo->prepare("
        UPDATE property_documents
        SET verified_by_agent = 1,
            verified_at = NOW()
        WHERE id = ?
        AND uploaded_by = 'owner'
    ");
    $stmt->execute([$doc_id]);

    echo json_encode([
        "success" => true,
        "message" => "Document verified"
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
