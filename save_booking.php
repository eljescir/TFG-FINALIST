<?php
require __DIR__ . '/db.php';
require __DIR__ . '/mailer.php';

const SLOTS_PER_TIME = 2;
const TIMES = ['9:00 AM','10:00 AM','11:00 AM','1:00 PM','2:00 PM','3:00 PM','4:00 PM','5:00 PM'];

$wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function finish($ok, $msg, $ref = '', $code = 200) {
  global $wantsJson;
  http_response_code($code);
  if ($wantsJson) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'message' => $msg, 'ref' => $ref]);
    exit;
  }
  $t = htmlspecialchars($ok ? 'Booking received' : 'Booking not saved');
  $m = htmlspecialchars($msg);
  $r = $ref ? '<p>Your reference: <b>' . htmlspecialchars($ref) . '</b></p>' : '';
  echo "<!doctype html><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'><title>$t</title>"
     . "<body style='font-family:sans-serif;background:#111;color:#eee;display:grid;place-items:center;min-height:100vh;margin:0'>"
     . "<div style='max-width:420px;padding:24px;text-align:center'><h1>$t</h1><p>$m</p>$r<p><a style='color:#ff6b5b' href='booking.html'>Back to booking</a></p></div>";
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') finish(false, 'Invalid request.', '', 405);

// Spam trap: real visitors never see or fill this hidden field.
if (trim((string)($_POST['website'] ?? '')) !== '')
  finish(true, 'We saved your booking request. We will confirm it shortly.', 'TFG-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)));

function f($k, $max) { return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max); }

$service = f('service', 60);
$brand = f('brand', 30);
$model = f('model', 60);
$year = f('year', 20);
$trans = f('transmission', 10);
$date = f('date', 10);
$time = f('time', 10);
$name = f('name', 100);
$phone = f('phone', 15);
$email = strtolower(f('email', 254));
$notes = f('notes', 255);
$pay = f('payment', 40);
$ref = strtoupper(f('booking_ref', 12));

$errors = [];
foreach (['service'=>$service,'brand'=>$brand,'model'=>$model,'year'=>$year,'name'=>$name,'payment'=>$pay] as $k => $v)
  if ($v === '') $errors[] = "Missing $k.";
if (!in_array($trans, ['Automatic','Manual'], true)) $errors[] = 'Invalid transmission.';
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d')) $errors[] = 'Invalid date.';
if (!in_array($time, TIMES, true)) $errors[] = 'Invalid time.';
if (!preg_match('/^(\+63|0)9[0-9]{9}$/', $phone)) $errors[] = 'Invalid phone.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
if ($errors) finish(false, implode(' ', $errors), '', 422);

$payStatus = $pay === 'Cash' ? 'Cash at shop' : 'Pending';

try {
  $pdo = db();
  $pdo->beginTransaction();

  $q = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_date=? AND booking_time=? AND status<>'Cancelled' FOR UPDATE");
  $q->execute([$date, $time]);
  if ((int)$q->fetchColumn() >= SLOTS_PER_TIME) {
    $pdo->rollBack();
    finish(false, 'That time just filled up. Please choose another date or time.', '', 409);
  }

  if (!preg_match('/^TFG-[A-Z0-9]{6}$/', $ref)) $ref = '';
  for ($i = 0; $i < 5; $i++) {
    if ($ref !== '') {
      $c = $pdo->prepare('SELECT 1 FROM bookings WHERE booking_ref=?');
      $c->execute([$ref]);
      if (!$c->fetchColumn()) break;
    }
    $ref = 'TFG-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
  }

  $ins = $pdo->prepare('INSERT INTO bookings
    (booking_ref, service, brand, model, year_model, transmission, booking_date, booking_time,
     full_name, phone, email, notes, payment_method, payment_status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $ins->execute([$ref,$service,$brand,$model,$year,$trans,$date,$time,$name,$phone,$email,$notes,$pay,$payStatus]);
  $pdo->commit();
} catch (Exception $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  finish(false, 'Could not save your booking. Please try again.', '', 500);
}

send_booking_emails([
  'ref' => $ref, 'service' => $service, 'brand' => $brand, 'model' => $model, 'year' => $year,
  'trans' => $trans, 'date' => $date, 'time' => $time, 'name' => $name, 'phone' => $phone,
  'email' => $email, 'notes' => $notes, 'pay' => $pay,
]);

finish(true, 'We saved your booking request. We will confirm it shortly.', $ref);
