<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  global $config;
  $db = $config['db'];
  $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
  try {
    $configSource = $config['config_file'] ?? (__DIR__ . '/config.php');
    error_log(sprintf(
      '[%s] DB config source: %s | host=%s | db=%s | user=%s | pass_set=%s',
      date('c'),
      $configSource,
      $db['host'],
      $db['name'],
      $db['user'],
      $db['pass'] !== '' ? 'yes' : 'no'
    ));
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  } catch (PDOException $e) {
    error_log(sprintf('[%s] DB connection failed: %s', date('c'), $e->getMessage()));
    $debug = (bool)($config['debug'] ?? false);
    $message = 'No se pudo conectar con la base de datos. Verificá las credenciales en config.php o en tus variables de entorno.';
    if ($debug) {
      $message = sprintf('Error de base de datos: %s', $e->getMessage());
    }
    abort(500, $message);
  }
  return $pdo;
}
