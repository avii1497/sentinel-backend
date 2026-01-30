<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json; charset=UTF-8");

try {
    $pdo = (new Database())->getPdo();
    $stmt = $pdo->prepare("
        SELECT id, bank_name, product_name, annual_rate, min_term_months, max_term_months,
               processing_fee, currency
        FROM loan_products
        WHERE is_active = 1
        ORDER BY bank_name ASC, product_name ASC
    ");
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
