<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    requireLogin();
    requireRole('agent');
    requireCsrf();

    $conversation_id = $_POST['conversation_id'] ?? null;
    $message_text    = trim($_POST['message_text'] ?? "");

    $agent_id = $_SESSION['agent_id'] ?? null;
    if (!$agent_id) {
        $stmt = (new Database())->getPdo()->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $agent_id = $stmt->fetchColumn();
    }

    if (!$agent_id || !$conversation_id || !$message_text) {
        throw new Exception("Missing required fields: agent_id, conversation_id, or message_text.");
    }

    $pdo = (new Database())->getPdo();

    // Validate conversation belongs to this agent
    $check = $pdo->prepare("
        SELECT * 
        FROM owner_agent_conversations
        WHERE conversation_id = ? AND agent_id = ?
    ");
    $check->execute([$conversation_id, $agent_id]);
    if (!$check->fetch()) {
        throw new Exception("Invalid conversation for this agent.");
    }

    // Handle optional attachment
    $attachmentPath = null;
    if (!empty($_FILES['attachment']['name'])) {
        $uploadInfo = validateUpload(
            $_FILES['attachment'],
            ['png', 'jpg', 'jpeg', 'webp', 'pdf', 'mp3', 'wav', 'm4a'],
            ['image/png', 'image/jpeg', 'image/webp', 'application/pdf', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp3', 'audio/m4a'],
            10 * 1024 * 1024
        );

        $uploadDir = __DIR__ . "/../uploads/chat/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = "chat_" . time() . "_" . rand(1000, 9999) . "." . $uploadInfo['ext'];
        $fullPath = $uploadDir . $filename;

        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $fullPath)) {
            throw new Exception("File upload failed.");
        }

        $attachmentPath = "uploads/chat/" . $filename;
    }

    // Insert message
    $sql = "
        INSERT INTO chat_messages (conversation_id, sender_type, sender_id, message_text, attachment_path)
        VALUES (?, 'agent', ?, ?, ?)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$conversation_id, $agent_id, $message_text, $attachmentPath]);

    echo json_encode([
        "success"          => true,
        "message_id"       => $pdo->lastInsertId(),
        "attachment_path"  => $attachmentPath,
        "conversation_id"  => $conversation_id
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
