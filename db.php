<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  global $config;
  $db = $config['db'];
  $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}
