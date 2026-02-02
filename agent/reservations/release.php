<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../lib/validation.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    requireLogin();
    requireRole('agent');
    requireCsrf();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $input = sanitize_array($input);

    $propertyId = v_int($input['property_id'] ?? ($_POST['property_id'] ?? null), 'property id');

    $pdo = (new Database())->getPdo();

    $agentId = (int)($_SESSION['agent_id'] ?? 0);
    if ($agentId <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $agentId = (int)$stmt->fetchColumn();
    }
    if ($agentId <= 0) json_error('Unauthorized', 403);

    $stmt = $pdo->prepare("
        SELECT id, status, assigned_agent_id
        FROM properties
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$propertyId]);
    $prop = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prop) json_error('Property not found', 404);

    if ((int)$prop['assigned_agent_id'] !== $agentId) {
        json_error('Not authorized for this property', 403);
    }
    if ($prop['status'] === 'Sold') {
        json_error('Property already sold', 409);
    }

    $active = $pdo->prepare("
        SELECT id
        FROM property_reservations
        WHERE property_id = ?
          AND cancelled_at IS NULL
          AND (
            reservation_status = 'PAID_CONFIRMED'
            OR payment_status = 'paid'
          )
        LIMIT 1
    ");
    $active->execute([$propertyId]);
    if ($active->fetch()) {
        json_error('Active reservation exists for this property', 409);
    }

    $pdo->prepare("
        UPDATE properties
        SET status = 'Available',
            reserved_by_customer_id = NULL,
            reserved_until = NULL
        WHERE id = ?
    ")->execute([$propertyId]);

    echo json_encode(['success' => true, 'message' => 'Property released']);

} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
