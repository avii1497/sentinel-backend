<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json");

try {
requireLogin();
    requireRole('owner');
    requireCsrf();

    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) throw new Exception("Invalid JSON.");
    $data = sanitize_array($data ?? []);

    $id = v_int($data["id"] ?? null, 'id');

    $db = new Database();
    $pdo = $db->getPdo();

    // Resolve owner_id from session (do not trust client input)
    $owner_id = (int)($_SESSION['owner_id'] ?? 0);
    if ($owner_id <= 0) {
        $oStmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $oStmt->execute([(int)$_SESSION['user_id']]);
        $owner_id = (int)$oStmt->fetchColumn();
        if ($owner_id <= 0) {
            throw new Exception("Owner profile not found.");
        }
        $_SESSION['owner_id'] = $owner_id;
    }

    // Check ownership
    $stmt = $pdo->prepare("
        SELECT p.owner_id
        FROM rental_properties r
        JOIN properties p ON p.id = r.property_id
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception("Rental package not found.");
    if ((int)$row["owner_id"] !== (int)$owner_id) throw new Exception("Unauthorized.");

    $pdo->prepare("DELETE FROM rental_properties WHERE id = ?")->execute([$id]);

    echo json_encode(["success" => true]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
