<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/mailer.php';

header("Content-Type: application/json; charset=UTF-8");

$owner_id   = $_POST['owner_id'] ?? null;
$meeting_id = $_POST['meeting_id'] ?? null;
$new_date   = $_POST['meeting_date'] ?? null;
$new_start  = $_POST['start_time'] ?? null;
$new_end    = $_POST['end_time'] ?? null;
$note       = trim($_POST['note'] ?? "");

if (!$owner_id || !$meeting_id || !$new_date || !$new_start || !$new_end) {
    echo json_encode(["success" => false, "error" => "Missing required fields"]);
    exit;
}

if (!is_numeric($owner_id) || !is_numeric($meeting_id)) {
    echo json_encode(["success" => false, "error" => "Invalid IDs"]);
    exit;
}

try {
    $pdo = (new Database())->getPdo();
    $pdo->beginTransaction();

    // 🔒 Load meeting + client info
    $sql = "
        SELECT
            m.*,
            p.title AS property_title,
            CONCAT(u.first_name, ' ', u.last_name) AS client_name,
            u.email AS client_email
        FROM meetings m
        INNER JOIN properties p ON p.id = m.property_id
        INNER JOIN property_reservations r ON r.id = m.reservation_id
        INNER JOIN customers c ON c.id = r.customer_id
        INNER JOIN users u ON u.id = c.user_id
        WHERE m.id = ? AND m.owner_id = ?
        LIMIT 1
        FOR UPDATE
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$meeting_id, $owner_id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$meeting) {
        throw new Exception("Meeting not found for this owner.");
    }

    // Build description append
    $appendText = "Owner requested reschedule to {$new_date} {$new_start}-{$new_end}";
    if ($note !== "") {
        $appendText .= " (Note: {$note})";
    }

    // Update meeting
    $upd = $pdo->prepare("
        UPDATE meetings
        SET
            meeting_date = ?,
            start_time   = ?,
            end_time     = ?,
            status       = 'pending',
            description  = CONCAT(IFNULL(description,''), '\n\n', ?),
            updated_at   = NOW()
        WHERE id = ?
    ");

    $upd->execute([$new_date, $new_start, $new_end, $appendText, $meeting_id]);

    // 📧 Email client
    $prettyDate = date("l, d M Y", strtotime($new_date));
    $prettyTime = "$new_start – $new_end";

   $html = "
  <h2>Property Visit Rescheduled 🔁</h2>
  <p>Hello <b>{$meeting['client_name']}</b>,</p>

  <p>
    The owner has rescheduled your property visit.
    Please note that the date and time below are now the official schedule.
  </p>

  <ul>
    <li><b>Property:</b> {$meeting['property_title']}</li>
    <li><b>New Date & Time:</b> {$prettyDate} • {$prettyTime}</li>
  </ul>

  <p>
    If you have any questions, feel free to reply to this email.
  </p>

  <p>— Sentinel Team</p>
";

    $mail = sendMail(
        $meeting['client_email'],
        "Property Visit Rescheduled 🔁",
        $html
    );

    if (!$mail['success']) {
        throw new Exception("Email failed to send.");
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "meeting_date" => $new_date,
        "start_time" => $new_start,
        "end_time" => $new_end,
        "status" => "pending"
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
