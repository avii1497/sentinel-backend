<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    $agent_id = $_GET['agent_id'] ?? null;

    if (!$agent_id || !is_numeric($agent_id)) {
        throw new Exception("Invalid agent_id.");
    }

    $pdo = (new Database())->getPdo();

    // Ensure conversations exist for accepted owner-agent links (backfill)
    $acceptedStmt = $pdo->prepare("
        SELECT owner_id
        FROM owner_agent_link
        WHERE agent_id = ? AND status = 'Accepted'
    ");
    $acceptedStmt->execute([$agent_id]);
    $acceptedOwners = $acceptedStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($acceptedOwners)) {
        $convCheck = $pdo->prepare("
            SELECT 1
            FROM owner_agent_conversations
            WHERE owner_id = ? AND agent_id = ?
            LIMIT 1
        ");
        $convInsert = $pdo->prepare("
            INSERT INTO owner_agent_conversations (owner_id, agent_id, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
        ");

        foreach ($acceptedOwners as $ownerId) {
            $convCheck->execute([(int)$ownerId, (int)$agent_id]);
            if (!$convCheck->fetchColumn()) {
                $convInsert->execute([(int)$ownerId, (int)$agent_id]);
            }
        }
    }

    /**
     * We list all conversations where this agent is linked,
     * and join with owners + users to get owner name.
     *
     * Adjust table/column names for `owners` if they differ on your side.
     */
    $sql = "
        SELECT
            oac.conversation_id,
            oac.owner_id,
            oac.agent_id,

            CONCAT(u.first_name, ' ', u.last_name) AS owner_name,
            u.email AS owner_email,

            -- Last message text in this conversation
            (
                SELECT m.message_text
                FROM chat_messages m
                WHERE m.conversation_id = oac.conversation_id
                ORDER BY m.created_at DESC, m.message_id DESC
                LIMIT 1
            ) AS last_message,

            -- Unread messages sent by OWNER (for this agent)
            (
                SELECT COUNT(*)
                FROM chat_messages m2
                WHERE m2.conversation_id = oac.conversation_id
                  AND m2.sender_type = 'owner'
                  AND m2.is_read = 0
            ) AS unread_count
        FROM owner_agent_conversations oac
        INNER JOIN owners o ON oac.owner_id = o.id
        INNER JOIN users u ON o.user_id = u.id
        WHERE oac.agent_id = ?
        ORDER BY oac.updated_at DESC, oac.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agent_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data"    => $rows
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
