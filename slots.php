<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$out = [];
try {
  $rows = db()->query("SELECT booking_date, booking_time, COUNT(*) AS taken FROM bookings WHERE status <> 'Cancelled' AND booking_date >= CURDATE() GROUP BY booking_date, booking_time");
  foreach ($rows as $r) $out[$r['booking_date']][$r['booking_time']] = (int)$r['taken'];
} catch (Exception $e) {
  http_response_code(500);
}
echo json_encode((object)$out);
