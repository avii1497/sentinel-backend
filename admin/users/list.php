<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

try {
    $statusParam = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
    $search = trim((string)($_GET['search'] ?? ''));
    $statusFilter = null;
    if ($statusParam !== '') {
        $statusVal = (int)$statusParam;
        if (!in_array($statusVal, [0, 1], true)) {
            throw new RuntimeException('Invalid status. Use 0 or 1.');
        }
        $statusFilter = $statusVal;
    }

    $limit = (int)($_GET['limit'] ?? 200);
    if ($limit < 1) $limit = 1;
    if ($limit > 500) $limit = 500;

    $db = new Database();
    $pdo = $db->getPdo();

    $whereParts = [];
    $params = [];
    if ($statusFilter !== null) {
        $whereParts[] = 'status = :status';
        $params[':status'] = $statusFilter;
    }
    if ($search !== '') {
        $whereParts[] = "(CONCAT_WS(' ', first_name, last_name) LIKE :search OR email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

    $sql = "
        SELECT id, first_name, last_name, email, role, status, created_at
        FROM users
        $where
        ORDER BY created_at DESC
        LIMIT :limit
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $type = ($key === ':status') ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $type);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = array_map(static function (array $row): array {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        return [
            'id' => (int)$row['id'],
            'name' => $name !== '' ? $name : null,
            'email' => $row['email'],
            'role' => $row['role'],
            'status' => (int)$row['status'],
            'created_at' => $row['created_at'],
        ];
    }, $users);

    echo json_encode([
        'success' => true,
        'count' => count($result),
        'users' => $result,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
