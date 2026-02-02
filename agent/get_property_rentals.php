<?php
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

try {
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'agent') {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }
    requireLogin();
    requireRole('agent');

    $property_id = (int)($_GET['property_id'] ?? 0);
    if ($property_id <= 0) throw new Exception("Invalid property");

    $db = new Database();
    $pdo = $db->getPdo();

    // Resolve agent_id from session
    $agent_id = (int)($_SESSION['agent_id'] ?? 0);
    if ($agent_id <= 0) {
        $aStmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $aStmt->execute([(int)$_SESSION['user_id']]);
        $agent_id = (int)$aStmt->fetchColumn();
        if ($agent_id <= 0) {
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "message" => "Unauthorized"
            ]);
            exit;
        }
        $_SESSION['agent_id'] = $agent_id;
    }

    // Ensure property is assigned to this agent
    $pStmt = $pdo->prepare("SELECT assigned_agent_id FROM properties WHERE id = ? LIMIT 1");
    $pStmt->execute([$property_id]);
    $assigned = (int)$pStmt->fetchColumn();
    if ($assigned <= 0 || $assigned !== $agent_id) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            rental_type,
            price_daily,
            price_monthly,
            price_yearly,
            min_stay_days,
            max_stay_days,
            max_guests,
            notes
        FROM rental_properties
        WHERE property_id = ?
          AND is_active = 1
        ORDER BY rental_type
    ");
    $stmt->execute([$property_id]);

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
