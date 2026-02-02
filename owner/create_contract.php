<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json");

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid method");
    }

    requireLogin();
    requireRole('owner');
    requireCsrf();

    // Required
    $owner_id = $_SESSION['owner_id'] ?? null;
    $property_id = v_int($_POST['property_id'] ?? null, 'property id');
    $agent_id = v_int($_POST['agent_id'] ?? null, 'agent id');
    $startDateRaw = $_POST['start_date'] ?? null;
    if ($startDateRaw === '') $startDateRaw = null;
    $start_date = v_date($startDateRaw, 'start date', false);
    $endDateRaw = $_POST['end_date'] ?? null;
    if ($endDateRaw === '') $endDateRaw = null;
    $end_date = v_date($endDateRaw, 'end date', false);
    $commissionRaw = $_POST['commission_rate'] ?? null;
    if ($commissionRaw === '') $commissionRaw = null;
    $commission_rate = v_float($commissionRaw, 'commission rate', 0, 100, false);

    if (!$owner_id || !$property_id || !$agent_id) {
        throw new Exception("Missing required fields.");
    }

    $pdo = (new Database())->getPdo();

    if (!$owner_id) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $owner_id = $stmt->fetchColumn();
    }
    if (!$owner_id) {
        throw new Exception("Owner account not found.");
    }
    if (!is_numeric($property_id) || !is_numeric($agent_id)) {
        throw new Exception("Invalid property_id or agent_id.");
    }

    // ===================================================
    // 1️⃣ SECURITY CHECK — Validate agent↔property pairing
    // ===================================================
    $check = $pdo->prepare("
        SELECT 1
        FROM properties p
        WHERE p.id = ?
          AND p.owner_id = ?
          AND p.assigned_agent_id = ?
    ");
    $check->execute([$property_id, $owner_id, $agent_id]);

    if ($check->rowCount() === 0) {
        throw new Exception("Invalid agent–property assignment.");
    }

    // ===================================================
    // 2️⃣ HANDLE FILE UPLOAD (optional)
    // ===================================================
    $fileName = null;

    if (!empty($_FILES['contract_file']['name'])) {
        $uploadDir = __DIR__ . '/../uploads/contracts/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $uploadInfo = validateUpload(
            $_FILES['contract_file'],
            ['pdf'],
            ['application/pdf'],
            10 * 1024 * 1024
        );

        $fileName = 'contract_' . time() . '.' . $uploadInfo['ext'];
        $path = $uploadDir . $fileName;

        if (!move_uploaded_file($_FILES['contract_file']['tmp_name'], $path)) {
            throw new Exception("Failed to upload file.");
        }
    }

    // ===================================================
    // 3️⃣ INSERT CONTRACT
    // ===================================================
    $sql = "
        INSERT INTO property_contracts 
            (owner_id, property_id, agent_id, start_date, end_date, commission_rate, contract_file)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $owner_id,
        $property_id,
        $agent_id,
        $start_date,
        $end_date,
        $commission_rate,
        $fileName
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Contract created successfully"
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
