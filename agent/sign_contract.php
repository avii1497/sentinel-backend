<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    requireLogin();
    requireRole('agent');
    requireCsrf();

    $agent_id    = $_SESSION['agent_id'] ?? null;
    $contract_id = $_POST['contract_id'] ?? null;

    if (!$contract_id || !is_numeric($contract_id)) {
        throw new Exception("Missing or invalid contract_id");
    }
    if (empty($_FILES['signature']['tmp_name'])) {
        throw new Exception("Signature file is required");
    }

    $pdo = (new Database())->getPdo();

    if (!$agent_id) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $agent_id = $stmt->fetchColumn();
    }
    if (!$agent_id) {
        throw new Exception("Agent account not found.");
    }
    if (!$agent_id || !is_numeric($agent_id)) {
        throw new Exception("Missing or invalid agent_id");
    }

    // check contract belongs to this agent & is signable
    $stmt = $pdo->prepare("
        SELECT *
        FROM property_contracts
        WHERE contract_id = ? AND agent_id = ?
        LIMIT 1
    ");
    $stmt->execute([$contract_id, $agent_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        throw new Exception("Contract not found for this agent.");
    }

    if ($contract['status'] === 'Cancelled' || $contract['status'] === 'Expired') {
        throw new Exception("This contract can no longer be signed.");
    }

    // ============================
    // HANDLE FILE UPLOAD
    // ============================
    $uploadDir = __DIR__ . '/../uploads/contract_signatures';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new Exception("Failed to create signatures directory");
        }
    }

    $uploadInfo = validateUpload(
        $_FILES['signature'],
        ['png', 'jpg', 'jpeg'],
        ['image/png', 'image/jpeg'],
        5 * 1024 * 1024
    );

    $fileTmp  = $_FILES['signature']['tmp_name'];
    $safeName = 'agent_' . $agent_id . '_contract_' . $contract_id . '_' . time() . '.' . $uploadInfo['ext'];
    $targetPath = $uploadDir . '/' . $safeName;

    if (!move_uploaded_file($fileTmp, $targetPath)) {
        throw new Exception("Failed to save signature file");
    }

    // path to store in DB (relative to backend root)
    $relativePath = 'uploads/contract_signatures/' . $safeName;

    $ip        = $_SERVER['REMOTE_ADDR']      ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $pdo->beginTransaction();

    // insert or update contract_signatures row
    $check = $pdo->prepare("
        SELECT id 
        FROM contract_signatures
        WHERE contract_id = ? AND signer_type = 'agent'
        LIMIT 1
    ");
    $check->execute([$contract_id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $upd = $pdo->prepare("
            UPDATE contract_signatures
            SET signature_file = ?, signed_at = NOW(), ip_address = ?, user_agent = ?
            WHERE id = ?
        ");
        $upd->execute([$relativePath, $ip, $userAgent, $existing['id']]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO contract_signatures
                (contract_id, signer_type, signer_id, signature_file, signed_at, ip_address, user_agent)
            VALUES
                (?, 'agent', ?, ?, NOW(), ?, ?)
        ");
        $ins->execute([$contract_id, $agent_id, $relativePath, $ip, $userAgent]);
    }

    // update contract: set agent_signed_at, maybe status = Active
    $newStatus = $contract['status'];

    if (empty($contract['agent_signed_at'])) {
        // only update if not already signed
        if (!empty($contract['owner_signed_at']) && $contract['status'] === 'Pending') {
            $newStatus = 'Active';
        }

        $updContract = $pdo->prepare("
            UPDATE property_contracts
            SET agent_signed_at = NOW(), status = ?
            WHERE contract_id = ?
        ");
        $updContract->execute([$newStatus, $contract_id]);
    }

    // reload contract row + property info to send back to frontend
    $reload = $pdo->prepare("
        SELECT 
            pc.contract_id,
            pc.owner_id,
            pc.property_id,
            pc.agent_id,
            pc.start_date,
            pc.end_date,
            pc.approved_at,
            pc.owner_signed_at,
            pc.agent_signed_at,
            pc.commission_rate,
            pc.status,
            pc.contract_type,
            pc.contract_file,
            pc.signed_pdf_file,
            pc.created_at,
            p.title   AS property_title,
            p.location AS property_location
        FROM property_contracts pc
        JOIN properties p ON p.id = pc.property_id
        WHERE pc.contract_id = ?
        LIMIT 1
    ");
    $reload->execute([$contract_id]);
    $updatedContract = $reload->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    echo json_encode([
        'success'  => true,
        'message'  => 'Contract signed successfully.',
        'contract' => $updatedContract,
        'signature_file' => $relativePath,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
