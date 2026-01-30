<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();
requireRole('owner');

$db = new Database();
$pdo = $db->getPdo();

$owner_id = $_SESSION['owner_id'] ?? null;
if (!$owner_id) {
    $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $owner_id = $stmt->fetchColumn();
    if ($owner_id) {
        $_SESSION['owner_id'] = (int)$owner_id;
    }
}

$requested_owner_id = $_GET['owner_id'] ?? null;
if ($requested_owner_id && (int)$requested_owner_id !== (int)$owner_id) {
    echo json_encode(["success" => false, "error" => "Invalid owner_id"]);
    exit;
}

if (!$owner_id) {
    echo json_encode(["success" => false, "error" => "Owner not found"]);
    exit;
}

try {
    // 1. Fetch owner base info
    $stmt = $pdo->prepare("
        SELECT o.*, ot.*
        FROM owners o
        LEFT JOIN owner_types ot ON o.owner_type_id = ot.id
        WHERE o.id = ?
    ");
    $stmt->execute([$owner_id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(["success" => false, "error" => "Owner not found"]);
        exit;
    }

    // 2. Prepare type-specific fields (owner_types only)
    $type_field_keys = [
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

    $type_fields = [];
    foreach ($type_field_keys as $key) {
        if (array_key_exists($key, $row)) {
            $type_fields[$key] = $row[$key];
        }
    }

    echo json_encode([
        "success" => true,
        "data" => [
            "owner_id"       => $row['id'],
            "phone"          => $row['phone'],
            "address"        => $row['address'],
            "owner_type_id"  => $row['owner_type_id'],
            "type_name"      => $row['type_name'],
            "type_fields"    => $type_fields
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
