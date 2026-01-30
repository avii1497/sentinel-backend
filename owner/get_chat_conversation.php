<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    $owner_id = $_GET['owner_id'] ?? null;

    if (!$owner_id || !is_numeric($owner_id)) {
        throw new Exception("Invalid owner_id.");
    }

    $pdo = (new Database())->getPdo();

    /**
     * We list all conversations for this owner,
     * join with agents + users to get agent info,
     * and compute last_message + unread_count.
     */
    $sql = "
        SELECT
            oac.conversation_id,
            a.id AS agent_id,
            CONCAT(u.first_name, ' ', u.last_name) AS agent_name,
            a.agency AS agent_agency,
            a.profile_photo AS agent_photo,

            -- Last message in this conversation
            (
                SELECT m.message_text
                FROM chat_messages m
                WHERE m.conversation_id = oac.conversation_id
                ORDER BY m.created_at DESC, m.message_id DESC
                LIMIT 1
            ) AS last_message,

            -- Unread messages from AGENT to OWNER
            (
                SELECT COUNT(*)
                FROM chat_messages m2
                WHERE m2.conversation_id = oac.conversation_id
                  AND m2.sender_type = 'agent'
                  AND m2.is_read = 0
            ) AS unread_count
        FROM owner_agent_conversations oac
        INNER JOIN agents a ON oac.agent_id = a.id
        INNER JOIN users u ON u.id = a.user_id
        WHERE oac.owner_id = ?
        ORDER BY oac.updated_at DESC, oac.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$owner_id]);
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
