<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);
$data = sanitize_array($data ?? []);
$link_id = v_int($data['link_id'] ?? null, 'link id');
$status = v_enum($data['status'] ?? null, 'status', ['Accepted', 'Declined']);

try {
    $db = new Database();
    $pdo = $db->getPdo();

    // ✅ Step 1 — Fetch agent_id and owner_id from the link
    $stmt = $pdo->prepare("SELECT agent_id, owner_id FROM owner_agent_link WHERE link_id = ?");
    $stmt->execute([$link_id]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$link) {
        throw new Exception("Link not found");
    }

    $agent_id = (int) $link['agent_id'];
    $owner_id = (int) $link['owner_id'];

    // ✅ Step 2 — Update link status
    $sql = "UPDATE owner_agent_link
            SET status = ?, updated_at = NOW()
            WHERE link_id = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$status, $link_id]);

    // ✅ Step 3 — If Accepted, also update the agent record
    if ($status === 'Accepted') {
        $update = $pdo->prepare("UPDATE agents SET owner_id = ? WHERE id = ?");
        $update->execute([$owner_id, $agent_id]);

        // Ensure chat conversation exists
        $convCheck = $pdo->prepare("
            SELECT conversation_id
            FROM owner_agent_conversations
            WHERE owner_id = ? AND agent_id = ?
            LIMIT 1
        ");
        $convCheck->execute([$owner_id, $agent_id]);
        $existingConv = $convCheck->fetch(PDO::FETCH_ASSOC);

        if (!$existingConv) {
            $convInsert = $pdo->prepare("
                INSERT INTO owner_agent_conversations (owner_id, agent_id, created_at, updated_at)
                VALUES (?, ?, NOW(), NOW())
            ");
            $convInsert->execute([$owner_id, $agent_id]);
        }
    }

    // ✅ Step 4 — If Declined, remove or clear owner_id (optional)
    if ($status === 'Declined') {
        $clear = $pdo->prepare("UPDATE agents SET owner_id = NULL WHERE id = ?");
        $clear->execute([$agent_id]);
    }

    $emailSent = false;
    $emailError = null;

    if ($status === 'Accepted') {
        // Best-effort email to agent
        $info = $pdo->prepare("
            SELECT
                u.email AS agent_email,
                CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
                ou.first_name AS owner_first_name,
                ou.last_name AS owner_last_name,
                o.company_name AS owner_company
            FROM agents a
            JOIN users u ON a.user_id = u.id
            JOIN owners o ON o.id = ?
            JOIN users ou ON o.user_id = ou.id
            WHERE a.id = ?
            LIMIT 1
        ");
        $info->execute([$owner_id, $agent_id]);
        $row = $info->fetch(PDO::FETCH_ASSOC);

        if (!empty($row['agent_email'])) {
            $ownerName = trim(($row['owner_first_name'] ?? '') . ' ' . ($row['owner_last_name'] ?? ''));
            $ownerLabel = $ownerName !== '' ? $ownerName : ($row['owner_company'] ?? 'an owner');
            $agentName = $row['agent_name'] ?: 'Agent';

            $html = "
                <h2>Collaboration Request Accepted ✅</h2>
                <p>Hello <b>{$agentName}</b>,</p>
                <p>Your collaboration request has been accepted by {$ownerLabel}.</p>
                <p>You can now access the owner's portfolio and start working together in Sentinel.</p>
                <p>— Sentinel Team</p>
            ";

            $mail = sendMail(
                $row['agent_email'],
                "Your collaboration request was accepted",
                $html
            );

            $emailSent = (bool)($mail['success'] ?? false);
            $emailError = $mail['success'] ? null : ($mail['error'] ?? "Email failed.");
        }
    }

    echo json_encode([
        "success" => true,
        "message" => "Request status updated successfully",
        "agent_id" => $agent_id,
        "owner_id" => $owner_id,
        "status" => $status,
        "email_sent" => $emailSent,
        "email_error" => $emailError
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Server error: " . $e->getMessage()
    ]);
}
