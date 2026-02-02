<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';

header("Content-Type: application/json; charset=UTF-8");

function parse_number($value) {
    if ($value === null) {
        return null;
    }
    if (is_string($value)) {
        // Remove commas, currency symbols, and percent sign.
        $clean = preg_replace('/[^0-9.\-]/', '', $value);
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        return floatval($clean);
    }
    if (is_numeric($value)) {
        return floatval($value);
    }
    return null;
}

try {
$input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        throw new RuntimeException("Invalid JSON payload.");
    }
    $input = sanitize_array($input);

    $price = v_float(parse_number($input['price'] ?? null), 'price', 0.01, 1000000000);
    $downPayment = v_float(parse_number($input['down_payment'] ?? 0), 'down payment', 0, 1000000000, false);
    $interestRate = v_float(parse_number($input['interest_rate'] ?? null), 'interest rate', 0, 100, true); // annual %
    $termYears = parse_number($input['loan_term_years'] ?? null);
    $termMonths = parse_number($input['loan_term_months'] ?? null);
    $propertyId = v_int($input['property_id'] ?? null, 'property id', 1, 2147483647, false);
    $loanProductId = v_int($input['loan_product_id'] ?? null, 'loan product id', 1, 2147483647, false);
    $save = v_bool($input['save'] ?? 0, 'save', false) ?? 0;

    if ($termMonths === null) {
        if ($termYears === null) {
            throw new RuntimeException("Loan term is required.");
        }
        $termYears = v_int($termYears, 'loan term years', 1, 100);
        $termMonths = $termYears * 12;
    } else {
        $termMonths = v_int($termMonths, 'loan term months', 1, 1200);
    }

    $principal = $price - ($downPayment ?? 0);
    if ($principal <= 0) {
        throw new RuntimeException("Down payment must be less than price.");
    }

    $monthlyRate = ($interestRate / 100) / 12;
    if ($monthlyRate == 0) {
        $monthlyPayment = $principal / $termMonths;
    } else {
        $factor = pow(1 + $monthlyRate, $termMonths);
        $monthlyPayment = $principal * ($monthlyRate * $factor) / ($factor - 1);
    }

    $totalPayment = $monthlyPayment * $termMonths;
    $totalInterest = $totalPayment - $principal;

    $saved = false;
    if ($save) {
        if (empty($_SESSION['user_id'])) {
            throw new RuntimeException("Authentication required to save history.");
        }

        $pdo = (new Database())->getPdo();
        $stmt = $pdo->prepare("
            INSERT INTO loan_calculations
                (user_id, property_id, loan_product_id, price, down_payment, interest_rate, term_months,
                 principal, monthly_payment, total_payment, total_interest)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int) $_SESSION['user_id'],
            $propertyId ?: null,
            $loanProductId ?: null,
            $price,
            $downPayment ?? 0,
            $interestRate,
            (int) $termMonths,
            $principal,
            $monthlyPayment,
            $totalPayment,
            $totalInterest
        ]);
        $saved = true;
    }

    echo json_encode([
        "success" => true,
        "data" => [
            "principal" => round($principal, 2),
            "monthly_payment" => round($monthlyPayment, 2),
            "total_payment" => round($totalPayment, 2),
            "total_interest" => round($totalInterest, 2),
            "term_months" => (int) round($termMonths)
        ],
        "saved" => $saved
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
