<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/property_amenities.php';
header('Content-Type: application/json');

try {
    $db = new Database();
    $pdo = $db->getPdo();

    requireLogin();
    requireRole('owner');
    requireCsrf();

    // 1) READ INPUTS
    $propertyId = (int)($_POST['property_id'] ?? 0);
    $requested_owner_id = (int)($_POST['owner_id'] ?? 0);
    $owner_id   = $_SESSION['owner_id'] ?? null;

    if ($propertyId <= 0) throw new Exception("Invalid property_id");
    if (!$owner_id) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $owner_id = $stmt->fetchColumn();
    }
    if (!$owner_id || ($requested_owner_id && $requested_owner_id !== (int)$owner_id)) {
        throw new Exception("Missing owner_id");
    }

    // 2) VALIDATE OWNER
    $ownerStmt = $pdo->prepare("
        SELECT o.id, o.owner_type_id, o.is_primary_contact, ot.type_name
        FROM owners o
        LEFT JOIN owner_types ot ON ot.id = o.owner_type_id
        WHERE o.id = ?
        LIMIT 1
    ");
    $ownerStmt->execute([$owner_id]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$owner) {
        throw new Exception("Owner not found.");
    }

    $ownerType = strtolower($owner['type_name'] ?? '');

    if (strpos($ownerType, 'co') !== false) {
        if ((int)$owner['is_primary_contact'] !== 1) {
            throw new Exception("Only the PRIMARY co-owner can update properties.");
        }
    }

    // 3) VALIDATE PROPERTY BELONGS TO OWNER
    // 3) VALIDATE PROPERTY BELONGS TO OWNER + GET CURRENT LISTING TYPE
$checkProperty = $pdo->prepare("
    SELECT owner_id, listing_type_id 
    FROM properties 
    WHERE id = ?
");
$checkProperty->execute([$propertyId]);
$prop = $checkProperty->fetch(PDO::FETCH_ASSOC);

    if (!$prop) throw new Exception("Property not found.");
    if ((int)$prop['owner_id'] !== $owner_id) {
        throw new Exception("Unauthorized: You do not own this property.");
    }

    $title = trim($_POST['title'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $price = $_POST['price'] ?? 0;

    if ($title === '') {
        throw new Exception("Title is required.");
    }
    if ($location === '') {
        throw new Exception("Location is required.");
    }
    if (!is_numeric($price) || (float)$price <= 0) {
        throw new Exception("Price must be greater than 0.");
    }

    // 4) LOAD CURRENT IMAGES
    $existing = $pdo->prepare("
        SELECT image_url, model_3d_url 
        FROM properties 
        WHERE id = ?
    ");
    $existing->execute([$propertyId]);
    $current = $existing->fetch(PDO::FETCH_ASSOC);

    $currentImage = $current['image_url'];
    $currentModel = $current['model_3d_url'];

    // 5) DIRECTORIES
    $galleryDir = __DIR__ . '/uploads/gallery/';
    $modelDir   = __DIR__ . '/uploads/models/';
    $docsDir    = __DIR__ . '/uploads/documents/'; // 🆕 same folder as add

    foreach ([$galleryDir, $modelDir, $docsDir] as $d) {
        if (!is_dir($d)) mkdir($d, 0777, true);
    }

    // 6) CLEAN FIELDS
    $is_premium_listing = ($_POST['is_premium_listing'] ?? "0") === "1" ? 1 : 0;

    $assigned_agent_id = is_numeric($_POST['assigned_agent_id'] ?? null)
        ? (int)$_POST['assigned_agent_id']
        : null;

    $property_type_id = is_numeric($_POST['property_type_id'] ?? null)
        ? (int)$_POST['property_type_id']
        : null;

   // CURRENT listing type in DB (could be 1=Sale, 2=Rent, 3=Lease, etc.)
$currentListingType = isset($prop['listing_type_id']) ? (int)$prop['listing_type_id'] : null;

// From form (can be null or a number)
$incomingListingType = is_numeric($_POST['listing_type_id'] ?? null)
    ? (int)$_POST['listing_type_id']
    : null;

// RENTAL-SAFE LOGIC:
if ($currentListingType === 2) {
    // 🔒 Property is already marked "For Rent" via Rental Settings.
    // Do NOT allow this update script to change it.
    $listing_type_id = 2;
} else {
    // Property is NOT rental yet

    if ($incomingListingType === 2) {
        // ❌ Block manual "For Rent" selection here
        throw new Exception("Please configure rentals in the Rental Settings page, not in Edit Property.");
    }

    // ✅ For Sale / Lease / Auction etc. — keep old validation
    if ($incomingListingType) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM listing_types WHERE id = ?");
        $check->execute([$incomingListingType]);
        if ($check->fetchColumn() == 0) {
            $listing_type_id = null;
        } else {
            $listing_type_id = $incomingListingType;
        }
    } else {
        $listing_type_id = null;
    }
}


    // 7) VALIDATE ASSIGNED AGENT
    if ($assigned_agent_id) {
        $linkCheck = $pdo->prepare("
            SELECT status 
            FROM owner_agent_link 
            WHERE owner_id = ? AND agent_id = ?
            LIMIT 1
        ");
        $linkCheck->execute([$owner_id, $assigned_agent_id]);
        $status = $linkCheck->fetchColumn();

        if ($status !== "Accepted") {
            throw new Exception("This agent is NOT an accepted collaborator. Assign a valid agent.");
        }
    }

    // 8) SAFE UPDATE: MAIN IMAGE
    $mainImage = $currentImage;

    if (!empty($_FILES['gallery']['name'][0]) && $_FILES['gallery']['error'][0] === 0) {
        $uploadInfo = validateUpload(
            [
                'name' => $_FILES['gallery']['name'][0],
                'type' => $_FILES['gallery']['type'][0] ?? '',
                'tmp_name' => $_FILES['gallery']['tmp_name'][0],
                'error' => $_FILES['gallery']['error'][0],
                'size' => $_FILES['gallery']['size'][0] ?? 0,
            ],
            ['jpg', 'jpeg', 'png', 'webp'],
            ['image/jpeg', 'image/png', 'image/webp'],
            10 * 1024 * 1024
        );
        $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $uploadInfo['ext'];
        move_uploaded_file($_FILES['gallery']['tmp_name'][0], $galleryDir . $fileName);
        $mainImage = "properties/uploads/gallery/" . $fileName;
    }

    // 9) SAFE UPDATE: 3D MODEL
    $modelPath = $currentModel;

    if (!empty($_FILES['model_3d']['name']) && $_FILES['model_3d']['error'] === 0) {
        $uploadInfo = validateUpload(
            $_FILES['model_3d'],
            ['glb', 'gltf', 'obj', 'fbx'],
            ['model/gltf-binary', 'model/gltf+json', 'application/octet-stream'],
            50 * 1024 * 1024
        );
        $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $uploadInfo['ext'];
        move_uploaded_file($_FILES['model_3d']['tmp_name'], $modelDir . $fileName);
        $modelPath = "properties/uploads/models/" . $fileName;
    }

    // 10) UPDATE PROPERTY
    $stmt = $pdo->prepare("
        UPDATE properties 
        SET 
            title = :title,
            description = :description,
            location = :location,
            price = :price,
            property_type_id = :property_type_id,
            listing_type_id = :listing_type_id,
            bedrooms = :bedrooms,
            bathrooms = :bathrooms,
            area_sqft = :area_sqft,
            status = :status,
            vr_link = :vr_link,
            model_3d_url = :model_3d_url,
            image_url = :image_url,
            assigned_agent_id = :assigned_agent_id,
            is_premium_listing = :is_premium_listing
        WHERE id = :id
    ");

    $stmt->execute([
        ':title'             => $_POST['title'] ?? '',
        ':description'       => $_POST['description'] ?? '',
        ':location'          => $_POST['location'] ?? '',
        ':price'             => $_POST['price'] ?? 0,
        ':property_type_id'  => $property_type_id,
        ':listing_type_id'   => $listing_type_id,
        ':bedrooms'          => $_POST['bedrooms'] ?? 0,
        ':bathrooms'         => $_POST['bathrooms'] ?? 0,
        ':area_sqft'         => $_POST['area_sqft'] ?? 0,
        ':status'            => $_POST['status'] ?? 'Available',
        ':vr_link'           => $_POST['vr_link'] ?? null,
        ':model_3d_url'      => $modelPath,
        ':image_url'         => $mainImage,
        ':assigned_agent_id' => $assigned_agent_id,
        ':is_premium_listing'=> $is_premium_listing,
        ':id'                => $propertyId
    ]);

    $amenitiesProvided = false;
    $amenityIds = getAmenityIdsFromRequest($amenitiesProvided);
    if ($amenitiesProvided) {
        syncPropertyAmenities($pdo, $propertyId, $amenityIds);
    }

    // 11) 🆕 Save NEW documents (optional)
    if (
        isset($_FILES['documents']) && isset($_FILES['documents']['name']) &&
        is_array($_FILES['documents']['name']) &&
        isset($_POST['document_keys']) && isset($_POST['document_labels'])
    ) {
        $docKeys   = $_POST['document_keys'];
        $docLabels = $_POST['document_labels'];

        $insertDoc = $pdo->prepare("
            INSERT INTO property_documents 
            (property_id, document_key, document_label, file_url, uploaded_by)
            VALUES (:property_id, :document_key, :document_label, :file_url, 'owner')
        ");

        foreach ($_FILES['documents']['name'] as $index => $name) {
            if ($_FILES['documents']['error'][$index] !== UPLOAD_ERR_OK) {
                continue;
            }

            $file = [
                'name' => $_FILES['documents']['name'][$index] ?? '',
                'type' => $_FILES['documents']['type'][$index] ?? '',
                'tmp_name' => $_FILES['documents']['tmp_name'][$index] ?? '',
                'error' => $_FILES['documents']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['documents']['size'][$index] ?? 0,
            ];
            $uploadInfo = validateUpload(
                $file,
                ['pdf', 'doc', 'docx'],
                ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                10 * 1024 * 1024
            );

            $originalName = basename($name);
            $safeName = time() . '_' . $index . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $originalName);
            $safeName = pathinfo($safeName, PATHINFO_FILENAME) . '.' . $uploadInfo['ext'];

            if (!move_uploaded_file($_FILES['documents']['tmp_name'][$index], $docsDir . $safeName)) {
                continue;
            }

            $fileUrl = "properties/uploads/documents/" . $safeName;
            $documentKey   = $docKeys[$index]   ?? 'other';
            $documentLabel = $docLabels[$index] ?? $originalName;

            $insertDoc->execute([
                ':property_id'    => $propertyId,
                ':document_key'   => $documentKey,
                ':document_label' => $documentLabel,
                ':file_url'       => $fileUrl,
            ]);
        }
    }

    // 12) EXTRA GALLERY IMAGES
    if (!empty($_FILES['gallery']['name'])) {
        $galleryInsert = $pdo->prepare("
            INSERT INTO property_gallery (property_id, image_url) 
            VALUES (?, ?)
        ");

        for ($i = 1; $i < count($_FILES['gallery']['name']); $i++) {
            if ($_FILES['gallery']['error'][$i] === 0) {
                $uploadInfo = validateUpload(
                    [
                        'name' => $_FILES['gallery']['name'][$i],
                        'type' => $_FILES['gallery']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                        'error' => $_FILES['gallery']['error'][$i],
                        'size' => $_FILES['gallery']['size'][$i] ?? 0,
                    ],
                    ['jpg', 'jpeg', 'png', 'webp'],
                    ['image/jpeg', 'image/png', 'image/webp'],
                    10 * 1024 * 1024
                );
                $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $uploadInfo['ext'];
                move_uploaded_file($_FILES['gallery']['tmp_name'][$i], $galleryDir . $fileName);

                $imgPath = "properties/uploads/gallery/" . $fileName;
                $galleryInsert->execute([$propertyId, $imgPath]);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Property updated successfully',
        'is_premium_listing' => $is_premium_listing
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
