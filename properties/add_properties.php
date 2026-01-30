<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/property_amenities.php';

header("Content-Type: application/json");

function buildNominatimQuery(string $location): string
{
    $query = trim($location);
    if ($query === '') {
        return '';
    }

    if (stripos($query, 'mauritius') === false) {
        $query .= ', Mauritius';
    }

    return $query;
}

function geocodeWithNominatim(string $query): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $email = getenv('NOMINATIM_EMAIL') ?: '';
    $params = [
        'format' => 'jsonv2',
        'q' => $query,
        'limit' => 1,
        'addressdetails' => 0,
    ];
    if ($email !== '') {
        $params['email'] = $email;
    }

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);

    $userAgent = getenv('NOMINATIM_USER_AGENT');
    if (!$userAgent) {
        $userAgent = $email !== '' ? "SentinelProperty/1.0 ({$email})" : "SentinelProperty/1.0";
    }

    $response = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $userAgent,
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            return null;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: {$userAgent}\r\n",
                'timeout' => 8,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data)) {
        return null;
    }

    $lat = isset($data[0]['lat']) ? (float)$data[0]['lat'] : null;
    $lng = isset($data[0]['lon']) ? (float)$data[0]['lon'] : null;
    if (!is_finite($lat) || !is_finite($lng)) {
        return null;
    }

    return ['lat' => $lat, 'lng' => $lng];
}

try {
    $db  = new Database();
    $pdo = $db->getPdo();

    // ✅ Guards
    requireLogin();
    requireRole('owner');
    requireCsrf();

    // =========================================================
    // ✅ Directory setup
    // =========================================================
    // NOTE: These are relative to THIS php file location.
    $galleryDir = __DIR__ . '/uploads/gallery/';
    $modelDir   = __DIR__ . '/uploads/models/';
    $docsDir    = __DIR__ . '/uploads/documents/';

    foreach ([$galleryDir, $modelDir, $docsDir] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    // =========================================================
    // ✅ Inputs (MUST come before validation)
    // =========================================================
    $requested_owner_id = isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;

    $owner_id          = $_SESSION['owner_id'] ?? null;
    $user_id           = (int)($_SESSION['user_id'] ?? 0);

    $title             = trim($_POST['title'] ?? '');
    $location          = trim($_POST['location'] ?? '');
    $description       = trim($_POST['description'] ?? '');
    $price             = $_POST['price'] ?? 0;
    $latitudeRaw       = trim($_POST['latitude'] ?? '');
    $longitudeRaw      = trim($_POST['longitude'] ?? '');

    $assigned_agent_id = $_POST['assigned_agent_id'] ?? null;
    $property_type_id  = $_POST['property_type_id'] ?? null;
    $listing_type_id   = $_POST['listing_type_id'] ?? null;

    $bedrooms          = $_POST['bedrooms'] ?? 0;
    $bathrooms         = $_POST['bathrooms'] ?? 0;
    $area_sqft         = $_POST['area_sqft'] ?? 0;
    $status            = $_POST['status'] ?? 'Available';
    $vr_link           = trim($_POST['vr_link'] ?? '');

    $is_premium_listing = (isset($_POST['is_premium_listing']) && $_POST['is_premium_listing'] === "1") ? 1 : 0;

    // =========================================================
    // ✅ Ensure owner_id exists (fallback if session missing)
    // =========================================================
    if (!$owner_id) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $owner_id = $stmt->fetchColumn();
        if ($owner_id) {
            $_SESSION['owner_id'] = $owner_id; // keep session in sync
        }
    }

    // =========================================================
    // ✅ Basic validations
    // =========================================================
    if (!$owner_id) {
        throw new Exception("Owner not identified.");
    }

    if ($requested_owner_id && (int)$requested_owner_id !== (int)$owner_id) {
        throw new Exception("Invalid owner_id.");
    }

    if ($title === '') {
        throw new Exception("Title is required.");
    }

    if ($location === '') {
        throw new Exception("Location is required.");
    }

    if (!is_numeric($price) || (float)$price <= 0) {
        throw new Exception("Price must be greater than 0.");
    }

    // =========================================================
    // ✅ Validate owner & owner type (only primary co-owner can add)
    // =========================================================
    $stmtOwner = $pdo->prepare("
        SELECT 
            o.id,
            o.owner_type_id,
            o.is_primary_contact,
            ot.type_name
        FROM owners o
        LEFT JOIN owner_types ot ON ot.id = o.owner_type_id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmtOwner->execute([$owner_id]);
    $ownerRow = $stmtOwner->fetch(PDO::FETCH_ASSOC);

    if (!$ownerRow) {
        throw new Exception("Owner not found.");
    }

    $ownerTypeName = strtolower($ownerRow['type_name'] ?? '');
    if (strpos($ownerTypeName, 'co') !== false) {
        $isPrimary = (int)($ownerRow['is_primary_contact'] ?? 0);
        if ($isPrimary !== 1) {
            throw new Exception("Only the primary co-owner can add properties for this account.");
        }
    }

    // =========================================================
    // ✅ Clean numeric fields
    // =========================================================
    $assigned_agent_id = is_numeric($assigned_agent_id) ? (int)$assigned_agent_id : null;
    $property_type_id  = is_numeric($property_type_id) ? (int)$property_type_id : null;
    $listing_type_id   = is_numeric($listing_type_id) ? (int)$listing_type_id : null;

    $bedrooms  = is_numeric($bedrooms) ? (int)$bedrooms : 0;
    $bathrooms = is_numeric($bathrooms) ? (int)$bathrooms : 0;
    $area_sqft = is_numeric($area_sqft) ? (float)$area_sqft : 0;
    $latitude  = is_numeric($latitudeRaw) ? (float)$latitudeRaw : null;
    $longitude = is_numeric($longitudeRaw) ? (float)$longitudeRaw : null;

    if (!is_finite($latitude) || !is_finite($longitude)) {
        $latitude = null;
        $longitude = null;
        $query = buildNominatimQuery($location);
        if ($query !== '') {
            $geo = geocodeWithNominatim($query);
            if ($geo) {
                $latitude = $geo['lat'];
                $longitude = $geo['lng'];
            }
        }
    }

    // =========================================================
    // ✅ Validate foreign keys
    // =========================================================
    // Agent must be linked AND accepted
    if ($assigned_agent_id) {
        $checkLink = $pdo->prepare("
            SELECT COUNT(*) 
            FROM owner_agent_link 
            WHERE owner_id = ? 
              AND agent_id = ? 
              AND status = 'Accepted'
        ");
        $checkLink->execute([$owner_id, $assigned_agent_id]);

        if ((int)$checkLink->fetchColumn() === 0) {
            throw new Exception("Selected agent is not an accepted collaborator for this owner.");
        }
    }

    // Property type exists?
    if ($property_type_id) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM property_types WHERE id = ?");
        $check->execute([$property_type_id]);
        if ((int)$check->fetchColumn() === 0) {
            $property_type_id = null;
        }
    }

    // Listing type exists?
    if ($listing_type_id) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM listing_types WHERE id = ?");
        $check->execute([$listing_type_id]);
        if ((int)$check->fetchColumn() === 0) {
            $listing_type_id = null;
        }
    }

    // =========================================================
    // ✅ Handle main gallery image (first image)
    // =========================================================
    $mainImage = null;

    if (!empty($_FILES['gallery']['name'][0]) && $_FILES['gallery']['error'][0] === UPLOAD_ERR_OK) {
        $uploadInfo = validateUpload(
            [
                'name'     => $_FILES['gallery']['name'][0],
                'type'     => $_FILES['gallery']['type'][0] ?? '',
                'tmp_name' => $_FILES['gallery']['tmp_name'][0],
                'error'    => $_FILES['gallery']['error'][0],
                'size'     => $_FILES['gallery']['size'][0] ?? 0,
            ],
            ['jpg', 'jpeg', 'png', 'webp'],
            ['image/jpeg', 'image/png', 'image/webp'],
            10 * 1024 * 1024
        );

        $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $uploadInfo['ext'];
        if (!move_uploaded_file($_FILES['gallery']['tmp_name'][0], $galleryDir . $fileName)) {
            throw new Exception("Failed to upload main image.");
        }
        $mainImage = "properties/uploads/gallery/" . $fileName;
    }

    // =========================================================
    // ✅ Handle 3D model
    // =========================================================
    $modelPath = null;

    if (!empty($_FILES['model_3d']['name']) && $_FILES['model_3d']['error'] === UPLOAD_ERR_OK) {
        $uploadInfo = validateUpload(
            $_FILES['model_3d'],
            ['glb', 'gltf'],
            ['model/gltf-binary', 'model/gltf+json', 'application/octet-stream'],
            50 * 1024 * 1024
        );

        $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $uploadInfo['ext'];
        if (!move_uploaded_file($_FILES['model_3d']['tmp_name'], $modelDir . $fileName)) {
            throw new Exception("Failed to upload 3D model.");
        }
        $modelPath = "properties/uploads/models/" . $fileName;
    }

    // =========================================================
    // ✅ Insert property
    // =========================================================
    $sql = "INSERT INTO properties 
        (owner_id, assigned_agent_id, title, description, location, price, latitude, longitude,
         property_type_id, listing_type_id, bedrooms, bathrooms, area_sqft,
         status, vr_link, model_3d_url, image_url, is_premium_listing)
        VALUES 
        (:owner_id, :assigned_agent_id, :title, :description, :location, :price, :latitude, :longitude,
         :property_type_id, :listing_type_id, :bedrooms, :bathrooms, :area_sqft,
         :status, :vr_link, :model_3d_url, :image_url, :is_premium_listing)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':owner_id'           => $owner_id,
        ':assigned_agent_id'  => $assigned_agent_id,
        ':title'              => $title,
        ':description'        => $description,
        ':location'           => $location,
        ':price'              => $price,
        ':latitude'           => $latitude,
        ':longitude'          => $longitude,
        ':property_type_id'   => $property_type_id,
        ':listing_type_id'    => $listing_type_id,
        ':bedrooms'           => $bedrooms,
        ':bathrooms'          => $bathrooms,
        ':area_sqft'          => $area_sqft,
        ':status'             => $status,
        ':vr_link'            => ($vr_link === '' ? null : $vr_link),
        ':model_3d_url'       => $modelPath,
        ':image_url'          => $mainImage,
        ':is_premium_listing' => $is_premium_listing
    ]);

    $propertyId = (int)$pdo->lastInsertId();

    $amenitiesProvided = false;
    $amenityIds = getAmenityIdsFromRequest($amenitiesProvided);
    if ($amenitiesProvided) {
        syncPropertyAmenities($pdo, $propertyId, $amenityIds);
    }

    // =========================================================
    // ✅ Save property documents (if any)
    // =========================================================
    if (
        isset($_FILES['documents'], $_FILES['documents']['name']) &&
        is_array($_FILES['documents']['name']) &&
        isset($_POST['document_keys'], $_POST['document_labels'])
    ) {
        $docKeys   = $_POST['document_keys'];
        $docLabels = $_POST['document_labels'];

        foreach ($_FILES['documents']['name'] as $index => $name) {
            if (($_FILES['documents']['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $file = [
                'name'     => $_FILES['documents']['name'][$index] ?? '',
                'type'     => $_FILES['documents']['type'][$index] ?? '',
                'tmp_name' => $_FILES['documents']['tmp_name'][$index] ?? '',
                'error'    => $_FILES['documents']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $_FILES['documents']['size'][$index] ?? 0,
            ];

            $uploadInfo = validateUpload(
                $file,
                ['pdf', 'doc', 'docx'],
                [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ],
                10 * 1024 * 1024
            );

            $originalName = basename($name);
            $safeNameBase = time() . '_' . $index . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $originalName);
            $safeName     = pathinfo($safeNameBase, PATHINFO_FILENAME) . '.' . $uploadInfo['ext'];

            if (!move_uploaded_file($_FILES['documents']['tmp_name'][$index], $docsDir . $safeName)) {
                throw new Exception("Failed to upload document: " . $originalName);
            }

            $fileUrl       = "properties/uploads/documents/" . $safeName;
            $documentKey   = $docKeys[$index] ?? 'other';
            $documentLabel = $docLabels[$index] ?? $originalName;

            $insertDoc = $pdo->prepare("
                INSERT INTO property_documents 
                (property_id, document_key, document_label, file_url, uploaded_by)
                VALUES (:property_id, :document_key, :document_label, :file_url, 'owner')
            ");
            $insertDoc->execute([
                ':property_id'    => $propertyId,
                ':document_key'   => $documentKey,
                ':document_label' => $documentLabel,
                ':file_url'       => $fileUrl,
            ]);
        }
    }

    // =========================================================
    // ✅ Save extra gallery images (index 1..n)
    // =========================================================
    if (!empty($_FILES['gallery']['name']) && is_array($_FILES['gallery']['name'])) {
        $count = count($_FILES['gallery']['name']);
        for ($i = 1; $i < $count; $i++) {
            if (($_FILES['gallery']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $uploadInfo = validateUpload(
                [
                    'name'     => $_FILES['gallery']['name'][$i],
                    'type'     => $_FILES['gallery']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                    'error'    => $_FILES['gallery']['error'][$i],
                    'size'     => $_FILES['gallery']['size'][$i] ?? 0,
                ],
                ['jpg', 'jpeg', 'png', 'webp'],
                ['image/jpeg', 'image/png', 'image/webp'],
                10 * 1024 * 1024
            );

            $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $uploadInfo['ext'];

            if (!move_uploaded_file($_FILES['gallery']['tmp_name'][$i], $galleryDir . $fileName)) {
                continue;
            }

            $imgPath = "properties/uploads/gallery/" . $fileName;

            $pdo->prepare("INSERT INTO property_gallery (property_id, image_url) VALUES (?, ?)")
                ->execute([$propertyId, $imgPath]);
        }
    }

    echo json_encode([
        'success'            => true,
        'message'            => 'Property added successfully',
        'property_id'        => $propertyId,
        'is_premium_listing' => $is_premium_listing
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
