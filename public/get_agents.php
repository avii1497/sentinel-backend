<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
header("Content-Type: application/json");

try {
    $pdo = (new Database())->getPdo();

    $sql = "
        SELECT 
            a.*,
            CONCAT(u.first_name, ' ', u.last_name) AS full_name,
            u.email,
            u.phone,
            u.role,
            u.is_premium
        FROM agents a
        JOIN users u ON u.id = a.user_id
        ORDER BY a.years_of_experience DESC
    ";

    $stmt = $pdo->query($sql);
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $agents
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
