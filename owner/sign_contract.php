<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json");

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid method.");
    }

    requireLogin();
    requireCsrf();

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!$input) {
        throw new Exception("Invalid JSON body.");
    }
    $input = sanitize_array($input ?? []);

    $contract_id   = v_int($input['contract_id'] ?? null, 'contract id');
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['owner', 'agent'], true)) {
        throw new Exception("Unauthorized signer role.");
    }
    $signer_type = $role;
    $signer_id = $role === 'owner' ? ($_SESSION['owner_id'] ?? null) : ($_SESSION['agent_id'] ?? null);
    $signatureData = v_string($input['signature_data'] ?? null, 'signature data', 5000000);

    $pdo = (new Database())->getPdo();

    if (!$signer_id) {
        if ($signer_type === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        }
        $stmt->execute([$_SESSION['user_id']]);
        $signer_id = $stmt->fetchColumn();
    }
    if (!$signer_id) {
        throw new Exception("Signer account not found.");
    }

    // ---------------------------------------------------
    // 1) Validate that the signer REALLY belongs to contract
    // ---------------------------------------------------
    if ($signer_type === 'owner') {
        $checkSql = "SELECT * FROM property_contracts WHERE contract_id = ? AND owner_id = ? LIMIT 1";
    } else {
        $checkSql = "SELECT * FROM property_contracts WHERE contract_id = ? AND agent_id = ? LIMIT 1";
    }

    $stmt = $pdo->prepare($checkSql);
    $stmt->execute([$contract_id, $signer_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        throw new Exception("You are not authorized to sign this contract.");
    }

    // ---------------------------------------------------
    // 2) Decode & save PNG signature
    // ---------------------------------------------------
    if (strpos($signatureData, 'data:image') === 0) {
        $parts = explode(',', $signatureData, 2);
        $signatureData = $parts[1] ?? '';
    }

    $binary = base64_decode($signatureData, true);

    if ($binary === false) {
        throw new Exception("Invalid signature image data.");
    }
    if (strlen($binary) > 2 * 1024 * 1024) {
        throw new Exception("Signature image too large.");
    }
    $imgInfo = @getimagesizefromstring($binary);
    if (!$imgInfo || ($imgInfo['mime'] ?? '') !== 'image/png') {
        throw new Exception("Signature must be a PNG image.");
    }

    $uploadDir = __DIR__ . '/../uploads/signatures';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            throw new Exception("Failed to create signatures directory.");
        }
    }

    $filename = "contract_{$contract_id}_{$signer_type}_" . time() . ".png";
    $fullPath = $uploadDir . '/' . $filename;

    if (file_put_contents($fullPath, $binary) === false) {
        throw new Exception("Failed to save signature file.");
    }

    // Path to store in DB (public URL base)
    $dbPath = "uploads/signatures/" . $filename;

    // ---------------------------------------------------
    // 3) Insert into contract_signatures
    // ---------------------------------------------------
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $insertSql = "
        INSERT INTO contract_signatures
            (contract_id, signer_type, signer_id, signature_file, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ";
    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([$contract_id, $signer_type, $signer_id, $dbPath, $ip, $ua]);

    // ---------------------------------------------------
    // 4) Update main contract row
    // ---------------------------------------------------
    if ($signer_type === 'owner') {
        $updateSql = "
            UPDATE property_contracts
            SET owner_signed_at = NOW()
            WHERE contract_id = ?
        ";
    } else {
        $updateSql = "
            UPDATE property_contracts
            SET agent_signed_at = NOW()
            WHERE contract_id = ?
        ";
    }
    $pdo->prepare($updateSql)->execute([$contract_id]);

    // If both signed -> mark Active (if still Pending)
    $statusUpdate = "
        UPDATE property_contracts
        SET status = 'Active'
        WHERE contract_id = ?
          AND status = 'Pending'
          AND owner_signed_at IS NOT NULL
          AND agent_signed_at IS NOT NULL
    ";
    $pdo->prepare($statusUpdate)->execute([$contract_id]);

    echo json_encode([
        "success" => true,
        "signature_file" => $dbPath
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
