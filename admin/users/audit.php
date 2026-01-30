<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

try {
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 100);
    $offset = (int)($_GET['offset'] ?? 0);

    if ($limit < 1) $limit = 1;
    if ($limit > 500) $limit = 500;
    if ($offset < 0) $offset = 0;

    $db = new Database();
    $pdo = $db->getPdo();

    $loginWhere = [];
    $loginParams = [];
    if ($from !== '') {
        $loginWhere[] = "ula.logged_in_at >= :from";
        $loginParams[':from'] = $from;
    }
    if ($to !== '') {
        $loginWhere[] = "ula.logged_in_at <= :to";
        $loginParams[':to'] = $to;
    }
    $loginWhereSql = $loginWhere ? 'WHERE ' . implode(' AND ', $loginWhere) : '';

    $eventsSql = "
        SELECT
            ula.id,
            ula.user_id,
            u.email,
            u.role,
            ula.ip_address,
            ula.user_agent,
            ula.logged_in_at
        FROM user_login_audit ula
        JOIN users u ON u.id = ula.user_id
        $loginWhereSql
        ORDER BY ula.logged_in_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $eventsStmt = $pdo->prepare($eventsSql);
    foreach ($loginParams as $key => $value) {
        $eventsStmt->bindValue($key, $value);
    }
    $eventsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $eventsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $eventsStmt->execute();
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $summarySql = "
        SELECT
            DATE(ula.logged_in_at) AS login_date,
            ula.ip_address,
            COUNT(*) AS login_count,
            COUNT(DISTINCT ula.user_id) AS user_count
        FROM user_login_audit ula
        $loginWhereSql
        GROUP BY DATE(ula.logged_in_at), ula.ip_address
        ORDER BY login_date DESC, login_count DESC
    ";
    $summaryStmt = $pdo->prepare($summarySql);
    foreach ($loginParams as $key => $value) {
        $summaryStmt->bindValue($key, $value);
    }
    $summaryStmt->execute();
    $summary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalLoginsSql = "SELECT COUNT(*) FROM user_login_audit ula $loginWhereSql";
    $totalLoginsStmt = $pdo->prepare($totalLoginsSql);
    foreach ($loginParams as $key => $value) {
        $totalLoginsStmt->bindValue($key, $value);
    }
    $totalLoginsStmt->execute();
    $totalLogins = (int)$totalLoginsStmt->fetchColumn();

    $createdWhere = [];
    $createdParams = [];
    if ($from !== '') {
        $createdWhere[] = "created_at >= :from_created";
        $createdParams[':from_created'] = $from;
    }
    if ($to !== '') {
        $createdWhere[] = "created_at <= :to_created";
        $createdParams[':to_created'] = $to;
    }
    $createdWhereSql = $createdWhere ? 'WHERE ' . implode(' AND ', $createdWhere) : '';

    $createdSql = "
        SELECT DATE(created_at) AS created_date, COUNT(*) AS user_count
        FROM users
        $createdWhereSql
        GROUP BY DATE(created_at)
        ORDER BY created_date DESC
    ";
    $createdStmt = $pdo->prepare($createdSql);
    foreach ($createdParams as $key => $value) {
        $createdStmt->bindValue($key, $value);
    }
    $createdStmt->execute();
    $createdSummary = $createdStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    echo json_encode([
        'success' => true,
        'filters' => [
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
            'limit' => $limit,
            'offset' => $offset,
        ],
        'totals' => [
            'users' => $totalUsers,
            'logins' => $totalLogins,
        ],
        'login_summary' => $summary,
        'login_events' => $events,
        'user_created_summary' => $createdSummary,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
