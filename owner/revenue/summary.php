<?php
require_once __DIR__ . '/../../cors.php';
require_once __DIR__ . '/../rentals/owner_guard.php';

header('Content-Type: application/json');

function monthBuckets(DateTime $start, DateTime $end): array {
    $months = [];
    $cursor = clone $start;
    while ($cursor <= $end) {
        $ym = $cursor->format('Y-m');
        $months[$ym] = [
            'label' => $cursor->format('M Y'),
            'rental' => 0.0,
            'property_reservation' => 0.0,
            'payments' => 0.0,
            'refunds' => 0.0,
        ];
        $cursor->modify('+1 month');
    }
    return $months;
}

function mapMonthlyTotals(PDO $pdo, string $sql, array $params): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $ym = $row['ym'] ?? null;
        if ($ym === null) {
            continue;
        }
        $map[$ym] = (float)($row['total'] ?? 0);
    }
    return $map;
}

try {
    // Total revenue (all time)
    $total = $pdo->prepare("
        SELECT COALESCE(SUM(rb.total_price), 0)
        FROM rental_bookings_backup rb
        JOIN properties p ON p.id = rb.property_id
        WHERE p.owner_id = ?
          AND rb.payment_status = 'paid'
    ");
    $total->execute([$OWNER_ID]);
    $totalRevenue = (float)$total->fetchColumn();

    // This month
    $month = $pdo->prepare("
        SELECT COALESCE(SUM(rb.total_price), 0)
        FROM rental_bookings_backup rb
        JOIN properties p ON p.id = rb.property_id
        WHERE p.owner_id = ?
          AND rb.payment_status = 'paid'
          AND MONTH(rb.created_at) = MONTH(CURRENT_DATE())
          AND YEAR(rb.created_at) = YEAR(CURRENT_DATE())
    ");
    $month->execute([$OWNER_ID]);
    $monthlyRevenue = (float)$month->fetchColumn();

    // This year
    $year = $pdo->prepare("
        SELECT COALESCE(SUM(rb.total_price), 0)
        FROM rental_bookings_backup rb
        JOIN properties p ON p.id = rb.property_id
        WHERE p.owner_id = ?
          AND rb.payment_status = 'paid'
          AND YEAR(rb.created_at) = YEAR(CURRENT_DATE())
    ");
    $year->execute([$OWNER_ID]);
    $yearlyRevenue = (float)$year->fetchColumn();

    // Upcoming paid revenue (future check-ins)
    $upcoming = $pdo->prepare("
        SELECT COALESCE(SUM(rb.total_price), 0)
        FROM rental_bookings_backup rb
        JOIN properties p ON p.id = rb.property_id
        WHERE p.owner_id = ?
          AND rb.payment_status = 'paid'
          AND rb.checkin > CURRENT_DATE()
    ");
    $upcoming->execute([$OWNER_ID]);
    $upcomingRevenue = (float)$upcoming->fetchColumn();

    $rangeEnd = new DateTime('first day of this month');
    $rangeStart = (clone $rangeEnd)->modify('-11 months');
    $rangeStartDate = $rangeStart->format('Y-m-01 00:00:00');
    $rangeEndDate = (clone $rangeEnd)->modify('last day of this month')->format('Y-m-d 23:59:59');

    $months = monthBuckets($rangeStart, $rangeEnd);

    $rentalsMap = mapMonthlyTotals(
        $pdo,
        "
        SELECT DATE_FORMAT(rb.created_at, '%Y-%m') AS ym,
               COALESCE(SUM(rb.total_price), 0) AS total
        FROM rental_bookings_backup rb
        JOIN properties p ON p.id = rb.property_id
        WHERE p.owner_id = :owner_id
          AND rb.payment_status = 'paid'
          AND rb.created_at BETWEEN :from AND :to
        GROUP BY ym
        ",
        [':owner_id' => $OWNER_ID, ':from' => $rangeStartDate, ':to' => $rangeEndDate]
    );

    $reservationsMap = mapMonthlyTotals(
        $pdo,
        "
        SELECT DATE_FORMAT(pr.created_at, '%Y-%m') AS ym,
               COALESCE(SUM(pr.reservation_fee), 0) AS total
        FROM property_reservations pr
        JOIN properties p ON p.id = pr.property_id
        WHERE p.owner_id = :owner_id
          AND pr.payment_status = 'paid'
          AND pr.created_at BETWEEN :from AND :to
        GROUP BY ym
        ",
        [':owner_id' => $OWNER_ID, ':from' => $rangeStartDate, ':to' => $rangeEndDate]
    );

    $paymentsMap = mapMonthlyTotals(
        $pdo,
        "
        SELECT DATE_FORMAT(pmt.created_at, '%Y-%m') AS ym,
               COALESCE(SUM(pmt.amount), 0) AS total
        FROM payments pmt
        LEFT JOIN property_reservations pr
          ON pmt.type = 'property_reservation' AND pr.id = pmt.reference_id
        LEFT JOIN rental_bookings_backup rb
          ON pmt.type = 'rental_booking' AND rb.id = pmt.reference_id
        LEFT JOIN properties p ON p.id = COALESCE(pr.property_id, rb.property_id)
        WHERE p.owner_id = :owner_id
          AND pmt.status = 'paid'
          AND pmt.created_at BETWEEN :from AND :to
        GROUP BY ym
        ",
        [':owner_id' => $OWNER_ID, ':from' => $rangeStartDate, ':to' => $rangeEndDate]
    );

    $refundsMap = mapMonthlyTotals(
        $pdo,
        "
        SELECT ym, SUM(total) AS total FROM (
            SELECT DATE_FORMAT(COALESCE(rb.refunded_at, rb.cancelled_at, rb.created_at), '%Y-%m') AS ym,
                   COALESCE(SUM(rb.refund_amount), 0) AS total
            FROM rental_bookings_backup rb
            JOIN properties p ON p.id = rb.property_id
            WHERE p.owner_id = :owner_id_rb
              AND rb.refund_status IN ('refunded','approved')
              AND COALESCE(rb.refunded_at, rb.cancelled_at, rb.created_at) BETWEEN :from_rb AND :to_rb
            GROUP BY ym
            UNION ALL
            SELECT DATE_FORMAT(COALESCE(pr.refunded_at, pr.cancelled_at, pr.created_at), '%Y-%m') AS ym,
                   COALESCE(SUM(pr.refund_amount), 0) AS total
            FROM property_reservations pr
            JOIN properties p ON p.id = pr.property_id
            WHERE p.owner_id = :owner_id_pr
              AND pr.refund_status IN ('approved','processed')
              AND COALESCE(pr.refunded_at, pr.cancelled_at, pr.created_at) BETWEEN :from_pr AND :to_pr
            GROUP BY ym
        ) t
        GROUP BY ym
        ",
        [
            ':owner_id_rb' => $OWNER_ID,
            ':from_rb' => $rangeStartDate,
            ':to_rb' => $rangeEndDate,
            ':owner_id_pr' => $OWNER_ID,
            ':from_pr' => $rangeStartDate,
            ':to_pr' => $rangeEndDate
        ]
    );

    $chart = [];
    $totals = [
        'rental' => 0.0,
        'property_reservation' => 0.0,
        'payments' => 0.0,
        'refunds' => 0.0,
    ];

    foreach ($months as $ym => $meta) {
        $rental = (float)($rentalsMap[$ym] ?? 0);
        $reservation = (float)($reservationsMap[$ym] ?? 0);
        $payments = (float)($paymentsMap[$ym] ?? 0);
        $refunds = (float)($refundsMap[$ym] ?? 0);

        $chart[] = [
            'label' => $meta['label'],
            'rental' => $rental,
            'property_reservation' => $reservation,
            'payments' => $payments,
            'refunds' => $refunds,
        ];

        $totals['rental'] += $rental;
        $totals['property_reservation'] += $reservation;
        $totals['payments'] += $payments;
        $totals['refunds'] += $refunds;
    }

    $pie = [
        ['name' => 'Rentals', 'value' => $totals['rental']],
        ['name' => 'Property Reservations', 'value' => $totals['property_reservation']],
        ['name' => 'Payments', 'value' => $totals['payments']],
        ['name' => 'Refunds', 'value' => $totals['refunds']],
    ];

    echo json_encode([
        'success' => true,
        'data' => [
            'total'    => $totalRevenue,
            'month'    => $monthlyRevenue,
            'year'     => $yearlyRevenue,
            'upcoming' => $upcomingRevenue,
            'chart'    => $chart,
            'pie'      => $pie,
            'range'    => [
                'from' => $rangeStart->format('Y-m-01'),
                'to' => $rangeEnd->format('Y-m-d'),
            ],
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
