<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header("Content-Type: application/json");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();
requireRole('agent');
requireCsrf();

$db = new Database();
$pdo = $db->getPdo();

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

if ($isMultipart) {
    $src = $_POST;
    $files = $_FILES ?? [];
} else {
    $src = json_decode(file_get_contents('php://input'), true) ?? [];
    $files = [];
}
$src = sanitize_array($src ?? []);

$userId = (int)($_SESSION['user_id'] ?? 0);
$agentId = $_SESSION['agent_id'] ?? null;

if (!$agentId) {
    $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $agentId = $stmt->fetchColumn();
    if ($agentId) {
        $_SESSION['agent_id'] = (int)$agentId;
    }
}

if (!$agentId) {
    echo json_encode(["success" => false, "error" => "Agent profile not found."]);
    exit;
}

$payloadAgentId = $src['agent_id'] ?? null;
if ($payloadAgentId !== null) {
    $payloadAgentId = v_int($payloadAgentId, 'agent id', 1, 2147483647, false);
}
if ($payloadAgentId && (int)$payloadAgentId !== (int)$agentId) {
    echo json_encode(["success" => false, "error" => "Invalid agent_id."]);
    exit;
}

function ensure_dir($path) {
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
}

function compressAndSaveImage($sourcePath, $destinationPath, $maxWidth = 800, $quality = 80) {
    if (!function_exists('imagecreatefromjpeg')) {
        return move_uploaded_file($sourcePath, $destinationPath);
    }
    $info = @getimagesize($sourcePath);
    if (!$info) return false;

    [$width, $height] = $info;
    $mime = $info['mime'] ?? '';

    switch ($mime) {
        case 'image/jpeg': $srcImage = @imagecreatefromjpeg($sourcePath); break;
        case 'image/png':  $srcImage = @imagecreatefrompng($sourcePath);  break;
        case 'image/webp': $srcImage = @imagecreatefromwebp($sourcePath); break;
        default:
            return move_uploaded_file($sourcePath, $destinationPath);
    }
    if (!$srcImage) {
        return move_uploaded_file($sourcePath, $destinationPath);
    }

    $ratio = $width / max(1, $height);
    $newWidth  = ($width > $maxWidth) ? $maxWidth : $width;
    $newHeight = (int)round($newWidth / $ratio);

    $dstImage = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    switch ($mime) {
        case 'image/jpeg': imagejpeg($dstImage, $destinationPath, $quality); break;
        case 'image/png':  imagepng($dstImage, $destinationPath, 8); break;
        case 'image/webp': imagewebp($dstImage, $destinationPath, $quality); break;
    }
    imagedestroy($srcImage);
    imagedestroy($dstImage);
    return true;
}

$allowed = [
    'license_no',
    'specialization',
    'commission_rate',
    'phone',
    'nic',
    'agency',
    'position',
    'years_of_experience',
    'work_schedule',
    'office_address',
    'whatsapp_number',
    'area_of_operation',
    'bio',
];

$set = [];
$params = [];

foreach ($allowed as $field) {
    if (array_key_exists($field, $src)) {
        $value = $src[$field];
        switch ($field) {
            case 'commission_rate':
                $value = v_float($value, 'commission rate', 0, 100, false);
                break;
            case 'years_of_experience':
                $value = v_int($value, 'years of experience', 0, 80, false);
                break;
            case 'phone':
                $value = v_phone($value, 'phone', false);
                break;
            case 'whatsapp_number':
                $value = v_phone($value, 'whatsapp number', false);
                break;
            case 'license_no':
                $value = v_string($value, 'license no', 100, 0, false);
                break;
            case 'specialization':
                $value = v_string($value, 'specialization', 200, 0, false);
                break;
            case 'nic':
                $value = v_string($value, 'nic', 50, 0, false);
                break;
            case 'agency':
                $value = v_string($value, 'agency', 200, 0, false);
                break;
            case 'position':
                $value = v_string($value, 'position', 100, 0, false);
                break;
            case 'work_schedule':
                $value = v_string($value, 'work schedule', 200, 0, false);
                break;
            case 'office_address':
                $value = v_string($value, 'office address', 255, 0, false);
                break;
            case 'area_of_operation':
                $value = v_string($value, 'area of operation', 200, 0, false);
                break;
            case 'bio':
                $value = v_string($value, 'bio', 2000, 0, false);
                break;
            default:
                $value = v_string($value, $field, 255, 0, false);
                break;
        }
        if ($value === '') {
            $value = null;
        }
        $set[] = "$field = ?";
        $params[] = $value;
    }
}

$profilePhotoPath = null;
if (
    $isMultipart &&
    isset($files['profile_photo']) &&
    !empty($files['profile_photo']['tmp_name'])
) {
    $uploadDir = ensure_dir(__DIR__ . '/uploads/photos');
    try {
        $uploadInfo = validateUpload(
            $files['profile_photo'],
            ['jpg', 'jpeg', 'png', 'webp'],
            ['image/jpeg', 'image/png', 'image/webp', 'image/jpg', 'application/octet-stream'],
            5 * 1024 * 1024
        );
    } catch (RuntimeException $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
        exit;
    }
    $name = uniqid('agent_photo_') . '.' . $uploadInfo['ext'];
    $full = $uploadDir . $name;

    if (!compressAndSaveImage($files['profile_photo']['tmp_name'], $full)) {
        echo json_encode(["success" => false, "error" => "Failed to process profile photo."]);
        exit;
    }
    $profilePhotoPath = 'agent/uploads/photos/' . $name;
    $set[] = "profile_photo = ?";
    $params[] = $profilePhotoPath;
}

$cvFilePath = null;
if (
    $isMultipart &&
    isset($files['cv_file']) &&
    !empty($files['cv_file']['tmp_name'])
) {
    $cvDir = ensure_dir(__DIR__ . '/../uploads/agents/cv');
    try {
        $uploadInfo = validateUpload(
            $files['cv_file'],
            ['pdf', 'doc', 'docx'],
            [
                'application/pdf',
                'application/x-pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/octet-stream'
            ],
            10 * 1024 * 1024
        );
    } catch (RuntimeException $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
        exit;
    }
    $name = uniqid('agent_cv_') . '.' . $uploadInfo['ext'];
    $full = $cvDir . $name;
    if (!move_uploaded_file($files['cv_file']['tmp_name'], $full)) {
        echo json_encode(["success" => false, "error" => "Failed to upload CV."]);
        exit;
    }
    $cvFilePath = 'uploads/agents/cv/' . $name;
    $set[] = "cv_file = ?";
    $params[] = $cvFilePath;
}

$userUpdates = [];
if (array_key_exists('first_name', $src)) {
    $userUpdates['first_name'] = v_string($src['first_name'], 'first name', 100, 1, false);
}
if (array_key_exists('last_name', $src)) {
    $userUpdates['last_name'] = v_string($src['last_name'], 'last name', 100, 1, false);
}

if (empty($set) && empty($userUpdates)) {
    echo json_encode(["success" => false, "error" => "No changes provided."]);
    exit;
}

try {
    if (!empty($set)) {
        $params[] = (int)$agentId;
        $sql = "UPDATE agents SET " . implode(", ", $set) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    if (!empty($userUpdates)) {
        $db->updateUserProfile($userId, $userUpdates);
        if (isset($userUpdates['first_name'])) {
            $_SESSION['first_name'] = $userUpdates['first_name'];
        }
        if (isset($userUpdates['last_name'])) {
            $_SESSION['last_name'] = $userUpdates['last_name'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            a.id AS agent_id,
            a.license_no,
            a.specialization,
            a.commission_rate,
            a.phone,
            a.profile_photo,
            a.cv_file,
            a.nic,
            a.agency,
            a.position,
            a.years_of_experience,
            a.work_schedule,
            a.office_address,
            a.whatsapp_number,
            a.area_of_operation,
            a.bio,
            u.first_name,
            u.last_name,
            u.email
        FROM agents a
        JOIN users u ON a.user_id = u.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$agentId]);
    $agent = $stmt->fetch();

    echo json_encode([
        "success" => true,
        "message" => "Agent profile updated successfully.",
        "data" => $agent
    ]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
