<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();
requireRole('owner');
requireCsrf();

$data = json_decode(file_get_contents("php://input"), true) ?? [];
$data = sanitize_array($data ?? []);

$db = new Database();
$pdo = $db->getPdo();

$payloadOwnerId = $data['owner_id'] ?? null;
$owner_type_id = v_int($data['owner_type_id'] ?? null, 'owner type id');

$phone         = $data['phone'] ?? null;
$address       = $data['address'] ?? null;

$type_fields   = $data['type_fields'] ?? [];
if (!is_array($type_fields)) {
    bad_request('type_fields must be an object.');
}

// Resolve owner_id from session (source of truth)
$owner_id = $_SESSION['owner_id'] ?? null;
if (!$owner_id) {
    $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $owner_id = $stmt->fetchColumn();
    if ($owner_id) {
        $_SESSION['owner_id'] = (int)$owner_id;
    }
}

if ($payloadOwnerId !== null) {
    $payloadOwnerId = v_int($payloadOwnerId, 'owner id', 1, 2147483647, false);
}
if ($payloadOwnerId && (int)$payloadOwnerId !== (int)$owner_id) {
    echo json_encode(["success" => false, "error" => "Invalid owner_id"]);
    exit;
}

if (!$owner_id || !$owner_type_id) {
    echo json_encode(["success" => false, "error" => "Missing required fields"]);
    exit;
}

try {
    // ===============================
    // 1. UPDATE Owners base fields
    // ===============================
    if ($phone === '') $phone = null;
    if ($address === '') $address = null;
    if ($phone !== null) {
        $phone = v_phone($phone, 'phone', true);
    }
    if ($address !== null) {
        $address = v_string($address, 'address', 255, 1, true);
    }
    $stmt = $pdo->prepare("
        UPDATE owners
        SET owner_type_id = ?, phone = ?, address = ?
        WHERE id = ?
    ");
    $stmt->execute([$owner_type_id, $phone, $address, $owner_id]);


    // ===============================
    // 2. UPDATE Owner Type Fields
    // ===============================
    if (!empty($type_fields)) {
        $allowedTypeFields = [
            'agency_name',
            'agency_license_no',
            'agency_tax_id',
            'lease_number',
            'lease_expiry_date',
            'lease_renewal_date',
            'annual_lease_fee',
            'government_authority',
            'co_ownership_group',
            'co_ownership_percentage',
            'syndic_name',
            'syndic_contact',
            'is_primary_contact',
        ];
        $setParts = [];
        $values = [];

        foreach ($type_fields as $field => $value) {
            if (!in_array($field, $allowedTypeFields, true)) {
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '') {
                $value = null;
            }
            if ($value !== null) {
                switch ($field) {
                    case 'annual_lease_fee':
                        $value = v_float($value, 'annual lease fee', 0, 1000000000, true);
                        break;
                    case 'co_ownership_percentage':
                        $value = v_float($value, 'co ownership percentage', 0, 100, true);
                        break;
                    case 'is_primary_contact':
                        $value = v_bool($value, 'is primary contact', true);
                        break;
                    case 'lease_expiry_date':
                    case 'lease_renewal_date':
                        $value = v_date($value, $field, true);
                        break;
                    default:
                        $value = v_string($value, $field, 255, 0, false);
                        break;
                }
            }
            $setParts[] = "$field = ?";
            $values[] = $value;
        }

        if (!empty($setParts)) {
            $sql = "UPDATE owner_types SET " . implode(", ", $setParts) . " WHERE id = ?";
            $values[] = $owner_type_id;

            $stmt2 = $pdo->prepare($sql);
            $stmt2->execute($values);
        }
    }

    echo json_encode([
        "success" => true,
        "message" => "Owner profile updated successfully."
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
