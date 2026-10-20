<?php
declare(strict_types=1);

/**
 * Supports Railway MySQL environment variables and local XAMPP defaults.
 * Do not put production credentials in source control.
 */
function envv(string $key, string $default = ''): string {
  $value = getenv($key);
  return ($value === false || $value === '') ? $default : $value;
}
const LOCAL_DB_HOST = '127.0.0.1';
const LOCAL_DB_NAME = 'student_wms';
const LOCAL_DB_USER = 'root';
const LOCAL_DB_PASS = '';

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $host = envv('MYSQLHOST', envv('DB_HOST', LOCAL_DB_HOST));
  $port = envv('MYSQLPORT', envv('DB_PORT', '3306'));
  $name = envv('MYSQLDATABASE', envv('DB_NAME', LOCAL_DB_NAME));
  $user = envv('MYSQLUSER', envv('DB_USER', LOCAL_DB_USER));
  $pass = envv('MYSQLPASSWORD', envv('DB_PASS', LOCAL_DB_PASS));

  $pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );
  return $pdo;
}
