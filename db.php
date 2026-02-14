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

function ensure_product_suppliers_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();

  $columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM products");
  foreach ($st->fetchAll() as $row) {
    $columns[(string)$row['Field']] = true;
  }

  if (!isset($columns['sale_mode'])) {
    $pdo->exec("ALTER TABLE products ADD COLUMN sale_mode ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD' AFTER brand");
  }

  if (!isset($columns['sale_units_per_pack'])) {
    $pdo->exec("ALTER TABLE products ADD COLUMN sale_units_per_pack INT UNSIGNED NULL AFTER sale_mode");
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS product_suppliers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    supplier_sku VARCHAR(120) NOT NULL DEFAULT '',
    cost_type ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD',
    units_per_pack INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_ps_product (product_id),
    KEY idx_ps_supplier (supplier_id),
    KEY idx_ps_active (product_id, is_active),
    CONSTRAINT fk_ps_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $ready = true;
}
