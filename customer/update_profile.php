<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header('Content-Type: application/json');

requireLogin();
requireRole(['customer', 'premium_customer']);
requireCsrf();

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;

if ($isMultipart) {
    $profile = $_POST ?? [];
    $files = $_FILES ?? [];
} else {
    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    $profile = $payload['profile'] ?? [];
    $files = [];
}
$profile = sanitize_array(is_array($profile) ? $profile : []);

$db = new Database();
$pdo = $db->getPdo();
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userFields = ['first_name', 'last_name', 'email', 'phone'];
$customerFields = ['preferred_city', 'budget_min', 'budget_max', 'notes', 'profile_photo'];

$userUpdates = [];
$userParams = [':id' => $userId];

foreach ($userFields as $field) {
    if (array_key_exists($field, $profile)) {
        $value = $profile[$field];
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '') {
            $value = null;
        }
        if ($value !== null) {
            switch ($field) {
                case 'email':
                    $value = v_email($value, 'email');
                    break;
                case 'phone':
                    $value = v_phone($value, 'phone', true);
                    break;
                default:
                    $value = v_string($value, $field, 100, 1, true);
                    break;
            }
        }
        $userUpdates[] = "`$field` = :$field";
        $userParams[":$field"] = $value;
    }
}

$customerUpdates = [];
$customerParams = [':user_id' => $userId];

foreach ($customerFields as $field) {
    if (array_key_exists($field, $profile)) {
        $value = $profile[$field];
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '') {
            $value = null;
        }
        if ($value !== null) {
            if (in_array($field, ['budget_min', 'budget_max'], true)) {
                $value = v_float($value, $field, 0, 1000000000, true);
            } elseif ($field === 'preferred_city') {
                $value = v_string($value, 'preferred city', 100, 1, true);
            } elseif ($field === 'notes') {
                $value = v_string($value, 'notes', 1000, 0, false);
            }
        }
        if ($field === 'profile_photo') {
            continue;
        }
        $customerUpdates[] = "`$field` = :$field";
        $customerParams[":$field"] = $value;
    }
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

$profilePhotoPath = null;
if (
    $isMultipart &&
    isset($files['profile_photo']) &&
    !empty($files['profile_photo']['tmp_name'])
) {
    $uploadDir = ensure_dir(__DIR__ . '/../uploads/customers/photos');
    try {
        $uploadInfo = validateUpload(
            $files['profile_photo'],
            ['jpg', 'jpeg', 'png', 'webp'],
            ['image/jpeg', 'image/png', 'image/webp', 'image/jpg', 'application/octet-stream'],
            5 * 1024 * 1024
        );
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    $name = uniqid('customer_photo_') . '.' . $uploadInfo['ext'];
    $full = $uploadDir . $name;

    if (!compressAndSaveImage($files['profile_photo']['tmp_name'], $full)) {
        echo json_encode(['success' => false, 'error' => 'Failed to process profile photo.']);
        exit;
    }
    $profilePhotoPath = 'uploads/customers/photos/' . $name;
    $customerUpdates[] = "profile_photo = :profile_photo";
    $customerParams[':profile_photo'] = $profilePhotoPath;
}

try {
    $pdo->beginTransaction();

    if ($userUpdates) {
        $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $userUpdates) . " WHERE id = :id");
        $stmt->execute($userParams);
    }

    $stmt = $pdo->prepare("SELECT id FROM customers WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $customerId = $stmt->fetchColumn();

    if (!$customerId) {
        $stmt = $pdo->prepare("INSERT INTO customers (user_id, created_at) VALUES (?, NOW())");
        $stmt->execute([$userId]);
    }

    if ($customerUpdates) {
        $stmt = $pdo->prepare(
            "UPDATE customers SET " . implode(', ', $customerUpdates) . " WHERE user_id = :user_id"
        );
        $stmt->execute($customerParams);
    }

    if (array_key_exists('first_name', $profile)) {
        $_SESSION['first_name'] = $profile['first_name'];
    }
    if (array_key_exists('last_name', $profile)) {
        $_SESSION['last_name'] = $profile['last_name'];
    }
    if (array_key_exists('email', $profile)) {
        $_SESSION['email'] = $profile['email'];
    }
    if (array_key_exists('phone', $profile)) {
        $_SESSION['phone'] = $profile['phone'];
    }
    if (array_key_exists('first_name', $profile) || array_key_exists('last_name', $profile)) {
        $first = $_SESSION['first_name'] ?? '';
        $last = $_SESSION['last_name'] ?? '';
        $_SESSION['name'] = trim($first . ' ' . $last);
    }

    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
