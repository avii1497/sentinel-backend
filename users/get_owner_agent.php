<?php
// 🔧 Hide notices/warnings (so JSON stays clean)
error_reporting(0);
ini_set('display_errors', 0);

// ✅ Safe session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ✅ Output type
header('Content-Type: application/json');

// Include database
require_once __DIR__ . '/../Database.php';

try {
    // Get owner_id safely
    $ownerId = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : 0;
    if ($ownerId <= 0) {
        throw new RuntimeException('Invalid or missing owner_id');
    }

    // Connect DB
    $db = new Database();
    $pdo = $db->getPdo();

    // Query agent by owner_id
    $sql = "
        SELECT 
            a.id AS agent_id,
            au.first_name AS agent_first_name,
            au.last_name AS agent_last_name,
            au.email AS agent_email,
            a.phone AS agent_phone,
            a.license_no,
            a.specialization,
            a.commission_rate
        FROM agents a
        JOIN users au ON a.user_id = au.id
        WHERE a.owner_id = :owner_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':owner_id' => $ownerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $rows ?: []
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
