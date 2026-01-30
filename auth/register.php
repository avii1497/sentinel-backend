<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json');

try {
    $db  = new Database();
    $pdo = $db->getPdo();

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isMultipart = stripos($contentType, 'multipart/form-data') !== false;

    if ($isMultipart) {
        $src = $_POST;
        $files = $_FILES ?? [];
    } else {
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        $src = $payload;
        $files = [];
    }

    // ---------- Read core fields ----------
    $firstName = trim((string)($src['first_name'] ?? ''));
    $lastName  = trim((string)($src['last_name'] ?? ''));
    $email     = strtolower(trim((string)($src['email'] ?? '')));
    $password  = (string)($src['password'] ?? '');
    $role      = (string)($src['role'] ?? 'customer');
    $roleData  = $src['roleData'] ?? [];
    if (is_string($roleData)) {
        $decoded = json_decode($roleData, true);
        $roleData = is_array($decoded) ? $decoded : [];
    } elseif (!is_array($roleData)) {
        $roleData = [];
    }

    // ---------- Validation ----------
    if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
        throw new RuntimeException('All required fields are required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email format.');
    }

    // Premium customer removed from registration
    $validRoles = ['customer', 'owner', 'agent']; // CHANGED
    if (!in_array($role, $validRoles, true)) {
        throw new RuntimeException('Invalid role provided.');
    }

    // Duplicate email
    $existingUser = $db->getUserByEmail($email);
    if ($existingUser) {
        throw new RuntimeException('Email already exists.');
    }

    $pdo->beginTransaction();

    // ---------- Create user ----------
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (first_name, last_name, email, password_hash, role, status, email_verified, created_at)
        VALUES (:first_name, :last_name, :email, :password_hash, :role, 0, 0, NOW())
    ");
    $stmt->execute([
        ':first_name'    => $firstName,
        ':last_name'     => $lastName,
        ':email'         => $email,
        ':password_hash' => $passwordHash,
        ':role'          => $role,
    ]);

    $userId = (int)$pdo->lastInsertId();
    $agentId = null;
    $ownerId = null;

    // ---------- Helpers ----------
    function ensure_dir($path) {
        if (!is_dir($path)) { @mkdir($path, 0777, true); }
        return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    function compressAndSaveImage($sourcePath, $destinationPath, $maxWidth = 800, $quality = 80) {
        // If GD is missing, just move
        if (!function_exists('imagecreatefromjpeg')) {
            return move_uploaded_file($sourcePath, $destinationPath);
        }
        $info = @getimagesize($sourcePath);
        if (!$info) return false;

        [$width, $height] = $info;
        $mime = $info['mime'] ?? '';

        switch ($mime) {
            case 'image/jpeg': $srcImage = imagecreatefromjpeg($sourcePath); break;
            case 'image/png':  $srcImage = imagecreatefrompng($sourcePath);  break;
            case 'image/webp': $srcImage = imagecreatefromwebp($sourcePath); break;
            default:
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

    // ---------- Optional uploads for AGENT ----------
    $agentProfilePhotoPath = null;
    $agentCvFilePath = null;

    if ($role === 'agent' && $isMultipart) {
        if (!empty($files['profile_photo']['tmp_name'])) {
            $uploadDir = ensure_dir(__DIR__ . '/../../agent/uploads/photos');
            $uploadInfo = validateUpload(
                $files['profile_photo'],
                ['jpg', 'jpeg', 'png', 'webp'],
                ['image/jpeg', 'image/png', 'image/webp'],
                5 * 1024 * 1024
            );
            $name = uniqid('agent_photo_') . '.' . $uploadInfo['ext'];
            $full = $uploadDir . $name;

            if (!compressAndSaveImage($files['profile_photo']['tmp_name'], $full)) {
                throw new RuntimeException('Failed to process profile photo.');
            }
            $agentProfilePhotoPath = 'agent/uploads/photos/' . $name; // CHANGED: fixed missing slash
        }

        if (!empty($files['cv_file']['tmp_name'])) {
            $cvDir = ensure_dir(__DIR__ . '/../../uploads/agents/cv');
            $uploadInfo = validateUpload(
                $files['cv_file'],
                ['pdf', 'doc', 'docx'],
                ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                10 * 1024 * 1024
            );
            $cvName = uniqid('agent_cv_') . '.' . $uploadInfo['ext'];
            $cvFull = $cvDir . $cvName;
            if (!move_uploaded_file($files['cv_file']['tmp_name'], $cvFull)) {
                throw new RuntimeException('Failed to upload CV.');
            }
            $agentCvFilePath = 'uploads/agents/cv/' . $cvName;
        }
    }

    // ---------- Optional upload for OWNER profile photo (new) ----------
    $ownerProfilePicPath = null;
    if ($role === 'owner' && $isMultipart && !empty($files['profile_pic']['tmp_name'])) {
        $ownerDir = ensure_dir(__DIR__ . '/../../uploads/owners/photos');
        $uploadInfo = validateUpload(
            $files['profile_pic'],
            ['jpg', 'jpeg', 'png', 'webp'],
            ['image/jpeg', 'image/png', 'image/webp'],
            5 * 1024 * 1024
        );
        $name = uniqid('owner_photo_') . '.' . $uploadInfo['ext'];
        $full = $ownerDir . $name;

        // simple mime check
        $ok = compressAndSaveImage($files['profile_pic']['tmp_name'], $full);
        if (!$ok) throw new RuntimeException('Failed to process owner profile photo.');

        $ownerProfilePicPath = 'uploads/owners/photos/' . $name;
    }

    // ---------- Role specific inserts ----------
    switch ($role) {
        case 'agent':
            $stmt = $pdo->prepare("
                INSERT INTO agents (
                    user_id, license_no, specialization, commission_rate, phone,
                    profile_photo, cv_file, nic, agency, position, years_of_experience,
                    work_schedule, status, office_address, whatsapp_number,
                    area_of_operation, bio, created_at
                ) VALUES (
                    :user_id, :license_no, :specialization, :commission_rate, :phone,
                    :profile_photo, :cv_file, :nic, :agency, :position, :years_of_experience,
                    :work_schedule, :status, :office_address, :whatsapp_number,
                    :area_of_operation, :bio, NOW()
                )
            ");
            $stmt->execute([
                ':user_id'             => $userId,
                ':license_no'          => $roleData['license_no'] ?? null,
                ':specialization'      => $roleData['specialization'] ?? null,
                ':commission_rate'     => $roleData['commission_rate'] ?? null,
                ':phone'               => $roleData['phone'] ?? null,
                ':profile_photo'       => $agentProfilePhotoPath,
                ':cv_file'             => $agentCvFilePath,
                ':nic'                 => $roleData['nic'] ?? null,
                ':agency'              => $roleData['agency'] ?? null,
                ':position'            => $roleData['position'] ?? null,
                ':years_of_experience' => $roleData['years_of_experience'] ?? null,
                ':work_schedule'       => $roleData['work_schedule'] ?? null,
                ':status'              => $roleData['status'] ?? 'Active',
                ':office_address'      => $roleData['office_address'] ?? null,
                ':whatsapp_number'     => $roleData['whatsapp_number'] ?? null,
                ':area_of_operation'   => $roleData['area_of_operation'] ?? null,
                ':bio'                 => $roleData['bio'] ?? null,
            ]);
            $agentId = (int)$pdo->lastInsertId();
            break;

        case 'owner':
            $stmt = $pdo->prepare("
                INSERT INTO owners (
                    user_id, company_name, business_type, tax_id, phone, address, profile_pic, created_at
                ) VALUES (
                    :user_id, :company_name, :business_type, :tax_id, :phone, :address, :profile_pic, NOW()
                )
            ");
            $stmt->execute([
                ':user_id'       => $userId,
                ':company_name'  => $roleData['company_name']  ?? null,
                ':business_type' => $roleData['business_type'] ?? null,
                ':tax_id'        => $roleData['tax_id']        ?? null,
                ':phone'         => $roleData['phone']         ?? null,
                ':address'       => $roleData['address']       ?? null,
                ':profile_pic'   => $ownerProfilePicPath,
            ]);
            $ownerId = (int)$pdo->lastInsertId();
            break;

        case 'customer':
            // keep customer simple
            $stmt = $pdo->prepare("
                INSERT INTO customers (user_id, preferred_city, budget_min, budget_max, notes, created_at)
                VALUES (:user_id, :preferred_city, :budget_min, :budget_max, :notes, NOW())
            ");
            $stmt->execute([
                ':user_id'        => $userId,
                ':preferred_city' => $roleData['preferred_city'] ?? null,
                ':budget_min'     => $roleData['budget_min'] ?? null,
                ':budget_max'     => $roleData['budget_max'] ?? null,
                ':notes'          => $roleData['notes'] ?? null,
            ]);
            break;
    }

    $pdo->commit();

    echo json_encode([
        'success'       => true,
        'message'       => 'Account created successfully!',
        'role'          => $role,
        'user_id'       => $userId,
        'agent_id'      => $agentId,
        'owner_id'      => $ownerId,
        'agent_photo'   => $agentProfilePhotoPath,
        'agent_cv'      => $agentCvFilePath,
        'owner_photo'   => $ownerProfilePicPath,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
