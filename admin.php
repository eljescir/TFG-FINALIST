<?php
require __DIR__ . '/db.php';

/* ---------- Settings ---------- */
// Placeholder password. Change it before going live (see "Change password" at the bottom of the page).
const ADMIN_PASSWORD = 'tfg12345';
// Optional: paste a hash from the "Change password" tool here. When set, ADMIN_PASSWORD is ignored.
const ADMIN_PASSWORD_HASH = '';

const SLOTS_PER_TIME = 2;
const STATUSES = ['Requested', 'Confirmed', 'Done', 'Cancelled'];

date_default_timezone_set('Asia/Manila');
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
  'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function check_password($pw) {
  if (ADMIN_PASSWORD_HASH !== '') return password_verify($pw, ADMIN_PASSWORD_HASH);
  return hash_equals(ADMIN_PASSWORD, $pw);
}

function filters() {
  $s = $_REQUEST['status'] ?? 'Requested';
  if ($s !== 'All' && !in_array($s, STATUSES, true)) $s = 'Requested';
  return ['status' => $s, 'q' => mb_substr(trim((string)($_REQUEST['q'] ?? '')), 0, 60)];
}

function redirect_back($msg = '', $type = 'ok') {
  if ($msg !== '') $_SESSION['flash'] = [$type, $msg];
  $f = filters();
  if ($f['q'] === '') unset($f['q']);
  header('Location: admin.php?' . http_build_query($f));
  exit;
}

$loginError = '';
$newHash = '';

/* ---------- POST handling ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('Session expired. Go back, reload the page and try again.');
  }
  $action = $_POST['action'] ?? '';

  if ($action === 'login') {
    if (($_SESSION['lock_until'] ?? 0) > time()) {
      $loginError = 'Too many tries. Wait a minute and try again.';
    } elseif (check_password((string)($_POST['password'] ?? ''))) {
      session_regenerate_id(true);
      $_SESSION['admin'] = true;
      $_SESSION['fails'] = 0;
      $_SESSION['csrf'] = bin2hex(random_bytes(16));
      header('Location: admin.php');
      exit;
    } else {
      $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
      if ($_SESSION['fails'] >= 5) { $_SESSION['lock_until'] = time() + 60; $_SESSION['fails'] = 0; }
      sleep(1);
      $loginError = 'Wrong password.';
    }
  } elseif (!empty($_SESSION['admin'])) {

    if ($action === 'logout') {
      $_SESSION = [];
      session_destroy();
      header('Location: admin.php');
      exit;
    }

    if ($action === 'make_hash') {
      $pw = (string)($_POST['new_password'] ?? '');
      if (mb_strlen($pw) < 8) $_SESSION['flash'] = ['err', 'Use at least 8 characters.'];
      else $newHash = password_hash($pw, PASSWORD_DEFAULT);
    }

    if (in_array($action, ['set_status', 'set_paid', 'delete'], true)) {
      $ref = strtoupper(trim((string)($_POST['ref'] ?? '')));
      try {
        $pdo = db();
        $cur = $pdo->prepare('SELECT status, payment_method, payment_status, booking_date, booking_time FROM bookings WHERE booking_ref=?');
        $cur->execute([$ref]);
        $b = $cur->fetch();
        if (!$b) redirect_back('Booking not found.', 'err');

        if ($action === 'set_status') {
          $st = (string)($_POST['value'] ?? '');
          if (!in_array($st, STATUSES, true)) redirect_back('Invalid status.', 'err');
          if ($b['status'] === 'Cancelled' && $st !== 'Cancelled') {
            $c = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_date=? AND booking_time=? AND status<>'Cancelled'");
            $c->execute([$b['booking_date'], $b['booking_time']]);
            if ((int)$c->fetchColumn() >= SLOTS_PER_TIME)
              redirect_back("Can't restore $ref: that time slot is now full.", 'err');
          }
          $pdo->prepare('UPDATE bookings SET status=? WHERE booking_ref=?')->execute([$st, $ref]);
          redirect_back("$ref is now $st.");
        }

        if ($action === 'set_paid') {
          $paid = ($_POST['value'] ?? '') === 'paid';
          $new = $paid ? 'Paid' : ($b['payment_method'] === 'Cash' ? 'Cash at shop' : 'Pending');
          $pdo->prepare('UPDATE bookings SET payment_status=? WHERE booking_ref=?')->execute([$new, $ref]);
          redirect_back($paid ? "$ref marked as Paid." : "$ref payment set to $new.");
        }

        if ($action === 'delete') {
          if ($b['status'] !== 'Cancelled') redirect_back('Only cancelled bookings can be deleted.', 'err');
          $pdo->prepare('DELETE FROM bookings WHERE booking_ref=?')->execute([$ref]);
          redirect_back("$ref deleted.");
        }
      } catch (PDOException $ex) {
        redirect_back('Database error: ' . $ex->getMessage(), 'err');
      }
    }
  }
}

$isAdmin = !empty($_SESSION['admin']);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Data ---------- */
$rows = [];
$counts = [];
$total = 0;
$dbError = '';
$f = filters();
if ($isAdmin) {
  try {
    $pdo = db();
    foreach ($pdo->query('SELECT status, COUNT(*) n FROM bookings GROUP BY status') as $r) {
      $counts[$r['status']] = (int)$r['n'];
      $total += (int)$r['n'];
    }
    $where = []; $args = [];
    if ($f['status'] !== 'All') { $where[] = 'status=?'; $args[] = $f['status']; }
    if ($f['q'] !== '') {
      $where[] = '(full_name LIKE ? OR booking_ref LIKE ? OR phone LIKE ? OR email LIKE ?)';
      $like = '%' . $f['q'] . '%';
      array_push($args, $like, $like, $like, $like);
    }
    $upcomingFirst = in_array($f['status'], ['Requested', 'Confirmed'], true);
    $dir = $upcomingFirst ? 'ASC' : 'DESC';
    $sql = 'SELECT * FROM bookings' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY booking_date $dir, STR_TO_DATE(booking_time, '%h:%i %p') $dir LIMIT 300";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
  } catch (PDOException $ex) {
    $dbError = $ex->getMessage();
  }
}

function action_btn($ref, $action, $value, $label, $class = '', $confirm = '') {
  global $f;
  $c = $confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '';
  return '<form method="post"' . $c . '>'
    . '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'
    . '<input type="hidden" name="action" value="' . e($action) . '">'
    . '<input type="hidden" name="ref" value="' . e($ref) . '">'
    . '<input type="hidden" name="value" value="' . e($value) . '">'
    . '<input type="hidden" name="status" value="' . e($f['status']) . '">'
    . '<input type="hidden" name="q" value="' . e($f['q']) . '">'
    . '<button class="b ' . e($class) . '">' . e($label) . '</button></form>';
}
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Bookings admin | The Feng's Garage</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,600;1,800&family=Titillium+Web:wght@400;600;700&display=swap" rel="stylesheet" />
  <style>
    :root {
      --bg: #121212; --panel: #1b1b1b; --line: #2f2f2f; --text: #ececec; --muted: #9b9b9b;
      --accent: #ff6b5b;
      --requested: #f5b83d; --confirmed: #5aa9ff; --done: #4cc38a; --cancelled: #8c8c8c;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 400 16px/1.5 "Titillium Web", system-ui, sans-serif; }
    a { color: var(--accent); }
    h1, h2 { font-family: "Barlow Condensed", sans-serif; font-style: italic; font-weight: 800; margin: 0; letter-spacing: .01em; }
    .wrap { max-width: 1040px; margin: 0 auto; padding: 0 18px; }
    .top { border-bottom: 1px solid var(--line); background: var(--panel); }
    .top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-top: 14px; padding-bottom: 14px; }
    .top h1 { font-size: 30px; }
    .top h1 span { color: var(--accent); }
    .flash { margin: 18px 0 0; padding: 10px 14px; border-radius: 6px; border-left: 4px solid var(--done); background: var(--panel); }
    .flash.err { border-color: var(--accent); }
    .warn { margin: 18px 0 0; padding: 10px 14px; border-radius: 6px; border-left: 4px solid var(--requested); background: var(--panel); }
    .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 22px 0 14px; }
    .tabs a { text-decoration: none; color: var(--muted); padding: 7px 14px; border: 1px solid var(--line); border-radius: 999px; font-weight: 600; }
    .tabs a b { color: var(--text); margin-left: 4px; font-weight: 700; }
    .tabs a.on { background: var(--accent); border-color: var(--accent); color: #111; }
    .tabs a.on b { color: #111; }
    .search { display: flex; gap: 8px; margin-bottom: 18px; }
    .search input { flex: 1; min-width: 0; }
    input[type=text], input[type=password] { background: var(--bg); color: var(--text); border: 1px solid var(--line); border-radius: 6px; padding: 10px 12px; font: inherit; }
    input:focus-visible, .b:focus-visible, .tabs a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    .b { background: transparent; color: var(--text); border: 1px solid var(--line); border-radius: 6px; padding: 8px 14px; font: 600 15px "Titillium Web", sans-serif; cursor: pointer; }
    .b:hover { border-color: var(--text); }
    .b.main { background: var(--accent); border-color: var(--accent); color: #111; }
    .b.good { background: var(--done); border-color: var(--done); color: #111; }
    .b.danger { color: var(--accent); border-color: var(--accent); }
    .card { background: var(--panel); border: 1px solid var(--line); border-left-width: 5px; border-radius: 8px; padding: 16px 18px; margin-bottom: 14px; }
    .card.s-Requested { border-left-color: var(--requested); }
    .card.s-Confirmed { border-left-color: var(--confirmed); }
    .card.s-Done { border-left-color: var(--done); }
    .card.s-Cancelled { border-left-color: var(--cancelled); opacity: .75; }
    .head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 14px; }
    .when { font: 800 26px/1.1 "Barlow Condensed", sans-serif; font-style: italic; }
    .when small { font: 600 15px "Titillium Web", sans-serif; font-style: normal; color: var(--muted); margin-left: 8px; }
    .chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .chip { font-size: 13px; font-weight: 700; padding: 2px 10px; border-radius: 999px; border: 1px solid currentColor; }
    .chip.Requested { color: var(--requested); } .chip.Confirmed { color: var(--confirmed); }
    .chip.Done { color: var(--done); } .chip.Cancelled { color: var(--cancelled); }
    .chip.Paid { background: var(--done); border-color: var(--done); color: #111; }
    .chip.Pending { color: var(--requested); } .chip.cash { color: var(--muted); }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px 24px; margin: 14px 0; }
    .grid h2 { font-size: 20px; margin-bottom: 2px; }
    .grid p { margin: 0; color: var(--muted); overflow-wrap: anywhere; }
    .grid p b { color: var(--text); font-weight: 600; }
    .acts { display: flex; flex-wrap: wrap; gap: 8px; padding-top: 12px; border-top: 1px solid var(--line); }
    .acts form { margin: 0; }
    .empty { text-align: center; padding: 48px 12px; color: var(--muted); }
    details { margin: 30px 0 60px; color: var(--muted); }
    details summary { cursor: pointer; font-weight: 600; }
    code { display: block; margin-top: 10px; padding: 10px; background: var(--bg); border: 1px solid var(--line); border-radius: 6px; color: var(--text); overflow-wrap: anywhere; user-select: all; }
    .login { max-width: 360px; margin: 14vh auto 0; padding: 26px; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; }
    .login h1 { font-size: 34px; margin-bottom: 4px; }
    .login p { color: var(--muted); margin: 0 0 18px; }
    .login input { width: 100%; margin-bottom: 12px; }
    .login .b { width: 100%; padding: 11px; }
    .err-text { color: var(--accent); margin: 0 0 12px; }
    @media (prefers-reduced-motion: no-preference) { .b { transition: border-color .15s; } }
  </style>
</head>
<body>
<?php if (!$isAdmin): ?>
  <form class="login" method="post" autocomplete="off">
    <h1>TFG admin</h1>
    <p>Sign in to manage bookings.</p>
    <?php if ($loginError): ?><p class="err-text"><?= e($loginError) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="login">
    <input type="password" name="password" placeholder="Password" required autofocus aria-label="Password">
    <button class="b main">Sign in</button>
  </form>
<?php else: ?>
  <div class="top">
    <div class="wrap">
      <h1>Bookings <span>&middot;</span> TFG</h1>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="b">Sign out</button>
      </form>
    </div>
  </div>

  <div class="wrap">
    <?php if (ADMIN_PASSWORD_HASH === '' && ADMIN_PASSWORD === 'tfg-change-me'): ?>
      <div class="warn">You are still using the placeholder password. Change it at the bottom of this page.</div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>
    <?php if ($dbError): ?><div class="flash err">Database error: <?= e($dbError) ?></div><?php endif; ?>

    <nav class="tabs" aria-label="Filter by status">
      <?php foreach (array_merge(STATUSES, ['All']) as $s):
        $n = $s === 'All' ? $total : ($counts[$s] ?? 0);
        $qs = http_build_query(array_filter(['status' => $s, 'q' => $f['q']], fn($v) => $v !== '')); ?>
        <a class="<?= $f['status'] === $s ? 'on' : '' ?>" href="admin.php?<?= e($qs) ?>"><?= e($s) ?><b><?= $n ?></b></a>
      <?php endforeach; ?>
    </nav>

    <form class="search" method="get">
      <input type="hidden" name="status" value="<?= e($f['status']) ?>">
      <input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="Search name, phone, email or reference" aria-label="Search bookings">
      <button class="b">Search</button>
      <?php if ($f['q'] !== ''): ?><a class="b" style="text-decoration:none" href="admin.php?status=<?= e($f['status']) ?>">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows && !$dbError): ?>
      <div class="empty">No <?= $f['status'] === 'All' ? '' : e(strtolower($f['status'])) . ' ' ?>bookings<?= $f['q'] !== '' ? ' match your search' : ' right now' ?>.</div>
    <?php endif; ?>

    <?php foreach ($rows as $b):
      $d = DateTime::createFromFormat('Y-m-d', $b['booking_date']);
      $dateLabel = $d ? $d->format('D, M j, Y') : $b['booking_date'];
      $isToday = $b['booking_date'] === $today;
      $ref = $b['booking_ref'];
      $paid = $b['payment_status'] === 'Paid';
      $payClass = $paid ? 'Paid' : ($b['payment_status'] === 'Pending' ? 'Pending' : 'cash');
    ?>
      <article class="card s-<?= e($b['status']) ?>">
        <div class="head">
          <div class="when"><?= e($b['booking_time']) ?> &middot; <?= e($dateLabel) ?><?php if ($isToday): ?><small>Today</small><?php endif; ?></div>
          <div class="chips">
            <span class="chip <?= e($b['status']) ?>"><?= e($b['status']) ?></span>
            <span class="chip <?= e($payClass) ?>"><?= e($b['payment_method']) ?>: <?= e($b['payment_status']) ?></span>
          </div>
        </div>

        <div class="grid">
          <div>
            <h2><?= e($b['full_name']) ?></h2>
            <p><a href="tel:<?= e($b['phone']) ?>"><?= e($b['phone']) ?></a></p>
            <p><a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a></p>
          </div>
          <div>
            <h2><?= e($b['service']) ?></h2>
            <p><b><?= e($b['brand']) ?> <?= e($b['model']) ?></b>, <?= e($b['year_model']) ?> &middot; <?= e($b['transmission']) ?></p>
          </div>
          <div>
            <h2><?= e($ref) ?></h2>
            <p><?= $b['notes'] !== '' && $b['notes'] !== null ? e($b['notes']) : 'No notes' ?></p>
          </div>
        </div>

        <div class="acts">
          <?php
            if ($b['status'] === 'Requested') {
              echo action_btn($ref, 'set_status', 'Confirmed', 'Confirm', 'main');
            } elseif ($b['status'] === 'Confirmed') {
              echo action_btn($ref, 'set_status', 'Done', 'Mark done', 'main');
            } elseif ($b['status'] === 'Done') {
              echo action_btn($ref, 'set_status', 'Confirmed', 'Reopen');
            } else {
              echo action_btn($ref, 'set_status', 'Requested', 'Restore');
            }
            if ($b['status'] !== 'Cancelled') {
              echo action_btn($ref, 'set_status', 'Cancelled', 'Cancel booking', 'danger', "Cancel booking $ref? The time slot will open up again.");
            }
            echo $paid
              ? action_btn($ref, 'set_paid', 'unpaid', 'Undo paid')
              : action_btn($ref, 'set_paid', 'paid', 'Mark as paid', 'good');
            if ($b['status'] === 'Cancelled') {
              echo action_btn($ref, 'delete', '', 'Delete', 'danger', "Delete $ref permanently? This cannot be undone.");
            }
          ?>
        </div>
      </article>
    <?php endforeach; ?>

    <details <?= $newHash ? 'open' : '' ?>>
      <summary>Change password</summary>
      <form method="post" style="margin-top:12px" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="make_hash">
        <input type="hidden" name="status" value="<?= e($f['status']) ?>">
        <input type="password" name="new_password" placeholder="New password (8+ characters)" minlength="8" aria-label="New password">
        <button class="b">Create hash</button>
      </form>
      <?php if ($newHash): ?>
        <p>Open <b>admin.php</b>, find <b>ADMIN_PASSWORD_HASH</b> near the top and paste this between the quotes:</p>
        <code><?= e($newHash) ?></code>
      <?php endif; ?>
    </details>
  </div>
<?php endif; ?>
</body>
</html>
