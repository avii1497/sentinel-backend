<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy document upload endpoint.
// Safe to remove after: 2026-06-30 (client uploads now use /public/documents/*).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

try {
    requireLogin();
    requireRole(['owner', 'customer']);
    requireCsrf();

    $property_id = v_int($_POST['property_id'] ?? null, 'property id');
    $document_key = v_string($_POST['document_key'] ?? null, 'document key', 100);
    $document_label = v_string($_POST['document_label'] ?? null, 'document label', 200);

    if (empty($_FILES['file'])) {
        throw new Exception("Missing fields");
    }

    // Role mapping
    $role = $_SESSION['role'];

    $db = new Database();
    $pdo = $db->getPdo();

    // 🔒 Ensure property has active reservation
    $stmt = $pdo->prepare("
        SELECT id FROM property_reservations
        WHERE property_id = ?
          AND (reservation_status = 'PAID_CONFIRMED' OR payment_status = 'paid')
        LIMIT 1
    ");
    $stmt->execute([$property_id]);

    if (!$stmt->fetch()) {
        throw new Exception("No active paid reservation");
    }

    // Upload
    $uploadDir = __DIR__ . "/../../uploads/docs/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $uploadInfo = validateUpload(
        $_FILES['file'],
        ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
        ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'],
        10 * 1024 * 1024
    );
    $filename = uniqid("doc_") . "." . $uploadInfo['ext'];
    $path = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
        throw new Exception("Upload failed");
    }

    $stmt = $pdo->prepare("
        INSERT INTO property_documents
        (property_id, document_key, document_label, file_url, uploaded_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $property_id,
        $document_key,
        $document_label,
        "uploads/docs/" . $filename,
        $role
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Document uploaded"
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
