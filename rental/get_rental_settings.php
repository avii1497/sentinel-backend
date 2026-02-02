<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

try {
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'owner') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
        exit;
    }
    requireLogin();
    requireRole('owner');

    $propertyId = (int)($_GET['property_id'] ?? 0);

    if ($propertyId <= 0) {
        throw new Exception("Invalid property_id");
    }

    $db  = new Database();
    $pdo = $db->getPdo();

    // Resolve owner_id from session (do not trust query)
    $owner_id = (int)($_SESSION['owner_id'] ?? 0);
    if ($owner_id <= 0) {
        $oStmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $oStmt->execute([(int)$_SESSION['user_id']]);
        $owner_id = (int)$oStmt->fetchColumn();
        if ($owner_id <= 0) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized'
            ]);
            exit;
        }
        $_SESSION['owner_id'] = $owner_id;
    }

    // Validate ownership
    $stmt = $pdo->prepare("
        SELECT id FROM properties
        WHERE id = ? AND owner_id = ?
        LIMIT 1
    ");
    $stmt->execute([$propertyId, $owner_id]);

    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized'
        ]);
        exit;
    }

    // Load rental packages
    $stmt = $pdo->prepare("
        SELECT *
        FROM rental_properties
        WHERE property_id = ?
        ORDER BY rental_type, created_at ASC
    ");
    $stmt->execute([$propertyId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by rental_type
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['rental_type']][] = $row;
    }

    echo json_encode([
        'success' => true,
        'data'    => $grouped
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
