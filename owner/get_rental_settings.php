<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy rental settings fetch.
// Safe to remove after: 2026-06-30 (rental settings now use /rental/get_rental_settings.php).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

try {
    $property_id = $_GET['property_id'] ?? null;
    $owner_id = $_GET['owner_id'] ?? null;

    if (!$property_id || !$owner_id) {
        throw new Exception("Missing property_id or owner_id");
    }

    $db = new Database();
    $pdo = $db->getPdo();

    // Validate property ownership
    $stmt = $pdo->prepare("SELECT owner_id FROM properties WHERE id = ?");
    $stmt->execute([$property_id]);
    $prop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prop) throw new Exception("Property not found.");
    if ((int)$prop['owner_id'] !== (int)$owner_id) {
        throw new Exception("Unauthorized.");
    }

    // Fetch rental packages
    $stmt = $pdo->prepare("
        SELECT *
        FROM rental_properties
        WHERE property_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$property_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by rental_type
    $grouped = [
        "short_term" => [],
        "long_term" => [],
        "corporate" => [],
        "holiday" => [],
    ];

    foreach ($rows as $r) {
        $grouped[$r["rental_type"]][] = $r;
    }

    echo json_encode([
        "success" => true,
        "data" => $grouped
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
