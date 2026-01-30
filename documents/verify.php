<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

try {
    if ($_SESSION['role'] !== 'agent') {
        throw new Exception("Unauthorized");
    }

    $input = json_decode(file_get_contents("php://input"), true);
    $document_id = $input['document_id'] ?? null;

    if (!$document_id || !is_numeric($document_id)) {
        throw new Exception("Invalid document_id");
    }

    $db = new Database();
    $pdo = $db->getPdo();

    $stmt = $pdo->prepare("
        UPDATE property_documents
        SET verified_by_agent = 1, verified_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$document_id]);

    echo json_encode([
        "success" => true,
        "message" => "Document verified"
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
