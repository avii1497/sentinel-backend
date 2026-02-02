<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
    // ✅ Client only
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['customer', 'premium_customer'])) {
        throw new Exception("Unauthorized");
    }

    $property_id = $_GET['property_id'] ?? null;
    if (!$property_id) {
        throw new Exception("Missing property_id");
    }

    $db = new Database();
    $pdo = $db->getPdo();

    // 🔒 Check reservation status
    $stmt = $pdo->prepare("
        SELECT status
        FROM reservations
        WHERE property_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$property_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    // ❌ No reservation or not paid yet → hide documents
    if (
        !$reservation ||
        !in_array($reservation['status'], [
            'PAID_CONFIRMED',
            'MEETING_SCHEDULED',
            'PROCEED_APPROVED'
        ])
    ) {
        echo json_encode([
            "success" => true,
            "data" => []
        ]);
        exit;
    }

    // ✅ Client-visible documents ONLY
    $stmt = $pdo->prepare("
        SELECT 
            d.id,
            d.document_key,
            d.document_label,
            d.file_url,
            d.verified_by_agent,
            d.verified_at,
            d.uploaded_at
        FROM property_documents d
        WHERE d.property_id = ?
          AND d.uploaded_by != 'owner'
        ORDER BY d.uploaded_at DESC
    ");
    $stmt->execute([$property_id]);

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
