<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../requireAdmin.php';
require_once __DIR__ . '/../../Database.php';

header('Content-Type: application/json');

function buildDateList(DateTime $from, DateTime $to): array {
    $dates = [];
    $cursor = clone $from;
    while ($cursor <= $to) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }
    return $dates;
}

function seriesFromQuery(PDO $pdo, string $sql, array $params, array $dates, string $valueKey = 'count'): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $map[$row['date']] = (float)$row[$valueKey];
    }

    $series = [];
    foreach ($dates as $date) {
        $value = $map[$date] ?? 0;
        $series[] = [
            'date' => $date,
            $valueKey => is_float($value) ? $value : (int)$value,
        ];
    }
    return $series;
}

try {
    $fromParam = trim((string)($_GET['from'] ?? ''));
    $toParam = trim((string)($_GET['to'] ?? ''));

    $to = $toParam !== '' ? new DateTime($toParam) : new DateTime();
    $from = $fromParam !== '' ? new DateTime($fromParam) : (clone $to)->modify('-29 days');

    if ($from > $to) {
        throw new RuntimeException('Invalid date range.');
    }

    $fromDate = $from->format('Y-m-d');
    $toDate = $to->format('Y-m-d');
    $fromDateTime = $fromDate . ' 00:00:00';
    $toDateTime = $toDate . ' 23:59:59';

    $dates = buildDateList($from, $to);

    $pdo = (new Database())->getPdo();

    $usersSeries = seriesFromQuery(
        $pdo,
        "SELECT DATE(created_at) AS date, COUNT(*) AS count
         FROM users
         WHERE created_at BETWEEN :from AND :to
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at)",
        [':from' => $fromDateTime, ':to' => $toDateTime],
        $dates,
        'count'
    );

    $propertiesSeries = seriesFromQuery(
        $pdo,
        "SELECT DATE(created_at) AS date, COUNT(*) AS count
         FROM properties
         WHERE created_at BETWEEN :from AND :to
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at)",
        [':from' => $fromDateTime, ':to' => $toDateTime],
        $dates,
        'count'
    );

    $offersSeries = seriesFromQuery(
        $pdo,
        "SELECT DATE(created_at) AS date, COUNT(*) AS count
         FROM property_offers
         WHERE created_at BETWEEN :from AND :to
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at)",
        [':from' => $fromDateTime, ':to' => $toDateTime],
        $dates,
        'count'
    );

    $reservationsSeries = seriesFromQuery(
        $pdo,
        "SELECT DATE(created_at) AS date, COUNT(*) AS count
         FROM property_reservations
         WHERE created_at BETWEEN :from AND :to
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at)",
        [':from' => $fromDateTime, ':to' => $toDateTime],
        $dates,
        'count'
    );

    $salesSeries = seriesFromQuery(
        $pdo,
        "SELECT DATE(sold_at) AS date, COUNT(*) AS count
         FROM property_sales
         WHERE sold_at BETWEEN :from AND :to
         GROUP BY DATE(sold_at)
         ORDER BY DATE(sold_at)",
        [':from' => $fromDateTime, ':to' => $toDateTime],
        $dates,
        'count'
    );

    $salesRevenueSeries = seriesFromQuery(
        $pdo,
        "SELECT DATE(sold_at) AS date, COALESCE(SUM(final_price), 0) AS total
         FROM property_sales
         WHERE sold_at BETWEEN :from AND :to
         GROUP BY DATE(sold_at)
         ORDER BY DATE(sold_at)",
        [':from' => $fromDateTime, ':to' => $toDateTime],
        $dates,
        'total'
    );

    $paymentsSeries = seriesFromQuery(
        $pdo,
        "SELECT DATE(created_at) AS date, COALESCE(SUM(amount), 0) AS total
         FROM payments
         WHERE status = 'paid' AND created_at BETWEEN :from AND :to
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at)",
        [':from' => $fromDateTime, ':to' => $toDateTime],
        $dates,
        'total'
    );

    $rentalsSeries = seriesFromQuery(
        $pdo,
        "SELECT DATE(created_at) AS date, COUNT(*) AS count
         FROM rental_bookings_backup
         WHERE created_at BETWEEN :from AND :to
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at)",
        [':from' => $fromDateTime, ':to' => $toDateTime],
        $dates,
        'count'
    );

    echo json_encode([
        'success' => true,
        'range' => [
            'from' => $fromDate,
            'to' => $toDate,
        ],
        'series' => [
            'users' => $usersSeries,
            'properties' => $propertiesSeries,
            'offers' => $offersSeries,
            'reservations' => $reservationsSeries,
            'sales' => $salesSeries,
            'sales_revenue' => $salesRevenueSeries,
            'payments_revenue' => $paymentsSeries,
            'rental_bookings' => $rentalsSeries,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
