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
    default_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $supplier_columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM suppliers");
  foreach ($st->fetchAll() as $row) {
    $supplier_columns[(string)$row['Field']] = true;
  }

  if (!isset($supplier_columns['default_margin_percent'])) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN default_margin_percent DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER name");
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS product_suppliers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    supplier_sku VARCHAR(120) NOT NULL DEFAULT '',
    cost_type ENUM('UNIDAD','PACK') NOT NULL DEFAULT 'UNIDAD',
    units_per_pack INT UNSIGNED NULL,
    supplier_cost DECIMAL(10,2) NULL,
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

  $product_supplier_columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM product_suppliers");
  foreach ($st->fetchAll() as $row) {
    $product_supplier_columns[(string)$row['Field']] = true;
  }

  if (!isset($product_supplier_columns['supplier_cost'])) {
    $pdo->exec("ALTER TABLE product_suppliers ADD COLUMN supplier_cost DECIMAL(10,2) NULL AFTER units_per_pack");
  }

  $productSupplierUniqueExists = false;
  $st = $pdo->query("SHOW INDEX FROM product_suppliers WHERE Key_name = 'uq_product_supplier_link'");
  if ($st->fetch()) {
    $productSupplierUniqueExists = true;
  }

  if (!$productSupplierUniqueExists) {
    $pdo->exec("DELETE ps_old
      FROM product_suppliers ps_old
      INNER JOIN product_suppliers ps_newer
        ON ps_old.product_id = ps_newer.product_id
       AND ps_old.supplier_id = ps_newer.supplier_id
       AND ps_old.id < ps_newer.id");

    $pdo->exec("ALTER TABLE product_suppliers
      ADD CONSTRAINT uq_product_supplier_link UNIQUE (product_id, supplier_id)");
  }

  $ready = true;
}

function normalize_margin_percent_value($raw): ?string {
  $value = trim((string)$raw);
  if ($value === '') {
    $value = '0';
  }

  if (!preg_match('/^\d{1,3}(?:[\.,]\d{1,2})?$/', $value)) {
    return null;
  }

  $normalized = (float)str_replace(',', '.', $value);
  if ($normalized < 0 || $normalized > 999.99) {
    return null;
  }

  return number_format($normalized, 2, '.', '');
}

function normalize_site_margin_percent_value($raw): ?string {
  $value = trim((string)$raw);
  if ($value === '') {
    $value = '0';
  }

  if (!preg_match('/^-?\d{1,3}(?:[\.,]\d{1,2})?$/', $value)) {
    return null;
  }

  $normalized = (float)str_replace(',', '.', $value);
  if ($normalized < -100 || $normalized > 999.99) {
    return null;
  }

  return number_format($normalized, 2, '.', '');
}

function ensure_sites_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();

  $pdo->exec("CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    margin_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sites_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $site_columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM sites");
  foreach ($st->fetchAll() as $row) {
    $site_columns[(string)$row['Field']] = true;
  }

  if (!isset($site_columns['is_visible'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");
  }

  $ready = true;
}

function ensure_brands_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  $pdo = db();

  $pdo->exec("CREATE TABLE IF NOT EXISTS brands (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_brands_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $columns = [];
  $st = $pdo->query("SHOW COLUMNS FROM products");
  foreach ($st->fetchAll() as $row) {
    $columns[(string)$row['Field']] = true;
  }

  if (!isset($columns['brand_id'])) {
    $pdo->exec("ALTER TABLE products ADD COLUMN brand_id INT UNSIGNED NULL AFTER brand");
  }

  $pdo->exec("INSERT IGNORE INTO brands(name)
    SELECT DISTINCT TRIM(brand)
    FROM products
    WHERE TRIM(COALESCE(brand, '')) <> ''");

  $pdo->exec("UPDATE products p
    INNER JOIN brands b ON b.name = TRIM(p.brand)
    SET p.brand_id = b.id
    WHERE p.brand_id IS NULL
      AND TRIM(COALESCE(p.brand, '')) <> ''");

  $indexExists = false;
  $st = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_brand_id'");
  foreach ($st->fetchAll() as $row) {
    $indexExists = true;
    break;
  }
  if (!$indexExists) {
    $pdo->exec("ALTER TABLE products ADD KEY idx_products_brand_id (brand_id)");
  }

  $fkExists = false;
  $st = $pdo->prepare("SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'brand_id'
      AND REFERENCED_TABLE_NAME = 'brands'");
  $st->execute();
  if ($st->fetch()) {
    $fkExists = true;
  }

  if (!$fkExists) {
    $pdo->exec("ALTER TABLE products
      ADD CONSTRAINT fk_products_brand
      FOREIGN KEY (brand_id) REFERENCES brands(id)
      ON DELETE SET NULL");
  }

  $ready = true;
}

function fetch_brands(): array {
  ensure_brands_schema();
  $st = db()->query("SELECT id, name FROM brands ORDER BY name ASC");
  return $st->fetchAll();
}

function resolve_brand_id(string $brandName): ?int {
  ensure_brands_schema();
  $name = trim($brandName);
  if ($name === '') {
    return null;
  }

  $st = db()->prepare("INSERT IGNORE INTO brands(name) VALUES(?)");
  $st->execute([$name]);

  $st = db()->prepare("SELECT id FROM brands WHERE name = ? LIMIT 1");
  $st->execute([$name]);
  $brandId = $st->fetchColumn();
  if ($brandId === false) {
    return null;
  }

  return (int)$brandId;
}
