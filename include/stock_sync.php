<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/stock.php';

function ensure_stock_sync_schema(): void {
  static $ready = false;
  if ($ready) {
    return;
  }

  ensure_sites_schema();
  ensure_stock_schema();

  $pdo = db();

  $siteColumns = [];
  $st = $pdo->query('SHOW COLUMNS FROM sites');
  foreach ($st->fetchAll() as $row) {
    $siteColumns[(string)$row['Field']] = true;
  }

  if (!isset($siteColumns['conn_type'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN conn_type ENUM('none','prestashop','mercadolibre') NOT NULL DEFAULT 'none' AFTER channel_type");
  }
  if (!isset($siteColumns['conn_enabled'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN conn_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER conn_type");
  }
  if (!isset($siteColumns['sync_stock_enabled'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN sync_stock_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER conn_enabled");
  }
  if (!isset($siteColumns['last_sync_at'])) {
    $pdo->exec("ALTER TABLE sites ADD COLUMN last_sync_at DATETIME NULL AFTER sync_stock_enabled");
  }

  $movesColumns = [];
  $st = $pdo->query('SHOW COLUMNS FROM ts_stock_moves');
  foreach ($st->fetchAll() as $row) {
    $movesColumns[(string)$row['Field']] = true;
  }
  if (!isset($movesColumns['origin'])) {
    $pdo->exec("ALTER TABLE ts_stock_moves ADD COLUMN origin ENUM('tswork','prestashop','mercadolibre') NOT NULL DEFAULT 'tswork' AFTER reason");
  }
  if (!isset($movesColumns['event_id'])) {
    $pdo->exec("ALTER TABLE ts_stock_moves ADD COLUMN event_id VARCHAR(120) NULL AFTER origin");
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS site_product_map (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    remote_id VARCHAR(120) NOT NULL,
    remote_variant_id VARCHAR(120) NULL,
    remote_sku VARCHAR(120) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_site_product_remote (site_id, remote_id, remote_variant_id),
    UNIQUE KEY uq_site_product_local (site_id, product_id),
    KEY idx_site_product_map_product (product_id),
    CONSTRAINT fk_site_product_map_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_site_product_map_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS ts_sync_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    action VARCHAR(30) NOT NULL,
    payload_json TEXT NULL,
    payload_hash CHAR(64) NULL,
    origin ENUM('tswork','prestashop','mercadolibre') NOT NULL DEFAULT 'tswork',
    source_site_id INT UNSIGNED NULL,
    status ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ts_sync_jobs_status (status, created_at),
    KEY idx_ts_sync_jobs_site_product (site_id, product_id),
    KEY idx_ts_sync_jobs_payload_hash (payload_hash)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS ts_sync_locks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    origin ENUM('tswork','prestashop','mercadolibre') NOT NULL,
    event_key VARCHAR(190) NOT NULL,
    payload_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ts_sync_locks_event (site_id, product_id, origin, event_key),
    KEY idx_ts_sync_locks_hash (payload_hash)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $ready = true;
}

function stock_sync_normalize_origin(string $origin): string {
  $normalized = strtolower(trim($origin));
  if (!in_array($normalized, ['tswork', 'prestashop', 'mercadolibre'], true)) {
    return 'tswork';
  }
  return $normalized;
}

function stock_sync_conn_type(array $site): string {
  $connType = strtolower((string)($site['conn_type'] ?? ''));
  if ($connType === '' || $connType === 'none') {
    $channelType = strtolower((string)($site['channel_type'] ?? 'none'));
    if (in_array($channelType, ['prestashop', 'mercadolibre'], true)) {
      $connType = $channelType;
    }
  }
  if (!in_array($connType, ['prestashop', 'mercadolibre'], true)) {
    return 'none';
  }
  return $connType;
}

function stock_sync_site_has_credentials(array $site): bool {
  $connType = stock_sync_conn_type($site);
  if ($connType === 'prestashop') {
    return trim((string)($site['ps_base_url'] ?? '')) !== '' && trim((string)($site['ps_api_key'] ?? '')) !== '';
  }
  if ($connType === 'mercadolibre') {
    return trim((string)($site['ml_access_token'] ?? '')) !== '' || (trim((string)($site['ml_client_id'] ?? '')) !== '' && trim((string)($site['ml_client_secret'] ?? '')) !== '');
  }
  return false;
}

function stock_sync_active_sites(): array {
  ensure_stock_sync_schema();

  $sql = "SELECT s.id, s.is_active, s.conn_type, s.conn_enabled, s.sync_stock_enabled, s.last_sync_at,
      sc.channel_type, sc.enabled AS connection_enabled, sc.ps_base_url, sc.ps_api_key, sc.ps_shop_id,
      sc.ml_client_id, sc.ml_client_secret, sc.ml_access_token, sc.ml_refresh_token
    FROM sites s
    LEFT JOIN site_connections sc ON sc.site_id = s.id
    WHERE s.is_active = 1";

  return db()->query($sql)->fetchAll();
}

function enqueue_stock_push_jobs(int $productId, int $qty, string $origin, ?int $sourceSiteId = null, ?string $eventId = null): int {
  ensure_stock_sync_schema();

  $origin = stock_sync_normalize_origin($origin);
  $sites = stock_sync_active_sites();
  $pdo = db();
  $created = 0;

  foreach ($sites as $site) {
    $siteId = (int)$site['id'];
    $connEnabled = (int)($site['conn_enabled'] ?? 0) === 1 || (int)($site['connection_enabled'] ?? 0) === 1;
    $syncEnabled = (int)($site['sync_stock_enabled'] ?? 1) === 1;
    if (!$connEnabled || !$syncEnabled || !stock_sync_site_has_credentials($site)) {
      continue;
    }
    if ($sourceSiteId !== null && $siteId === $sourceSiteId) {
      continue;
    }

    $payload = [
      'qty' => $qty,
      'origin' => $origin,
      'event_id' => $eventId,
      'source_site_id' => $sourceSiteId,
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $payloadHash = hash('sha256', $siteId . '|' . $productId . '|push_stock|' . $payloadJson);

    $check = $pdo->prepare("SELECT id FROM ts_sync_jobs WHERE payload_hash = ? AND status IN ('pending','running') LIMIT 1");
    $check->execute([$payloadHash]);
    if ($check->fetch()) {
      continue;
    }

    $ins = $pdo->prepare("INSERT INTO ts_sync_jobs(site_id, product_id, action, payload_json, payload_hash, origin, source_site_id, status, attempts, created_at, updated_at)
      VALUES(?, ?, 'push_stock', ?, ?, ?, ?, 'pending', 0, NOW(), NOW())");
    $ins->execute([$siteId, $productId, $payloadJson, $payloadHash, $origin, $sourceSiteId]);
    $created++;
  }

  return $created;
}

function stock_sync_register_lock(int $siteId, int $productId, string $origin, string $eventKey, ?string $payloadHash = null): bool {
  ensure_stock_sync_schema();
  $origin = stock_sync_normalize_origin($origin);
  $eventKey = trim($eventKey);
  if ($eventKey === '') {
    return false;
  }

  try {
    $st = db()->prepare('INSERT INTO ts_sync_locks(site_id, product_id, origin, event_key, payload_hash, created_at) VALUES(?, ?, ?, ?, ?, NOW())');
    $st->execute([$siteId, $productId, $origin, $eventKey, $payloadHash]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}
