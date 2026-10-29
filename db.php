<?php
function cfg($key, $default = null) {
  static $c = null;
  if ($c === null) $c = require __DIR__ . '/config.php';
  return $c[$key] ?? $default;
}

date_default_timezone_set(cfg('timezone', 'Asia/Manila'));

function db() {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO(
    'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4',
    cfg('db_user'),
    cfg('db_pass'),
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . cfg('utc_offset', '+08:00') . "'",
    ]
  );
  return $pdo;
}
