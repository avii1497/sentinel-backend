<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    $owner_id = $_GET['owner_id'] ?? null;
    $agent_id = $_GET['agent_id'] ?? null;

    if (!$owner_id || !is_numeric($owner_id)) {
        throw new Exception("Invalid owner_id.");
    }
    if (!$agent_id || !is_numeric($agent_id)) {
        throw new Exception("Invalid agent_id.");
    }

    $pdo = (new Database())->getPdo();

    // ----------------------------------------------------
    // 1) Find the conversation for this owner + agent
    // ----------------------------------------------------
    $convSql = "
        SELECT conversation_id
        FROM owner_agent_conversations
        WHERE owner_id = ? AND agent_id = ?
        LIMIT 1
    ";
    $convStmt = $pdo->prepare($convSql);
    $convStmt->execute([$owner_id, $agent_id]);
    $conv = $convStmt->fetch(PDO::FETCH_ASSOC);

    // If there is no conversation yet, just return empty list
    if (!$conv) {
        echo json_encode([
            "success"         => true,
            "messages"        => [],
            "conversation_id" => null,
            "agent_typing"    => false   // your React expects this field
        ]);
        exit;
    }

    $conversation_id = (int)$conv['conversation_id'];

    // ----------------------------------------------------
    // 2) Get all messages in this conversation
    // ----------------------------------------------------
    $sql = "
        SELECT 
            message_id,
            conversation_id,
            sender_type,
            sender_id,
            message_text,
            attachment_path,
            is_read,
            created_at
        FROM chat_messages
        WHERE conversation_id = ?
        ORDER BY created_at ASC, message_id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$conversation_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ----------------------------------------------------
    // 3) Mark AGENT → OWNER messages as read
    // ----------------------------------------------------
    $upd = $pdo->prepare("
        UPDATE chat_messages
        SET is_read = 1
        WHERE conversation_id = ?
          AND sender_type = 'agent'
          AND is_read = 0
    ");
    $upd->execute([$conversation_id]);

    // If you later add typing status in DB, compute it here.
    $agentTyping = false;

    echo json_encode([
        "success"         => true,
        "messages"        => $messages,
        "conversation_id" => $conversation_id,
        "agent_typing"    => $agentTyping
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
