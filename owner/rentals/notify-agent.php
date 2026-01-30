<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/owner_guard.php';
require_once __DIR__ . '/../../mailer.php'; // use your existing mailer

header('Content-Type: application/json');

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $msg
    ]);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Invalid request method', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $bookingId = (int)($input['booking_id'] ?? 0);

    if (!$bookingId) {
        json_error('Missing booking ID');
    }

    // 🔎 Fetch booking + property + agent
    $stmt = $pdo->prepare("
        SELECT
            rb.id,
            rb.checkin,
            rb.checkout,
            rb.payment_status,
            rb.status,
            rb.agent_notified_at,

            p.title AS property_title,
            p.owner_id,
            a.email AS agent_email,
            a.first_name AS agent_first_name

        FROM rental_bookings_backup rb
        INNER JOIN properties p ON p.id = rb.property_id
        INNER JOIN agents a ON a.id = p.assigned_agent_id

        WHERE
            rb.id = ?
            AND p.owner_id = ?
            AND rb.payment_status = 'paid'
            AND rb.status = 'accepted'
            AND rb.checkin > CURDATE()
        LIMIT 1
    ");

    $stmt->execute([$bookingId, $OWNER_ID]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        json_error('Booking not eligible for notification');
    }

    if (!empty($booking['agent_notified_at'])) {
        json_error('Agent already notified');
    }

    // 📧 Send email
    $subject = "Upcoming Rental Assignment – {$booking['property_title']}";

    $body = "
        <p>Hello {$booking['agent_first_name']},</p>

        <p>You have been assigned to an <strong>upcoming rental</strong>.</p>

        <p>
            <strong>Property:</strong> {$booking['property_title']}<br>
            <strong>Check-in:</strong> {$booking['checkin']}<br>
            <strong>Check-out:</strong> {$booking['checkout']}
        </p>

        <p>Please prepare accordingly.</p>

        <p>— Sentinel</p>
    ";

    $result = sendMail($booking['agent_email'], $subject, $body);

    if (empty($result['success'])) {
        json_error('Failed to send email');
    }

    // ✅ Mark as notified
    $update = $pdo->prepare("
        UPDATE rental_bookings_backup
        SET agent_notified_at = NOW()
        WHERE id = ?
    ");
    $update->execute([$bookingId]);

    echo json_encode([
        'success' => true
    ]);
    exit;

} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
