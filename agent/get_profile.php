<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json");

requireLogin();
requireRole('agent');

$db = new Database();
$pdo = $db->getPdo();

$userId = (int)($_SESSION['user_id'] ?? 0);
$agentId = $_SESSION['agent_id'] ?? null;

if (!$agentId) {
    $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $agentId = $stmt->fetchColumn();
    if ($agentId) {
        $_SESSION['agent_id'] = (int)$agentId;
    }
}

if (!$agentId) {
    echo json_encode(["success" => false, "error" => "Agent profile not found."]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        a.id AS agent_id,
        a.user_id,
        a.owner_id,
        a.license_no,
        a.specialization,
        a.commission_rate,
        a.phone,
        a.profile_photo,
        a.cv_file,
        a.nic,
        a.agency,
        a.position,
        a.years_of_experience,
        a.work_schedule,
        a.office_address,
        a.whatsapp_number,
        a.area_of_operation,
        a.bio,
        u.first_name,
        u.last_name,
        u.email
    FROM agents a
    JOIN users u ON a.user_id = u.id
    WHERE a.id = ?
    LIMIT 1
");
$stmt->execute([(int)$agentId]);
$agent = $stmt->fetch();

if (!$agent) {
    echo json_encode(["success" => false, "error" => "Agent profile not found."]);
    exit;
}

echo json_encode([
    "success" => true,
    "data" => $agent
]);
