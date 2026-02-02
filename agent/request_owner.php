<?php
require_once __DIR__ . '/../cors.php'; 
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json; charset=UTF-8");

try {
    $data = json_decode(file_get_contents("php://input"), true);
    $data = sanitize_array($data ?? []);
    $agent_id = v_int($data['agent_id'] ?? null, 'agent id');
    $owner_id = v_int($data['owner_id'] ?? null, 'owner id');

    $db = new Database();
    $pdo = $db->getPdo();

    // 1️⃣ Check that owner exists (and optionally get owner_type)
    $stmtOwner = $pdo->prepare("
        SELECT o.id, o.owner_type_id, ot.type_name
        FROM owners o
        LEFT JOIN owner_types ot ON o.owner_type_id = ot.id
        WHERE o.id = :owner_id
    ");
    $stmtOwner->execute([':owner_id' => $owner_id]);
    $owner = $stmtOwner->fetch(PDO::FETCH_ASSOC);

    if (!$owner) {
        echo json_encode(["success" => false, "error" => "Owner not found."]);
        exit;
    }

    // 🔎 If you ever want to restrict some types, you'd check $owner['type_name'] here.
    // For now: Independent, Agency, Government Lease, Co-Owner all allow requests.

    // 2️⃣ Prevent duplicate Pending / Accepted requests
    $check = $pdo->prepare("
        SELECT status 
        FROM owner_agent_link 
        WHERE owner_id = :owner_id 
          AND agent_id = :agent_id
        ORDER BY requested_at DESC
        LIMIT 1
    ");
    $check->execute([
        ':owner_id' => $owner_id,
        ':agent_id' => $agent_id
    ]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['status'] === 'Pending') {
            echo json_encode([
                "success" => false,
                "error" => "You already have a pending request with this owner."
            ]);
            exit;
        }
        if ($existing['status'] === 'Accepted') {
            echo json_encode([
                "success" => false,
                "error" => "You are already collaborating with this owner."
            ]);
            exit;
        }
        // If status was Declined → allow sending a new request.
    }

    // 3️⃣ Insert new collaboration request as Pending
    $sql = "
        INSERT INTO owner_agent_link (owner_id, agent_id, status, requested_at)
        VALUES (:owner_id, :agent_id, 'Pending', NOW())
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':owner_id' => $owner_id,
        ':agent_id' => $agent_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Request sent successfully."
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database error: " . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Unexpected error: " . $e->getMessage()
    ]);
}
