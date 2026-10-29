<?php
require_once __DIR__ . '/db.php';

function tfg_mail($to, $subject, $body, $replyTo = '') {
  $clean = function ($s) { return str_replace(["\r", "\n"], ' ', (string)$s); };
  $from = cfg('mail_from');
  $headers = [
    'From: "' . str_replace('"', '', $clean(cfg('shop_name'))) . '" <' . $from . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
  ];
  if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;
  $subj = '=?UTF-8?B?' . base64_encode($clean($subject)) . '?=';
  $ok = @mail($to, $subj, $body, implode("\r\n", $headers), '-f' . $from);
  if (!$ok) error_log('TFG mail failed for ' . $to);
  return $ok;
}

// Sends the customer confirmation and the owner notice. Never throws: a mail problem must not lose a booking.
function send_booking_emails(array $b) {
  if (!cfg('mail_enabled')) return;
  try {
    $d = DateTime::createFromFormat('Y-m-d', $b['date']);
    $when = ($d ? $d->format('l, F j, Y') : $b['date']) . ' at ' . $b['time'];
    $shop = cfg('shop_name');

    $details = "Reference: {$b['ref']}\n"
             . "Service: {$b['service']}\n"
             . "Motorcycle: {$b['brand']} {$b['model']}, {$b['year']} ({$b['trans']})\n"
             . "Date and time: $when\n"
             . "Payment: {$b['pay']}\n"
             . ($b['notes'] !== '' ? "Notes: {$b['notes']}\n" : '');

    $customer = "Hi {$b['name']},\n\n"
      . "Thanks for booking with $shop. We received your request and will confirm it shortly.\n\n"
      . $details . "\n"
      . "Need to change or cancel? Call " . cfg('shop_phone') . " and quote your reference.\n\n"
      . "$shop\n" . cfg('shop_address') . "\n";
    tfg_mail($b['email'], "Booking request received ({$b['ref']})", $customer, cfg('owner_email'));

    $owner = "New booking request.\n\n"
      . $details
      . "Customer: {$b['name']}\nPhone: {$b['phone']}\nEmail: {$b['email']}\n\n"
      . "Manage it: " . cfg('site_url') . "/admin.php\n";
    tfg_mail(cfg('owner_email'), "New booking: {$b['date']} {$b['time']} ({$b['ref']})", $owner, $b['email']);
  } catch (Throwable $e) {
    error_log('TFG mail error: ' . $e->getMessage());
  }
}
