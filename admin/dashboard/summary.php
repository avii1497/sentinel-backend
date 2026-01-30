<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

try {
    $pdo = (new Database())->getPdo();

    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 1")->fetchColumn();
    $pendingApprovals = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 0")->fetchColumn();

    $roleCountsStmt = $pdo->query("
        SELECT role, COUNT(*) AS count
        FROM users
        GROUP BY role
    ");
    $roleCounts = [];
    foreach ($roleCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roleCounts[$row['role']] = (int)$row['count'];
    }

    $totalProperties = (int)$pdo->query("SELECT COUNT(*) FROM properties")->fetchColumn();
    $publishedProperties = (int)$pdo->query("SELECT COUNT(*) FROM properties WHERE is_published = 1")->fetchColumn();
    $premiumListings = (int)$pdo->query("SELECT COUNT(*) FROM properties WHERE is_premium_listing = 1")->fetchColumn();

    $totalOffers = (int)$pdo->query("SELECT COUNT(*) FROM property_offers")->fetchColumn();
    $pendingOffers = (int)$pdo->query("SELECT COUNT(*) FROM property_offers WHERE status = 'pending'")->fetchColumn();

    $totalReservations = (int)$pdo->query("SELECT COUNT(*) FROM property_reservations")->fetchColumn();
    $pendingReservations = (int)$pdo->query("
        SELECT COUNT(*)
        FROM property_reservations
        WHERE reservation_status = 'ACCEPTED_AWAITING_PAYMENT'
           OR payment_status = 'pending'
    ")->fetchColumn();

    $totalSales = (int)$pdo->query("SELECT COUNT(*) FROM property_sales")->fetchColumn();

    $totalRentalBookings = (int)$pdo->query("SELECT COUNT(*) FROM rental_bookings_backup")->fetchColumn();
    $pendingRentalBookings = (int)$pdo->query("SELECT COUNT(*) FROM rental_bookings_backup WHERE status = 'pending'")->fetchColumn();

    $paidPayments = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'paid'")->fetchColumn();
    $paidRevenue = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'")->fetchColumn();

    echo json_encode([
        'success' => true,
        'stats' => [
            'users_total' => $totalUsers,
            'users_active' => $activeUsers,
            'users_pending_approval' => $pendingApprovals,
            'users_by_role' => $roleCounts,
            'properties_total' => $totalProperties,
            'properties_published' => $publishedProperties,
            'properties_premium' => $premiumListings,
            'offers_total' => $totalOffers,
            'offers_pending' => $pendingOffers,
            'reservations_total' => $totalReservations,
            'reservations_pending_payment' => $pendingReservations,
            'sales_total' => $totalSales,
            'rental_bookings_total' => $totalRentalBookings,
            'rental_bookings_pending' => $pendingRentalBookings,
            'payments_paid_count' => $paidPayments,
            'payments_paid_revenue' => $paidRevenue,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
