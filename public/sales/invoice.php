<?php
// [UNUSED]
// Reason: Not referenced by current frontend.
// Planned feature or legacy: Legacy sales invoice endpoint.
// Safe to remove after: 2026-06-30 (if no sales flow uses it).
require_once __DIR__ . '/../Database.php';

$sale_id = $_GET['sale_id'] ?? null;
if (!$sale_id) die("Invalid sale");

$db = new Database();
$pdo = $db->getPdo();

$stmt = $pdo->prepare("
    SELECT 
        s.invoice_number,
        s.sold_at,
        s.final_price,
        p.title,
        p.location,
        u.first_name,
        u.last_name,
        u.email
    FROM property_sales s
    JOIN properties p ON p.id = s.property_id
    JOIN users u ON u.id = s.buyer_id
    WHERE s.id = ?
");
$stmt->execute([$sale_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) die("Invoice not found");
?>

<!DOCTYPE html>
<html>
<head>
  <title>Invoice <?= $data['invoice_number'] ?></title>
  <style>
    body { font-family: Arial; padding: 40px; }
    h1 { color: #1f2937; }
    .box { border: 1px solid #ddd; padding: 20px; margin-top: 20px; }
  </style>
</head>
<body>

<h1>Sentinel Property Invoice</h1>

<div class="box">
  <p><strong>Invoice:</strong> <?= $data['invoice_number'] ?></p>
  <p><strong>Date:</strong> <?= $data['sold_at'] ?></p>
</div>

<div class="box">
  <h3>Buyer Details</h3>
  <p><?= $data['first_name'] ?> <?= $data['last_name'] ?></p>
  <p><?= $data['email'] ?></p>
</div>

<div class="box">
  <h3>Property</h3>
  <p><?= $data['title'] ?></p>
  <p><?= $data['location'] ?></p>
</div>

<div class="box">
  <h2>Total Paid: Rs <?= number_format($data['final_price'], 2) ?></h2>
</div>

<p style="margin-top:40px;">
  This invoice confirms the successful completion of the property transaction.
</p>

</body>
</html>
