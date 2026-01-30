<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/mailer.php';

header("Content-Type: application/json; charset=UTF-8");

if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('agent');
requireCsrf();

function isValidDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function isValidTime($time) {
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time);
}

try {
    $agent_id   = (int)($_SESSION['agent_id'] ?? 0);
    $booking_id = $_POST['booking_id'] ?? null;

    $meeting_date = $_POST['meeting_date'] ?? null;
    $start_time   = $_POST['start_time'] ?? null;
    $end_time     = $_POST['end_time'] ?? null;

    $title       = trim($_POST['title'] ?? 'Rental Visit');
    $description = trim($_POST['description'] ?? '');

    if (!$booking_id || !$meeting_date || !$start_time || !$end_time) {
        throw new Exception("Missing required fields.");
    }

    if (!isValidDate($meeting_date) || !isValidTime($start_time) || !isValidTime($end_time)) {
        throw new Exception("Invalid date or time format.");
    }

    if (strlen($start_time) === 5) $start_time .= ":00";
    if (strlen($end_time) === 5)   $end_time   .= ":00";

    $pdo = (new Database())->getPdo();
    if ($agent_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $agent_id = (int)$stmt->fetchColumn();
    }
    if ($agent_id <= 0) throw new Exception("Agent not found.");

    $pdo->beginTransaction();

    // Lock booking
    $stmt = $pdo->prepare("
        SELECT
            rb.*,
            p.owner_id,
            p.assigned_agent_id,
            p.title AS property_title,
            CONCAT(u.first_name,' ',u.last_name) AS client_name,
            u.email AS client_email
        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        INNER JOIN customers c ON c.id = rb.tenant_id
        INNER JOIN users u ON u.id = c.user_id
        WHERE rb.id = ?
        FOR UPDATE
    ");
    $stmt->execute([(int)$booking_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception("Rental booking not found.");
    if ((int)$row['assigned_agent_id'] !== $agent_id)
        throw new Exception("Not your booking.");

    if ($row['payment_status'] !== 'paid' || $row['status'] !== 'accepted')
        throw new Exception("Booking not eligible.");

    // Prevent duplicate meeting
    $check = $pdo->prepare("SELECT id FROM meetings WHERE booking_id = ? LIMIT 1");
    $check->execute([(int)$booking_id]);
    if ($check->fetch()) throw new Exception("Meeting already scheduled.");

    // Insert meeting
    $stmt = $pdo->prepare("
        INSERT INTO meetings (
            owner_id, agent_id, booking_id, property_id,
            title, description,
            meeting_date, start_time, end_time,
            status, created_by, created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'agent', NOW(), NOW())
    ");
    $stmt->execute([
        (int)$row['owner_id'],
        $agent_id,
        (int)$booking_id,
        (int)$row['property_id'],
        $title,
        $description ?: null,
        $meeting_date,
        $start_time,
        $end_time
    ]);

    $pdo->commit();

    // Email tenant (same style as reservation)
    $html = "
      <h2>Your rental visit has been scheduled</h2>
      <p>Hello <b>{$row['client_name']}</b>,</p>
      <p>Your visit for <b>{$row['property_title']}</b> has been scheduled.</p>
      <p><b>Date:</b> {$meeting_date}<br>
         <b>Time:</b> {$start_time} - {$end_time}</p>
      <p>Sentinel Team</p>
    ";

    sendMail($row['client_email'], "Rental Visit Scheduled", $html);

    echo json_encode(["success" => true]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
