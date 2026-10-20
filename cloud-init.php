<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$pdo = db();
$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) {
  throw new RuntimeException('schema.sql not found');
}

// The database is provisioned by the hosting provider. Remove local-only DB selection.
$sql = preg_replace('/^\s*CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+student_wms[^;]*;\s*/im', '', $sql);
$sql = preg_replace('/^\s*USE\s+student_wms\s*;\s*/im', '', $sql);

foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
  if ($statement !== '') $pdo->exec($statement);
}

$exists = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$exists->execute(['admin']);
if (!$exists->fetch()) {
  $adminPassword = getenv('ADMIN_PASSWORD') ?: 'ChangeMe-Immediately-123!';
  $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare("INSERT INTO users(name,username,password_hash,role) VALUES(?,?,?,'admin')");
  $stmt->execute(['ผู้ดูแลระบบ', 'admin', $hash]);
}
echo "WMS database initialized.\n";
