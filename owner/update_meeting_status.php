<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/validation.php';

header("Content-Type: application/json; charset=UTF-8");

requireLogin();
requireRole('owner');
requireCsrf();

$meeting_id = v_int($_POST['meeting_id'] ?? null, 'meeting id');
$status     = v_enum($_POST['status'] ?? null, 'status', ['pending','accepted','declined','cancelled','completed']);

try {
    $pdo = (new Database())->getPdo();

    $owner_id = (int)($_SESSION['owner_id'] ?? 0);
    if ($owner_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM owners WHERE user_id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $owner_id = (int)$stmt->fetchColumn();
    }
    if ($owner_id <= 0) {
        throw new Exception("Owner not found");
    }

    // Ensure meeting belongs to this owner
    $check = $pdo->prepare("SELECT * FROM meetings WHERE id = ? AND owner_id = ?");
    $check->execute([$meeting_id, $owner_id]);
    $meeting = $check->fetch(PDO::FETCH_ASSOC);

    if (!$meeting) {
        echo json_encode([
            "success" => false,
            "error"   => "Meeting not found for this owner"
        ]);
        exit;
    }

    $upd = $pdo->prepare("
        UPDATE meetings
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $upd->execute([$status, $meeting_id]);


// 📧 Send confirmation email ONLY when owner accepts a reservation meeting
if (
    $status === 'accepted'
    && !empty($meeting['reservation_id'])
) {
    $info = $pdo->prepare("
        SELECT
            m.meeting_date,
            m.start_time,
            m.end_time,
            p.title AS property_title,
            CONCAT(u.first_name, ' ', u.last_name) AS client_name,
            u.email AS client_email
        FROM meetings m
        INNER JOIN property_reservations r ON r.id = m.reservation_id
        INNER JOIN customers c ON c.id = r.customer_id
        INNER JOIN users u ON u.id = c.user_id
        LEFT JOIN properties p ON p.id = m.property_id
        WHERE m.id = ?
        LIMIT 1
    ");
    $info->execute([$meeting_id]);
    $row = $info->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $prettyDate = date("l, d M Y", strtotime($row['meeting_date']));
        $prettyTime =
            substr($row['start_time'], 0, 5) .
            " – " .
            substr($row['end_time'], 0, 5);

        $html = "
            <h2>Meeting Confirmed ✅</h2>
            <p>Hello <b>{$row['client_name']}</b>,</p>

            <p>Your property visit has been <b>confirmed</b> by the owner.</p>

            <ul>
                <li><b>Property:</b> {$row['property_title']}</li>
                <li><b>Date & Time:</b> {$prettyDate} • {$prettyTime}</li>
            </ul>

            <p>We look forward to welcoming you.</p>
            <p>— Sentinel Team</p>
        ";

        sendMail(
            $row['client_email'],
            "Your Property Visit Is Confirmed 🏡",
            $html
        );
    }
}



    echo json_encode([
        "success" => true,
        "message" => "Status updated",
        "status"  => $status
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
