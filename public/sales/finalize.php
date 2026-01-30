<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/mailer.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

try {
    requireLogin();
    requireRole('agent');
    requireCsrf();

    if ($_SESSION['role'] !== 'agent') {
        throw new Exception("Unauthorized");
    }

    $agent_id = (int)$_SESSION['agent_id'];
    $property_id = $_POST['property_id'] ?? null;

    if (!$property_id) throw new Exception("Missing property_id");

    $db = new Database();
    $pdo = $db->getPdo();

    // Load reservation + offer
    $stmt = $pdo->prepare("
        SELECT r.*, o.offer_price, o.customer_id
        FROM property_reservations r
        JOIN property_offers o ON o.id = r.offer_id
        JOIN properties p ON p.id = r.property_id
        WHERE r.property_id = ?
        AND p.assigned_agent_id = ?
        AND (r.reservation_status = 'PAID_CONFIRMED' OR r.payment_status = 'paid')
        AND r.ready_for_final = 1
        LIMIT 1
    ");
    $stmt->execute([$property_id, $agent_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) throw new Exception("Reservation not ready for final sale");

    $agentEmail = null;
    $agentName = null;
    $stmtAgent = $pdo->prepare("
        SELECT u.email, CONCAT(u.first_name, ' ', u.last_name) AS name
        FROM agents a
        INNER JOIN users u ON u.id = a.user_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmtAgent->execute([$agent_id]);
    $agentRow = $stmtAgent->fetch(PDO::FETCH_ASSOC);
    if ($agentRow) {
        $agentEmail = $agentRow['email'];
        $agentName = $agentRow['name'];
    }

    $pdo->beginTransaction();

    // Mark property SOLD
    $stmt = $pdo->prepare("
        UPDATE properties
        SET status = 'Sold',
            reserved_by_customer_id = NULL,
            reserved_until = NULL
        WHERE id = ?
    ");
    $stmt->execute([$property_id]);

    // Create sale record
    $invoice_number = "INV-" . time();

    $stmt = $pdo->prepare("
        INSERT INTO property_sales
        (property_id, reservation_id, buyer_id, agent_id, final_price, invoice_number)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $property_id,
        $res['id'],
        $res['customer_id'],
        $agent_id,
        $res['offer_price'],
        $invoice_number
    ]);

    $sale_id = $pdo->lastInsertId();

    // Mark agent contract as completed (if active)
    $pdo->prepare("
        UPDATE property_contracts
        SET status = 'Completed'
        WHERE property_id = ?
          AND agent_id = ?
          AND status = 'Active'
    ")->execute([$property_id, $agent_id]);

    // Close chat (optional but recommended)
    $stmt = $pdo->prepare("
        UPDATE property_chat
        SET status = 'resolved'
        WHERE property_id = ?
    ");
    $stmt->execute([$property_id]);

    $pdo->commit();

    // Email agent (non-blocking)
    if (!empty($agentEmail)) {
        $safeName = htmlspecialchars($agentName ?? 'Agent');
        $html = "
          <div style='font-family:Arial,sans-serif;line-height:1.5'>
            <h2 style='margin:0 0 8px'>Property Sale Completed</h2>
            <p style='margin:0 0 12px'>Hello <b>{$safeName}</b>,</p>
            <p style='margin:0 0 12px'>The sale has been finalized and your contract is now marked as completed.</p>
            <p style='margin:0'>Sentinel Team</p>
          </div>
        ";
        try {
            sendMail($agentEmail, 'Property Sale Completed', $html);
        } catch (Throwable $mailErr) {
            error_log('Sale completion email failed: ' . $mailErr->getMessage());
        }
    }

    echo json_encode([
        "success" => true,
        "message" => "Property sold successfully",
        "sale_id" => $sale_id,
        "invoice_number" => $invoice_number
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
