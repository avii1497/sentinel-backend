<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    requireLogin();
    requireRole('owner');
    requireCsrf();

    // Get POST parameters
    $conversation_id = $_POST['conversation_id'] ?? null;
    $message_text = trim($_POST['message_text'] ?? "");

    $owner_id = $_SESSION['owner_id'] ?? null;
    if (!$owner_id) {
        $stmt = (new Database())->getPdo()->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $owner_id = $stmt->fetchColumn();
    }

    // Check if required fields are provided
    if (!$owner_id || !$conversation_id || !$message_text) {
        throw new Exception("Missing required fields: owner_id, conversation_id, or message_text.");
    }

    $pdo = (new Database())->getPdo();

    // Validate that the conversation belongs to this owner
    $check = $pdo->prepare("SELECT * FROM owner_agent_conversations WHERE conversation_id = ? AND owner_id = ?");
    $check->execute([$conversation_id, $owner_id]);
    if (!$check->fetch()) {
        throw new Exception("Invalid conversation for this owner.");
    }

    // Handle file upload (attachment)
    $attachmentPath = null;
    if (!empty($_FILES['attachment']['name'])) {
        $uploadInfo = validateUpload(
            $_FILES['attachment'],
            ['png', 'jpg', 'jpeg', 'webp', 'pdf', 'mp3', 'wav', 'm4a'],
            ['image/png', 'image/jpeg', 'image/webp', 'application/pdf', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp3', 'audio/m4a'],
            10 * 1024 * 1024
        );

        // Upload the file
        $uploadDir = __DIR__ . "/../uploads/chat/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);  // Ensure the directory exists
        }

        // Generate a unique file name
        $filename = "chat_" . time() . "_" . rand(1000,9999) . "." . $uploadInfo['ext'];
        $fullPath = $uploadDir . $filename;

        // Move the file to the upload directory
        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $fullPath)) {
            throw new Exception("File upload failed.");
        }

        // Store file path relative to the web root
        $attachmentPath = "uploads/chat/" . $filename;
    }

    // Insert message into the database
    $sql = "
        INSERT INTO chat_messages (conversation_id, sender_type, sender_id, message_text, attachment_path)
        VALUES (?, 'owner', ?, ?, ?)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$conversation_id, $owner_id, $message_text, $attachmentPath]);

    // Return the response
    echo json_encode([
        "success" => true,
        "message_id" => $pdo->lastInsertId(),
        "attachment_path" => $attachmentPath,
        "conversation_id" => $conversation_id
    ]);

} catch (Throwable $e) {
    // Return error response if an exception occurs
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
