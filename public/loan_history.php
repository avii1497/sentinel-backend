<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy loan history endpoint.
// Safe to remove after: 2026-06-30 (if no loan history UI is shipped).
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

header("Content-Type: application/json; charset=UTF-8");

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        throw new RuntimeException("Unauthorized");
    }

    $pdo = (new Database())->getPdo();
    $stmt = $pdo->prepare("
        SELECT lc.id, lc.property_id, lc.loan_product_id, lc.price, lc.down_payment, lc.interest_rate,
               lc.term_months, lc.principal, lc.monthly_payment, lc.total_payment, lc.total_interest,
               lc.created_at,
               lp.bank_name, lp.product_name, lp.annual_rate, lp.currency
        FROM loan_calculations lc
        LEFT JOIN loan_products lp ON lc.loan_product_id = lp.id
        WHERE lc.user_id = ?
        ORDER BY lc.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([(int) $_SESSION['user_id']]);

    echo json_encode([
        "success" => true,
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
