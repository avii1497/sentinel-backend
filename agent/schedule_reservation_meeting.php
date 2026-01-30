<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/mailer.php';

header("Content-Type: application/json; charset=UTF-8");

function isValidDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function isValidTime($time) {
    // Accept HH:MM or HH:MM:SS
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time);
}

try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    requireLogin();
    requireRole('agent');
    requireCsrf();

    $agent_id       = (int)($_SESSION['agent_id'] ?? 0);
    $reservation_id = $_POST['reservation_id'] ?? null;

    $meeting_date = $_POST['meeting_date'] ?? null; // YYYY-MM-DD
    $start_time   = $_POST['start_time'] ?? null;   // HH:MM
    $end_time     = $_POST['end_time'] ?? null;     // HH:MM

    $title       = trim($_POST['title'] ?? 'Property Visit');
    $description = trim($_POST['description'] ?? '');

    if (!$reservation_id || !$meeting_date || !$start_time || !$end_time) {
        throw new Exception("Missing required fields.");
    }
    if (!is_numeric($reservation_id)) {
        throw new Exception("Invalid IDs.");
    }

    // Validate formats
    if (!isValidDate($meeting_date)) {
        throw new Exception("Invalid meeting_date format. Use YYYY-MM-DD.");
    }
    if (!isValidTime($start_time) || !isValidTime($end_time)) {
        throw new Exception("Invalid time format. Use HH:MM.");
    }

    // Normalize times to HH:MM:SS for safe comparisons + DB insert
    if (strlen($start_time) === 5) $start_time .= ":00";
    if (strlen($end_time) === 5) $end_time .= ":00";

    // Enforce 10-day rule (timestamp-safe, no string compare issues)
    $now = new DateTime("now");
    $meetingStart = new DateTime("$meeting_date $start_time");
    $meetingEnd   = new DateTime("$meeting_date $end_time");

    if ($meetingEnd <= $meetingStart) {
        throw new Exception("End time must be after start time.");
    }

    // Meeting must be today..+10 days
    $max = (clone $now)->modify("+10 days")->setTime(23, 59, 59);
    $min = (clone $now)->setTime(0, 0, 0);

    if ($meetingStart < $min || $meetingStart > $max) {
        throw new Exception("Meeting must be scheduled within 10 days.");
    }

    $pdo = (new Database())->getPdo();
    if ($agent_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $agent_id = (int)$stmt->fetchColumn();
    }
    if ($agent_id <= 0) {
        throw new Exception("Agent not found.");
    }
    $pdo->beginTransaction();

    /**
     * IMPORTANT:
     * This endpoint assumes meetings has reservation_id column.
     * If not, you must add it:
     * ALTER TABLE meetings ADD reservation_id INT NULL AFTER agent_id;
     * CREATE INDEX idx_meetings_reservation ON meetings(reservation_id);
     */

    // Lock reservation row to prevent double scheduling (race condition)
    $sql = "
        SELECT
            r.id AS reservation_id,
            r.payment_status,
            r.reservation_status,
            r.ready_for_final,
            r.coordination_status,

            p.id AS property_id,
            p.title AS property_title,
            p.owner_id,
            p.assigned_agent_id,

            CONCAT(u.first_name, ' ', u.last_name) AS client_name,
            u.email AS client_email,

            CONCAT(au.first_name, ' ', au.last_name) AS agent_name,
            au.email AS agent_email

        FROM property_reservations r
        INNER JOIN properties p ON p.id = r.property_id
        INNER JOIN users u ON u.id = r.customer_id

        INNER JOIN agents a ON a.id = p.assigned_agent_id
        INNER JOIN users au ON au.id = a.user_id

        WHERE r.id = ?
        LIMIT 1
        FOR UPDATE
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$reservation_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Reservation not found.");
    }

    if ((int)$row['assigned_agent_id'] !== (int)$agent_id) {
        throw new Exception("Reservation not assigned to this agent.");
    }

    $isPaid = ($row['payment_status'] === 'paid' || $row['reservation_status'] === 'PAID_CONFIRMED');
    if (!$isPaid || (int)$row['ready_for_final'] !== 1) {
        throw new Exception("Reservation is not ready for meeting scheduling.");
    }

    if ($row['coordination_status'] === 'scheduled') {
        throw new Exception("Meeting already scheduled for this reservation.");
    }

    // Extra safety: ensure no existing meeting for this reservation
    $checkMeeting = $pdo->prepare("SELECT id FROM meetings WHERE reservation_id = ? LIMIT 1");
    $checkMeeting->execute([(int)$reservation_id]);
    if ($checkMeeting->fetch()) {
        throw new Exception("Meeting already exists for this reservation.");
    }

    // Prevent overlaps for owner or agent
    $overlapStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM meetings
        WHERE meeting_date = ?
          AND status IN ('pending','accepted')
          AND (agent_id = ? OR owner_id = ?)
          AND start_time < ?
          AND end_time > ?
    ");
    $overlapStmt->execute([
        $meeting_date,
        (int)$agent_id,
        (int)$row['owner_id'],
        $end_time,
        $start_time
    ]);
    if ((int)$overlapStmt->fetchColumn() > 0) {
        throw new Exception("Meeting time overlaps with another booking.");
    }

    // Decide meeting status when agent schedules:
    // Recommended: pending (owner can view/read-only, agent can manage).
    // If you want auto-accepted: change to 'accepted'
    $meetingStatus = "pending";

    // Insert meeting
    $ins = $pdo->prepare("
        INSERT INTO meetings (
            owner_id, agent_id, reservation_id, property_id,
            title, description,
            meeting_date, start_time, end_time,
            status, created_by, created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'agent', NOW(), NOW())
    ");

    $ins->execute([
        (int)$row['owner_id'],
        (int)$agent_id,
        (int)$reservation_id,
        (int)$row['property_id'],
        $title ?: "Property Visit",
        $description !== "" ? $description : null,
        $meeting_date,
        $start_time,
        $end_time,
        $meetingStatus
    ]);

    $meeting_id = (int)$pdo->lastInsertId();
    // Update reservation coordination status
    $pdo->prepare("
        UPDATE property_reservations
        SET coordination_status = 'scheduled'
        WHERE id = ?
    ")->execute([(int)$reservation_id]);

    $pdo->commit();

    // Email client (system email).
    $prettyDate = (new DateTime($meeting_date))->format("l, d M Y");
    $prettyTime = (new DateTime($start_time))->format("H:i") . " - " . (new DateTime($end_time))->format("H:i");

    $clientName = htmlspecialchars($row['client_name'] ?? "Client");
    $propertyTitle = htmlspecialchars($row['property_title'] ?? "Property");
    $agentName = htmlspecialchars($row['agent_name'] ?? "Your Agent");

    $html = "
      <div style='font-family:Arial,sans-serif;line-height:1.5'>
        <h2 style='margin:0 0 8px'>Your payment has been acknowledged and your property visit is scheduled.</h2>
        <p style='margin:0 0 12px'>Hello <b>{$clientName}</b>,</p>

        <p style='margin:0 0 12px'>
          Your visit has been scheduled with our property agent <b>{$agentName}</b>.
        </p>

        <ul style='margin:0 0 12px;padding-left:18px'>
          <li><b>Property:</b> {$propertyTitle}</li>
          <li><b>Date:</b> {$prettyDate}</li>
          <li><b>Time:</b> {$prettyTime}</li>
        </ul>

        <p style='margin:0 0 12px'>
          If you have any questions, simply reply to this email and your agent will assist you.
        </p>

        <p style='margin:0'>Sentinel Team</p>
      </div>
    ";

    // Optional: if your sendMail doesn't support Reply-To, we can upgrade mailer.php later.
    // For now, use system email only.
    $emailSent = false;
    try {
        $mailResult = sendMail(
            $row['client_email'],
            "Your Property Visit Has Been Scheduled",
            $html
        );
        if (!empty($mailResult['success'])) {
            $emailSent = true;
        }
    } catch (Throwable $mailErr) {
        error_log("Meeting email failed: " . $mailErr->getMessage());
    }

    echo json_encode([
        "success" => true,
        "meeting_id" => $meeting_id,
        "email_sent" => $emailSent
    ]);


} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
