<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    requireLogin();
    requireRole('owner');

    $pdo = (new Database())->getPdo();

    $owner_id = $_SESSION['owner_id'] ?? null;
    if (!$owner_id) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $owner_id = $stmt->fetchColumn();
        if ($owner_id) {
            $_SESSION['owner_id'] = (int)$owner_id;
        }
    }

    $requested_owner_id = $_GET['owner_id'] ?? null;
    if ($requested_owner_id && (int)$requested_owner_id !== (int)$owner_id) {
        throw new Exception("Invalid owner_id.");
    }

    if (!$owner_id || !is_numeric($owner_id)) {
        throw new Exception("Invalid owner_id.");
    }

    $sql = "
        SELECT 
            c.contract_id,
            c.property_id,
            c.agent_id,
            c.start_date,
            c.end_date,
            c.commission_rate,
            c.contract_file,
            c.status,
            c.created_at,

            p.title AS property_title,
            CONCAT(u.first_name, ' ', u.last_name) AS agent_name

        FROM property_contracts c
        LEFT JOIN properties p ON p.id = c.property_id
        LEFT JOIN agents a ON a.id = c.agent_id
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN owners o ON o.id = p.owner_id

        WHERE o.id = ?
        ORDER BY c.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$owner_id]);

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
