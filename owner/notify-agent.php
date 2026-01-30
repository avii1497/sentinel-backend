<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/mailer.php';

header("Content-Type: application/json");
if (session_status() === PHP_SESSION_NONE) session_start();

try {
  requireLogin();
  requireRole('owner');
  requireCsrf();

  $owner_user_id = (int)$_SESSION['user_id'];
  $input = json_decode(file_get_contents("php://input"), true);
  $reservation_id = (int)($input['reservation_id'] ?? 0);

  if (!$reservation_id) throw new Exception("Missing reservation_id");

  $pdo = (new Database())->getPdo();

  // Owner ID
  $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
  $stmt->execute([$owner_user_id]);
  $owner = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$owner) throw new Exception("Owner record not found");

  $owner_id = (int)$owner['id'];

  // Load reservation + agent + customer
  $stmt = $pdo->prepare("
    SELECT
      pr.id AS reservation_id,
      p.id AS property_id,
      p.title AS property_title,

      au.email AS agent_email,
      CONCAT(au.first_name, ' ', au.last_name) AS agent_name,

      cu.email AS customer_email,
      CONCAT(cu.first_name, ' ', cu.last_name) AS customer_name

    FROM property_reservations pr
    JOIN properties p ON p.id = pr.property_id
    JOIN owners ow ON ow.id = p.owner_id

    JOIN agents ag ON ag.id = p.assigned_agent_id
    JOIN users au ON au.id = ag.user_id

    JOIN users cu ON cu.id = pr.customer_id

    WHERE pr.id = ?
      AND ow.id = ?
      AND (pr.reservation_status = 'PAID_CONFIRMED' OR pr.payment_status = 'paid')
    LIMIT 1
  ");

  $stmt->execute([$reservation_id, $owner_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    throw new Exception("Paid reservation not found or agent not assigned.");
  }

  // Send email
  $subject = "Reservation Paid  Please Schedule Meeting";

  $html = "
    <h2>Reservation Paid ✅</h2>
    <p>Hello <b>{$row['agent_name']}</b>,</p>

    <p>The client has completed payment.</p>

    <ul>
      <li><b>Property:</b> {$row['property_title']} (#{$row['property_id']})</li>
      <li><b>Reservation:</b> #{$row['reservation_id']}</li>
      <li><b>Client:</b> {$row['customer_name']} ({$row['customer_email']})</li>
    </ul>

    <p>Please log in to Sentinel to schedule the meeting.</p>
    <p>— Sentinel Platform</p>
  ";

  // Update coordination status + owner acknowledgement
  $pdo->prepare("
    UPDATE property_reservations
    SET coordination_status = 'agent_notified',
        owner_ack_at = NOW()
    WHERE id = ?
  ")->execute([$reservation_id]);

  $mailOk = false;
  $send = sendMail($row['agent_email'], $subject, $html);
  if (!empty($send['success'])) {
    $mailOk = true;
  } else {
    error_log("Owner notify-agent mail failed");
  }

  echo json_encode([
    "success" => true,
    "email_sent" => $mailOk
  ]);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
