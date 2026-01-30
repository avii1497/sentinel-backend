<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

try {
    if ($_SESSION['role'] !== 'agent') {
        throw new Exception("Unauthorized");
    }

    $property_id = $_POST['property_id'] ?? null;
    if (!$property_id) throw new Exception("Missing property_id");

    $db = new Database();
    $pdo = $db->getPdo();

    // Check unverified documents
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM property_documents
        WHERE property_id = ? AND verified_by_agent = 0
    ");
    $stmt->execute([$property_id]);

    if ($stmt->fetchColumn() > 0) {
        throw new Exception("All documents must be verified first");
    }

    // ✅ Mark reservation ready for final sale
    $stmt = $pdo->prepare("
        UPDATE property_reservations
        SET ready_for_final = 1
        WHERE property_id = ?
    ");
    $stmt->execute([$property_id]);

    echo json_encode([
        "success" => true,
        "message" => "Documents approved. Ready for final payment."
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
