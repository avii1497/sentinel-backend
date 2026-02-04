<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
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
    $src = sanitize_array($src ?? []);

    // ---------- Read core fields ----------
    $firstName = v_string($src['first_name'] ?? null, 'first name', 100);
    $lastName  = v_string($src['last_name'] ?? null, 'last name', 100);
    $email     = v_email($src['email'] ?? null, 'email');
    $password  = v_string($src['password'] ?? null, 'password', 256);
    $role      = (string)($src['role'] ?? 'customer');
    $roleData  = $src['roleData'] ?? [];
    if (is_string($roleData)) {
        $decoded = json_decode($roleData, true);
        $roleData = is_array($decoded) ? $decoded : [];
    } elseif (!is_array($roleData)) {
        $roleData = [];
    }
    $roleData = sanitize_array($roleData ?? []);

    
    $validRoles = ['customer', 'owner', 'agent']; 
    $role = v_enum($role, 'role', $validRoles);

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

    $agentData = [];
    $ownerData = [];
    $customerData = [];

    if ($role === 'agent') {
        $agentData = [
            'license_no'          => v_string($roleData['license_no'] ?? null, 'license no', 100, 0, false),
            'specialization'      => v_string($roleData['specialization'] ?? null, 'specialization', 200, 0, false),
            'commission_rate'     => v_float($roleData['commission_rate'] ?? null, 'commission rate', 0, 100, false),
            'phone'               => v_phone($roleData['phone'] ?? null, 'phone', false),
            'nic'                 => v_string($roleData['nic'] ?? null, 'nic', 50, 0, false),
            'agency'              => v_string($roleData['agency'] ?? null, 'agency', 200, 0, false),
            'position'            => v_string($roleData['position'] ?? null, 'position', 100, 0, false),
            'years_of_experience' => v_int($roleData['years_of_experience'] ?? null, 'years of experience', 0, 80, false),
            'work_schedule'       => v_string($roleData['work_schedule'] ?? null, 'work schedule', 200, 0, false),
            'status'              => v_string($roleData['status'] ?? 'Active', 'status', 20, 1, false),
            'office_address'      => v_string($roleData['office_address'] ?? null, 'office address', 255, 0, false),
            'whatsapp_number'     => v_phone($roleData['whatsapp_number'] ?? null, 'whatsapp number', false),
            'area_of_operation'   => v_string($roleData['area_of_operation'] ?? null, 'area of operation', 200, 0, false),
            'bio'                 => v_string($roleData['bio'] ?? null, 'bio', 2000, 0, false),
        ];
    } elseif ($role === 'owner') {
        $ownerData = [
            'company_name'  => v_string($roleData['company_name'] ?? null, 'company name', 200, 0, false),
            'business_type' => v_string($roleData['business_type'] ?? null, 'business type', 100, 0, false),
            'tax_id'        => v_string($roleData['tax_id'] ?? null, 'tax id', 50, 0, false),
            'phone'         => v_phone($roleData['phone'] ?? null, 'phone', false),
            'address'       => v_string($roleData['address'] ?? null, 'address', 255, 0, false),
        ];
    } else {
        $customerData = [
            'preferred_city' => v_string($roleData['preferred_city'] ?? null, 'preferred city', 100, 0, false),
            'budget_min'     => v_float($roleData['budget_min'] ?? null, 'budget min', 0, 1000000000, false),
            'budget_max'     => v_float($roleData['budget_max'] ?? null, 'budget max', 0, 1000000000, false),
            'notes'          => v_string($roleData['notes'] ?? null, 'notes', 1000, 0, false),
        ];
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
                ':license_no'          => $agentData['license_no'] ?? null,
                ':specialization'      => $agentData['specialization'] ?? null,
                ':commission_rate'     => $agentData['commission_rate'] ?? null,
                ':phone'               => $agentData['phone'] ?? null,
                ':profile_photo'       => $agentProfilePhotoPath,
                ':cv_file'             => $agentCvFilePath,
                ':nic'                 => $agentData['nic'] ?? null,
                ':agency'              => $agentData['agency'] ?? null,
                ':position'            => $agentData['position'] ?? null,
                ':years_of_experience' => $agentData['years_of_experience'] ?? null,
                ':work_schedule'       => $agentData['work_schedule'] ?? null,
                ':status'              => $agentData['status'] ?? 'Active',
                ':office_address'      => $agentData['office_address'] ?? null,
                ':whatsapp_number'     => $agentData['whatsapp_number'] ?? null,
                ':area_of_operation'   => $agentData['area_of_operation'] ?? null,
                ':bio'                 => $agentData['bio'] ?? null,
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
                ':company_name'  => $ownerData['company_name']  ?? null,
                ':business_type' => $ownerData['business_type'] ?? null,
                ':tax_id'        => $ownerData['tax_id']        ?? null,
                ':phone'         => $ownerData['phone']         ?? null,
                ':address'       => $ownerData['address']       ?? null,
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
                ':preferred_city' => $customerData['preferred_city'] ?? null,
                ':budget_min'     => $customerData['budget_min'] ?? null,
                ':budget_max'     => $customerData['budget_max'] ?? null,
                ':notes'          => $customerData['notes'] ?? null,
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
