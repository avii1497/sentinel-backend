<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../Database.php';

$db = new Database();
$pdo = $db->getPdo();

try {
    $pdo->beginTransaction();

    // Set ongoing for paid, accepted rentals in range.
    $ongoing = $pdo->prepare("
        UPDATE rental_bookings_backup
        SET status = 'ongoing'
        WHERE status = 'accepted'
          AND payment_status = 'paid'
          AND checkin <= CURDATE()
          AND checkout >= CURDATE()
    ");
    $ongoing->execute();
    $ongoingCount = $ongoing->rowCount();

    // Set completed for paid rentals that have ended.
    $completed = $pdo->prepare("
        UPDATE rental_bookings_backup
        SET status = 'completed'
        WHERE status IN ('accepted','ongoing')
          AND payment_status = 'paid'
          AND checkout < CURDATE()
    ");
    $completed->execute();
    $completedCount = $completed->rowCount();

    $pdo->commit();
    echo "Rental lifecycle updated: ongoing={$ongoingCount}, completed={$completedCount}";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("CRON ERROR: " . $e->getMessage());
}
