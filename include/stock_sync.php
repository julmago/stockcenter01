<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/../includes/integrations/MercadoLibreAdapter.php';

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
    $pdo->exec("ALTER TABLE sites ADD COLUMN sync_stock_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER conn_enabled");
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
    ml_item_id VARCHAR(120) NULL,
    ml_variation_id VARCHAR(120) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_site_product_remote (site_id, remote_id, remote_variant_id),
    UNIQUE KEY uq_site_product_local (site_id, product_id),
    KEY idx_site_product_map_product (product_id),
    CONSTRAINT fk_site_product_map_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_site_product_map_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $mapColumns = [];
  $st = $pdo->query('SHOW COLUMNS FROM site_product_map');
  foreach ($st->fetchAll() as $row) {
    $mapColumns[(string)$row['Field']] = true;
  }
  if (!isset($mapColumns['ml_item_id'])) {
    $pdo->exec("ALTER TABLE site_product_map ADD COLUMN ml_item_id VARCHAR(120) NULL AFTER remote_sku");
  }
  if (!isset($mapColumns['ml_variation_id'])) {
    $pdo->exec("ALTER TABLE site_product_map ADD COLUMN ml_variation_id VARCHAR(120) NULL AFTER ml_item_id");
  }

  $pdo->exec("UPDATE site_product_map spm
    INNER JOIN sites s ON s.id = spm.site_id
    SET
      spm.ml_item_id = COALESCE(NULLIF(spm.ml_item_id, ''), spm.remote_id),
      spm.ml_variation_id = COALESCE(NULLIF(spm.ml_variation_id, ''), spm.remote_variant_id)
    WHERE LOWER(s.conn_type) = 'mercadolibre'");

  $pdo->exec("CREATE TABLE IF NOT EXISTS stock_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    detail TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stock_logs_product_created (product_id, created_at),
    KEY idx_stock_logs_site_created (site_id, created_at),
    CONSTRAINT fk_stock_logs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_logs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
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

  $sql = "SELECT s.id, s.name, s.is_active, s.conn_type, s.conn_enabled, s.sync_stock_enabled, s.last_sync_at,
      sc.channel_type, sc.enabled AS connection_enabled, sc.ps_base_url, sc.ps_api_key, sc.ps_shop_id,
      sc.ml_client_id, sc.ml_client_secret, sc.ml_access_token, sc.ml_refresh_token, sc.ml_user_id, sc.ml_status
    FROM sites s
    LEFT JOIN site_connections sc ON sc.site_id = s.id
    WHERE s.is_active = 1";

  return db()->query($sql)->fetchAll();
}

function stock_sync_ml_http_get(string $url, string $accessToken): array {
  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('No se pudo inicializar cURL para MercadoLibre.');
  }

  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPGET, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $accessToken,
  ]);

  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) {
    throw new RuntimeException('Error cURL en MercadoLibre: ' . $err);
  }

  $json = json_decode((string)$resp, true);
  if (!is_array($json)) {
    $json = [];
  }

  return ['code' => $code, 'json' => $json];
}

function stock_sync_ml_extract_sku(array $item, string $defaultSku): string {
  $candidate = trim((string)($item['seller_custom_field'] ?? ''));
  if ($candidate !== '') {
    return $candidate;
  }
  $attributes = $item['attributes'] ?? [];
  if (is_array($attributes)) {
    foreach ($attributes as $attribute) {
      if (!is_array($attribute)) {
        continue;
      }
      $id = strtoupper(trim((string)($attribute['id'] ?? '')));
      if ($id !== 'SELLER_SKU') {
        continue;
      }
      $value = trim((string)($attribute['value_name'] ?? $attribute['value_id'] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
  }

  return $defaultSku;
}

function stock_sync_ml_variation_sku(array $variation): string {
  $candidate = trim((string)($variation['seller_custom_field'] ?? ''));
  if ($candidate !== '') {
    return $candidate;
  }
  $attributes = $variation['attributes'] ?? [];
  if (is_array($attributes)) {
    foreach ($attributes as $attribute) {
      if (!is_array($attribute)) {
        continue;
      }
      $id = strtoupper(trim((string)($attribute['id'] ?? '')));
      if ($id !== 'SELLER_SKU') {
        continue;
      }
      $value = trim((string)($attribute['value_name'] ?? $attribute['value_id'] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
  }

  return '';
}

function stock_sync_ml_ensure_user_id(PDO $pdo, int $siteId, array $site): string {
  $mlUserId = trim((string)($site['ml_user_id'] ?? ''));
  if ($mlUserId !== '') {
    return $mlUserId;
  }

  $accessToken = trim((string)($site['ml_access_token'] ?? ''));
  if ($accessToken === '') {
    throw new RuntimeException('MercadoLibre sin token de acceso para buscar por SKU.');
  }

  $me = stock_sync_ml_http_get('https://api.mercadolibre.com/users/me', $accessToken);
  if ($me['code'] < 200 || $me['code'] >= 300) {
    throw new RuntimeException('No se pudo consultar usuario de MercadoLibre (HTTP ' . $me['code'] . ').');
  }
  $mlUserId = trim((string)($me['json']['id'] ?? ''));
  if ($mlUserId === '') {
    throw new RuntimeException('MercadoLibre no devolvió user_id en /users/me.');
  }

  $up = $pdo->prepare('UPDATE site_connections SET ml_user_id = ?, updated_at = NOW() WHERE site_id = ?');
  $up->execute([$mlUserId, $siteId]);
  return $mlUserId;
}

function stock_sync_ml_search_by_sku(PDO $pdo, array $site, int $siteId, string $sku): array {
  $accessToken = trim((string)($site['ml_access_token'] ?? ''));
  if ($accessToken === '') {
    throw new RuntimeException('MercadoLibre sin token de acceso para buscar por SKU.');
  }

  $mlUserId = stock_sync_ml_ensure_user_id($pdo, $siteId, $site);
  $query = http_build_query(['seller_sku' => $sku], '', '&', PHP_QUERY_RFC3986);
  $search = stock_sync_ml_http_get('https://api.mercadolibre.com/users/' . rawurlencode($mlUserId) . '/items/search?' . $query, $accessToken);
  if ($search['code'] < 200 || $search['code'] >= 300) {
    throw new RuntimeException('No se pudo buscar SKU en MercadoLibre (HTTP ' . $search['code'] . ').');
  }

  $itemIds = $search['json']['results'] ?? [];
  if (!is_array($itemIds)) {
    $itemIds = [];
  }

  if (count($itemIds) === 0) {
    $fallbackQuery = http_build_query(['q' => $sku], '', '&', PHP_QUERY_RFC3986);
    $fallbackSearch = stock_sync_ml_http_get('https://api.mercadolibre.com/users/' . rawurlencode($mlUserId) . '/items/search?' . $fallbackQuery, $accessToken);
    if ($fallbackSearch['code'] < 200 || $fallbackSearch['code'] >= 300) {
      throw new RuntimeException('No se pudo buscar SKU en MercadoLibre por texto (HTTP ' . $fallbackSearch['code'] . ').');
    }

    $fallbackIds = $fallbackSearch['json']['results'] ?? [];
    if (!is_array($fallbackIds)) {
      $fallbackIds = [];
    }

    $matchedFallback = [];
    foreach ($fallbackIds as $fallbackItemId) {
      $fallbackItemId = trim((string)$fallbackItemId);
      if ($fallbackItemId === '') {
        continue;
      }
      $item = stock_sync_ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($fallbackItemId), $accessToken);
      if ($item['code'] < 200 || $item['code'] >= 300) {
        continue;
      }
      $itemJson = $item['json'];
      $sellerCustomField = trim((string)($itemJson['seller_custom_field'] ?? ''));
      if ($sellerCustomField === $sku) {
        $matchedFallback[] = $fallbackItemId;
      }
    }
    $itemIds = $matchedFallback;
  }

  $itemIds = array_values(array_unique(array_map(static function ($itemId): string {
    return trim((string)$itemId);
  }, $itemIds)));

  $rows = [];
  foreach ($itemIds as $itemId) {
    if ($itemId === '') {
      continue;
    }
    $item = stock_sync_ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($itemId), $accessToken);
    if ($item['code'] < 200 || $item['code'] >= 300) {
      continue;
    }

    $itemJson = $item['json'];
    $rows[] = [
      'item_id' => $itemId,
      'variation_id' => '',
      'sku' => stock_sync_ml_extract_sku($itemJson, $sku),
      'title' => trim((string)($itemJson['title'] ?? '')),
      'has_variations' => !empty($itemJson['variations']) && is_array($itemJson['variations']),
    ];

    $variations = $itemJson['variations'] ?? [];
    if (!is_array($variations)) {
      continue;
    }

    foreach ($variations as $variation) {
      if (!is_array($variation)) {
        continue;
      }
      $rows[] = [
        'item_id' => $itemId,
        'variation_id' => trim((string)($variation['id'] ?? '')),
        'sku' => stock_sync_ml_variation_sku($variation),
        'title' => trim((string)($itemJson['title'] ?? '')),
        'has_variations' => true,
      ];
    }
  }

  return $rows;
}

function stock_sync_ml_save_mapping(PDO $pdo, int $siteId, int $productId, string $sku, string $itemId, ?string $variationId): void {
  $variationValue = $variationId !== null && trim($variationId) !== '' ? trim($variationId) : null;
  $up = $pdo->prepare("INSERT INTO site_product_map(site_id, product_id, remote_id, remote_variant_id, remote_sku, ml_item_id, ml_variation_id, updated_at)
    VALUES(?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
      remote_id = VALUES(remote_id),
      remote_variant_id = VALUES(remote_variant_id),
      remote_sku = VALUES(remote_sku),
      ml_item_id = VALUES(ml_item_id),
      ml_variation_id = VALUES(ml_variation_id),
      updated_at = NOW()");
  $up->execute([$siteId, $productId, $itemId, $variationValue, $sku, $itemId, $variationValue]);
}

function stock_sync_ml_resolve_mapping(PDO $pdo, array $site, int $siteId, int $productId, string $sku): array {
  $rows = stock_sync_ml_search_by_sku($pdo, $site, $siteId, $sku);
  if (count($rows) === 0) {
    throw new RuntimeException('No se puede sincronizar a MercadoLibre: SKU no encontrado para vinculación automática.');
  }

  $itemIds = array_values(array_unique(array_map(static function (array $row): string {
    return (string)$row['item_id'];
  }, $rows)));

  if (count($itemIds) > 1) {
    $lines = [];
    foreach ($rows as $row) {
      $lines[] = 'Item ' . (string)$row['item_id'] . ' | Variante ' . ((string)$row['variation_id'] !== '' ? (string)$row['variation_id'] : '—') . ' | SKU ' . ((string)$row['sku'] !== '' ? (string)$row['sku'] : '—') . ' | Título ' . ((string)$row['title'] !== '' ? (string)$row['title'] : '—');
    }
    throw new RuntimeException('SKU duplicado en MercadoLibre. Requiere vinculación manual. Resultados: ' . implode(' || ', $lines));
  }

  $itemId = (string)$itemIds[0];
  $itemHasVariations = false;
  $variationCandidates = [];
  foreach ($rows as $row) {
    if ((string)$row['item_id'] !== $itemId) {
      continue;
    }
    if ((bool)($row['has_variations'] ?? false)) {
      $itemHasVariations = true;
    }
    $variationId = trim((string)($row['variation_id'] ?? ''));
    if ($variationId === '') {
      continue;
    }
    $rowSku = trim((string)($row['sku'] ?? ''));
    if ($rowSku === '' || $rowSku === $sku) {
      $variationCandidates[$variationId] = true;
    }
  }

  $variationId = null;
  $candidateIds = array_keys($variationCandidates);
  if (count($candidateIds) === 1) {
    $variationId = (string)$candidateIds[0];
  } elseif (count($candidateIds) > 1) {
    throw new RuntimeException('El item de MercadoLibre tiene múltiples variantes para el SKU y requiere vinculación manual.');
  } elseif ($itemHasVariations) {
    throw new RuntimeException('El item de MercadoLibre tiene variantes y no se pudo determinar la variante por SKU. Requiere vinculación manual.');
  }

  stock_sync_ml_save_mapping($pdo, $siteId, $productId, $sku, $itemId, $variationId);
  return ['ml_item_id' => $itemId, 'ml_variation_id' => $variationId, 'resolved' => true];
}

function enqueue_stock_push_jobs(int $productId, int $qty, string $origin, ?int $sourceSiteId = null, ?string $eventId = null): int {
  ensure_stock_sync_schema();

  $origin = stock_sync_normalize_origin($origin);
  if ($origin !== 'tswork') {
    return 0;
  }
  $sites = stock_sync_active_sites();
  $pdo = db();
  $created = 0;

  foreach ($sites as $site) {
    $siteId = (int)$site['id'];
    $connEnabled = (int)($site['conn_enabled'] ?? 0) === 1 || (int)($site['connection_enabled'] ?? 0) === 1;
    $syncEnabled = (int)($site['sync_stock_enabled'] ?? 0) === 1;
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

function stock_sync_log(string $message, array $context = []): void {
  $chunks = [];
  foreach ($context as $key => $value) {
    if (is_scalar($value) || $value === null) {
      $chunks[] = $key . '=' . (string)$value;
    } else {
      $chunks[] = $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE);
    }
  }
  error_log('[stock_sync] ' . $message . (count($chunks) ? ' | ' . implode(' ', $chunks) : ''));
}

function stock_sync_request_prestashop(array $site, string $method, string $path, ?string $body = null, array $extraHeaders = []): array {
  $baseUrl = rtrim((string)($site['ps_base_url'] ?? ''), '/');
  $apiKey = trim((string)($site['ps_api_key'] ?? ''));
  if ($baseUrl === '' || $apiKey === '') {
    throw new RuntimeException('Configuración incompleta de PrestaShop.');
  }

  $url = $baseUrl . $path;
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
  curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':');
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

  $headers = ['Accept: application/xml'];
  if ($body !== null) {
    $headers[] = 'Content-Type: application/xml; charset=utf-8';
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }
  foreach ($extraHeaders as $header) {
    if (is_string($header) && trim($header) !== '') {
      $headers[] = $header;
    }
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  $response = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($response === false) {
    throw new RuntimeException('cURL error: ' . $curlError);
  }

  if (!in_array($httpCode, [200, 201], true)) {
    stock_sync_log('Prestashop HTTP error response', [
      'method' => strtoupper($method),
      'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
      'http_code' => $httpCode,
      'response_body' => (string)$response,
      'request_xml' => $body,
    ]);
  }

  return [
    'http_code' => $httpCode,
    'body' => (string)$response,
    'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
  ];
}

function stock_sync_write_log(int $productId, ?int $siteId, string $action, array $detail = []): void {
  $action = trim($action);
  if ($action === '') {
    $action = 'sync_push';
  }

  try {
    $payload = count($detail) > 0 ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null;
    $st = db()->prepare('INSERT INTO stock_logs(product_id, site_id, action, detail, created_at) VALUES(?, ?, ?, ?, NOW())');
    $st->execute([$productId, $siteId, mb_substr($action, 0, 50), $payload]);
  } catch (Throwable $e) {
    stock_sync_log('stock_logs insert error', ['product_id' => $productId, 'site_id' => $siteId, 'action' => $action, 'error' => $e->getMessage()]);
  }
}


function sync_push_stock_to_sites(string $sku, int $newQty, ?int $excludeSiteId = null, ?int $productId = null): array {
  if ($productId === null || $productId <= 0) {
    $st = db()->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
    $st->execute([$sku]);
    $row = $st->fetch();
    $productId = (int)($row['id'] ?? 0);
  }

  if ($productId <= 0) {
    return [];
  }

  return sync_push_stock_to_sites_by_product($productId, $sku, $newQty, $excludeSiteId);
}

function sync_push_stock_to_sites_by_product(int $productId, string $sku, int $newQty, ?int $excludeSiteId = null): array {
  ensure_stock_sync_schema();

  $sites = stock_sync_active_sites();
  $pdo = db();
  $pushStatus = [];

  foreach ($sites as $site) {
    $siteId = (int)($site['id'] ?? 0);
    if ($excludeSiteId !== null && $siteId === $excludeSiteId) {
      continue;
    }

    $connEnabled = (int)($site['conn_enabled'] ?? 0) === 1 || (int)($site['connection_enabled'] ?? 0) === 1;
    $syncEnabled = (int)($site['sync_stock_enabled'] ?? 0) === 1;
    if (!$connEnabled || !$syncEnabled) {
      continue;
    }

    $connType = stock_sync_conn_type($site);
    if (!in_array($connType, ['prestashop', 'mercadolibre'], true)) {
      continue;
    }

    if ($connType === 'prestashop') {
      $pushResult = sync_stock_to_prestashop_with_result($site, $sku, $newQty);
      $pushStatus[] = [
        'site_id' => $siteId,
        'ok' => (bool)($pushResult['ok'] ?? false),
        'error' => (string)($pushResult['error'] ?? ''),
      ];
      continue;
    }

    if (!stock_sync_site_has_credentials($site)) {
      $err = 'MercadoLibre sin credenciales válidas.';
      stock_sync_write_log($productId, $siteId, 'sync_push', [
        'connector' => 'mercadolibre',
        'sku' => $sku,
        'qty' => $newQty,
        'ok' => false,
        'error' => $err,
      ]);
      $pushStatus[] = ['site_id' => $siteId, 'ok' => false, 'error' => $err];
      continue;
    }

    $map = stock_sync_load_ml_mapping($pdo, $siteId, $productId, $sku);

    $mlItemId = trim((string)($map['ml_item_id'] ?? ''));
    if ($mlItemId === '') {
      $mlItemId = trim((string)($map['remote_id'] ?? ''));
    }
    $mlVariationId = trim((string)($map['ml_variation_id'] ?? ''));
    if ($mlVariationId === '') {
      $mlVariationId = trim((string)($map['remote_variant_id'] ?? ''));
    }
    if ($mlVariationId === '') {
      $mlVariationId = null;
    }

    if ($mlItemId === '') {
      try {
        $resolvedMap = stock_sync_ml_resolve_mapping($pdo, $site, $siteId, $productId, $sku);
        $mlItemId = trim((string)($resolvedMap['ml_item_id'] ?? ''));
        $resolvedVariation = $resolvedMap['ml_variation_id'] ?? null;
        $mlVariationId = $resolvedVariation !== null && trim((string)$resolvedVariation) !== '' ? trim((string)$resolvedVariation) : null;
      } catch (Throwable $e) {
        $err = $e->getMessage();
        stock_sync_write_log($productId, $siteId, 'sync_push', [
          'connector' => 'mercadolibre',
          'sku' => $sku,
          'qty' => $newQty,
          'ok' => false,
          'error' => $err,
        ]);
        $pushStatus[] = ['site_id' => $siteId, 'ok' => false, 'error' => $err];
        continue;
      }
    }

    try {
      $response = MercadoLibreAdapter::updateStockWithResponse((string)$site['ml_access_token'], $mlItemId, $mlVariationId, $newQty);
      $ok = (int)($response['code'] ?? 0) >= 200 && (int)($response['code'] ?? 0) < 300;
      $body = (string)($response['body'] ?? '');

      stock_sync_write_log($productId, $siteId, 'sync_push', [
        'connector' => 'mercadolibre',
        'sku' => $sku,
        'item_id' => $mlItemId,
        'variation_id' => $mlVariationId,
        'qty' => $newQty,
        'ok' => $ok,
        'http_status' => (int)($response['code'] ?? 0),
        'http_body' => mb_substr($body, 0, 2000),
      ]);

      if ($ok) {
        $pushStatus[] = ['site_id' => $siteId, 'ok' => true, 'error' => ''];
      } else {
        $pushStatus[] = ['site_id' => $siteId, 'ok' => false, 'error' => 'Error al actualizar stock en MercadoLibre (HTTP ' . (int)$response['code'] . ').'];
      }
    } catch (Throwable $e) {
      stock_sync_write_log($productId, $siteId, 'sync_push', [
        'connector' => 'mercadolibre',
        'sku' => $sku,
        'item_id' => $mlItemId,
        'variation_id' => $mlVariationId,
        'qty' => $newQty,
        'ok' => false,
        'error' => $e->getMessage(),
      ]);
      $pushStatus[] = ['site_id' => $siteId, 'ok' => false, 'error' => $e->getMessage()];
    }
  }

  return $pushStatus;
}

function stock_sync_load_ml_mapping(PDO $pdo, int $siteId, int $productId, string $sku): ?array {
  $mapSt = $pdo->prepare('SELECT ml_item_id, ml_variation_id, remote_id, remote_variant_id FROM site_product_map WHERE site_id = ? AND product_id = ? LIMIT 1');
  $mapSt->execute([$siteId, $productId]);
  $map = $mapSt->fetch();
  if ($map) {
    return $map;
  }

  $sku = trim($sku);
  if ($sku === '') {
    return null;
  }

  $skuSt = $pdo->prepare('SELECT ml_item_id, ml_variation_id, remote_id, remote_variant_id
    FROM site_product_map
    WHERE site_id = ? AND remote_sku = ?
    ORDER BY id DESC
    LIMIT 1');
  $skuSt->execute([$siteId, $sku]);

  $skuMap = $skuSt->fetch();
  return $skuMap ?: null;
}

function stock_sync_resolve_shop_id(array $site): int {
  $shopId = (int)($site['ps_shop_id'] ?? 0);
  return $shopId > 0 ? $shopId : 1;
}

function sync_stock_to_prestashop_with_result(array $site, string $sku, int $newQty): array {
  $siteId = (int)($site['id'] ?? 0);
  try {
    $skuEncoded = rawurlencode($sku);
    $productResponse = stock_sync_request_prestashop($site, 'GET', '/api/products?filter[reference]=' . $skuEncoded . '&display=[id]');
    stock_sync_log('Prestashop lookup product', ['site_id' => $siteId, 'sku' => $sku, 'qty' => $newQty, 'http_code' => $productResponse['http_code'], 'body' => mb_substr($productResponse['body'], 0, 500)]);
    if ($productResponse['http_code'] < 200 || $productResponse['http_code'] >= 300) {
      return ['ok' => false, 'error' => 'Error consultando producto por SKU (HTTP ' . $productResponse['http_code'] . ').'];
    }

    $xml = @simplexml_load_string($productResponse['body']);
    $productId = 0;
    if ($xml && isset($xml->products->product)) {
      foreach ($xml->products->product as $productNode) {
        $productId = (int)($productNode->id ?? 0);
        if ($productId > 0) {
          break;
        }
      }
    }
    if ($productId <= 0) {
      stock_sync_log('Prestashop SKU not found', ['site_id' => $siteId, 'sku' => $sku, 'qty' => $newQty]);
      return ['ok' => false, 'error' => 'SKU no encontrado en PrestaShop.'];
    }

    $shopId = stock_sync_resolve_shop_id($site);
    $stockPath = '/api/stock_availables?filter[id_product]=' . $productId
      . '&filter[id_product_attribute]=0'
      . '&filter[id_shop]=' . $shopId
      . '&display=[id,id_product,id_product_attribute,id_shop,id_shop_group,quantity,depends_on_stock,out_of_stock]';
    $stockResponse = stock_sync_request_prestashop($site, 'GET', $stockPath);
    stock_sync_log('Prestashop lookup stock_available', ['site_id' => $siteId, 'sku' => $sku, 'qty' => $newQty, 'shop_id' => $shopId, 'url' => $stockResponse['url'] ?? '', 'http_code' => $stockResponse['http_code'], 'body' => mb_substr($stockResponse['body'], 0, 500)]);
    if ($stockResponse['http_code'] < 200 || $stockResponse['http_code'] >= 300) {
      return ['ok' => false, 'error' => 'Error consultando stock_available (HTTP ' . $stockResponse['http_code'] . ').'];
    }

    $stockXml = @simplexml_load_string($stockResponse['body']);
    $stockAvailableId = 0;
    if ($stockXml && isset($stockXml->stock_availables->stock_available)) {
      foreach ($stockXml->stock_availables->stock_available as $stockNode) {
        $stockAvailableId = (int)($stockNode->id ?? 0);
        if ($stockAvailableId > 0) {
          break;
        }
      }
    }

    if ($stockAvailableId <= 0) {
      stock_sync_log('Prestashop stock_available not found', ['site_id' => $siteId, 'sku' => $sku, 'product_id' => $productId]);
      return ['ok' => false, 'error' => 'No se encontró stock_available para el producto.'];
    }

    $templateResponse = stock_sync_request_prestashop($site, 'GET', '/api/stock_availables/' . $stockAvailableId);
    stock_sync_log('Prestashop get stock_available template', [
      'site_id' => $siteId,
      'sku' => $sku,
      'id_stock_available' => $stockAvailableId,
      'url' => $templateResponse['url'] ?? '',
      'http_code' => $templateResponse['http_code'],
      'body' => mb_substr($templateResponse['body'], 0, 500),
    ]);
    if ($templateResponse['http_code'] < 200 || $templateResponse['http_code'] >= 300) {
      return ['ok' => false, 'error' => 'Error leyendo stock_available #' . $stockAvailableId . ' (HTTP ' . $templateResponse['http_code'] . ').'];
    }

    $templateXml = @simplexml_load_string($templateResponse['body']);
    if (!$templateXml || !isset($templateXml->stock_available)) {
      return ['ok' => false, 'error' => 'Respuesta XML inválida al leer stock_available #' . $stockAvailableId . '.'];
    }

    $dependsOnStock = (int)($templateXml->stock_available->depends_on_stock ?? 0);
    if ($dependsOnStock === 1) {
      stock_sync_log('Prestashop stock depends_on_stock active', [
        'site_id' => $siteId,
        'sku' => $sku,
        'id_stock_available' => $stockAvailableId,
        'depends_on_stock' => $dependsOnStock,
      ]);
      return ['ok' => false, 'error' => 'No se puede actualizar porque depende de stock/stock avanzado.'];
    }

    $templateXml->stock_available->quantity = (string)$newQty;
    $payload = $templateXml->asXML();
    if ($payload === false) {
      return ['ok' => false, 'error' => 'No se pudo generar XML para actualizar stock_available #' . $stockAvailableId . '.'];
    }

    stock_sync_log('Prestashop update payload', [
      'site_id' => $siteId,
      'sku' => $sku,
      'id_stock_available' => $stockAvailableId,
      'request_xml' => $payload,
    ]);

    $updateResponse = stock_sync_request_prestashop(
      $site,
      'PUT',
      '/api/stock_availables/' . $stockAvailableId,
      $payload,
      [
        'X-TSWORK-SOURCE: tswork',
        'X-TSWORK-EVENT: tswork-' . sha1($siteId . '|' . $sku . '|' . $newQty . '|' . microtime(true)),
      ]
    );
    stock_sync_log('Prestashop update stock', [
      'site_id' => $siteId,
      'sku' => $sku,
      'qty' => $newQty,
      'id_stock_available' => $stockAvailableId,
      'url' => $updateResponse['url'] ?? '',
      'http_code' => $updateResponse['http_code'],
      'body' => mb_substr($updateResponse['body'], 0, 500),
    ]);

    if ($updateResponse['http_code'] >= 200 && $updateResponse['http_code'] < 300) {
      return ['ok' => true, 'error' => null];
    }
    return ['ok' => false, 'error' => 'Error actualizando stock en PrestaShop (HTTP ' . $updateResponse['http_code'] . ').'];
  } catch (Throwable $e) {
    stock_sync_log('Prestashop update error', ['site_id' => $siteId, 'sku' => $sku, 'qty' => $newQty, 'error' => $e->getMessage()]);
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function sync_stock_to_prestashop(array $site, string $sku, int $newQty): bool {
  $result = sync_stock_to_prestashop_with_result($site, $sku, $newQty);
  return (bool)($result['ok'] ?? false);
}

function pull_stock_from_prestashop(array $site, string $sku): ?int {
  $siteId = (int)($site['id'] ?? 0);
  try {
    $skuEncoded = rawurlencode($sku);
    $productResponse = stock_sync_request_prestashop($site, 'GET', '/api/products?filter[reference]=' . $skuEncoded . '&display=[id]');
    stock_sync_log('Prestashop pull lookup product', ['site_id' => $siteId, 'sku' => $sku, 'http_code' => $productResponse['http_code'], 'body' => mb_substr($productResponse['body'], 0, 500)]);
    if ($productResponse['http_code'] < 200 || $productResponse['http_code'] >= 300) {
      return null;
    }

    $xml = @simplexml_load_string($productResponse['body']);
    $productId = 0;
    if ($xml && isset($xml->products->product)) {
      foreach ($xml->products->product as $productNode) {
        $productId = (int)($productNode->id ?? 0);
        if ($productId > 0) {
          break;
        }
      }
    }
    if ($productId <= 0) {
      return null;
    }

    $stockPath = '/api/stock_availables?filter[id_product]=' . $productId . '&filter[id_product_attribute]=0&display=[id,quantity]';
    $stockResponse = stock_sync_request_prestashop($site, 'GET', $stockPath);
    stock_sync_log('Prestashop pull lookup stock_available', ['site_id' => $siteId, 'sku' => $sku, 'http_code' => $stockResponse['http_code'], 'body' => mb_substr($stockResponse['body'], 0, 500)]);
    if ($stockResponse['http_code'] < 200 || $stockResponse['http_code'] >= 300) {
      return null;
    }

    $stockXml = @simplexml_load_string($stockResponse['body']);
    if ($stockXml && isset($stockXml->stock_availables->stock_available)) {
      foreach ($stockXml->stock_availables->stock_available as $stockNode) {
        return (int)($stockNode->quantity ?? 0);
      }
    }
  } catch (Throwable $e) {
    stock_sync_log('Prestashop pull error', ['site_id' => $siteId, 'sku' => $sku, 'error' => $e->getMessage()]);
  }

  return null;
}

function get_prestashop_sync_sites(): array {
  $sites = stock_sync_active_sites();
  return array_values(array_filter($sites, static function (array $site): bool {
    $connEnabled = (int)($site['conn_enabled'] ?? 0) === 1 || (int)($site['connection_enabled'] ?? 0) === 1;
    return stock_sync_conn_type($site) === 'prestashop'
      && $connEnabled
      && (int)($site['sync_stock_enabled'] ?? 0) === 1
      && stock_sync_site_has_credentials($site);
  }));
}
