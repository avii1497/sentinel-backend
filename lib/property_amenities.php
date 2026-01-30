<?php

function normalizeAmenityIds($raw): array {
    $ids = [];

    if (is_array($raw)) {
        foreach ($raw as $value) {
            if (is_array($value)) continue;
            $id = (int)trim((string)$value);
            if ($id > 0) $ids[] = $id;
        }
    } elseif (is_string($raw)) {
        foreach (explode(',', $raw) as $part) {
            $id = (int)trim($part);
            if ($id > 0) $ids[] = $id;
        }
    } elseif (is_numeric($raw)) {
        $id = (int)$raw;
        if ($id > 0) $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

function getAmenityIdsFromRequest(bool &$provided = false): array {
    $provided = false;
    $raw = null;

    if (array_key_exists('amenity_ids', $_POST)) {
        $raw = $_POST['amenity_ids'];
        $provided = true;
    } elseif (array_key_exists('amenity_ids[]', $_POST)) {
        $raw = $_POST['amenity_ids[]'];
        $provided = true;
    } else {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $body = json_decode(file_get_contents('php://input'), true);
            if (is_array($body) && array_key_exists('amenity_ids', $body)) {
                $raw = $body['amenity_ids'];
                $provided = true;
            }
        }
    }

    return normalizeAmenityIds($raw);
}

function syncPropertyAmenities(PDO $pdo, int $propertyId, array $amenityIds): array {
    $pdo->prepare("DELETE FROM property_amenities WHERE property_id = ?")
        ->execute([$propertyId]);

    if (empty($amenityIds)) return [];

    $placeholders = implode(',', array_fill(0, count($amenityIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM amenities WHERE id IN ($placeholders)");
    $stmt->execute($amenityIds);
    $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($validIds)) return [];

    $insert = $pdo->prepare(
        "INSERT IGNORE INTO property_amenities (property_id, amenity_id) VALUES (?, ?)"
    );
    foreach ($validIds as $id) {
        $insert->execute([$propertyId, $id]);
    }

    return $validIds;
}

?>
