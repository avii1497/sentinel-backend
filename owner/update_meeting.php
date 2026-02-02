<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy meeting update endpoint.
// Safe to remove after: 2026-06-30 (use /owner/update_meeting_status.php).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json");

try {
    $meeting_id = v_int($_POST['meeting_id'] ?? null, 'meeting id');
    $status     = v_enum($_POST['status'] ?? null, 'status', ['accepted','declined','cancelled','completed']);

requireLogin();
    requireRole('owner');
    requireCsrf();

    $pdo = (new Database())->getPdo();

    $owner_id = (int)($_SESSION['owner_id'] ?? 0);
    if ($owner_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $owner_id = (int)$stmt->fetchColumn();
    }
    if ($owner_id <= 0) {
        throw new Exception("Owner not found.");
    }

    // Make sure this meeting belongs to this owner
    $check = $pdo->prepare("
        SELECT id FROM meetings
        WHERE id = ? AND owner_id = ?
        LIMIT 1
    ");
    $check->execute([$meeting_id, $owner_id]);
    if (!$check->fetch()) {
        throw new Exception("Meeting not found for this owner.");
    }

    $upd = $pdo->prepare("
        UPDATE meetings
        SET status = ?
        WHERE id = ?
    ");
    $upd->execute([$status, $meeting_id]);

    echo json_encode([
        "success" => true
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
