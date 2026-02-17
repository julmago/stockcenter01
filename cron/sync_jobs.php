<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/stock_sync.php';
require_once __DIR__ . '/../includes/integrations/PrestashopAdapter.php';
require_once __DIR__ . '/../includes/integrations/MercadoLibreAdapter.php';

ensure_stock_sync_schema();

$pdo = db();
$limit = 50;

$jobs = $pdo->query("SELECT id, site_id, product_id, payload_json, attempts FROM ts_sync_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT {$limit}")->fetchAll();

foreach ($jobs as $job) {
  $jobId = (int)$job['id'];
  $siteId = (int)$job['site_id'];
  $productId = (int)$job['product_id'];

  $pdo->prepare("UPDATE ts_sync_jobs SET status = 'running', attempts = attempts + 1, updated_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$jobId]);

  try {
    $payload = json_decode((string)$job['payload_json'], true);
    if (!is_array($payload) || !array_key_exists('qty', $payload)) {
      throw new RuntimeException('Payload inválido.');
    }

    $siteSt = $pdo->prepare("SELECT s.id, s.conn_type, s.conn_enabled, s.sync_stock_enabled, sc.channel_type, sc.enabled,
      sc.ps_base_url, sc.ps_api_key, sc.ml_access_token
      FROM sites s
      LEFT JOIN site_connections sc ON sc.site_id = s.id
      WHERE s.id = ? LIMIT 1");
    $siteSt->execute([$siteId]);
    $site = $siteSt->fetch();
    if (!$site) {
      throw new RuntimeException('Sitio inexistente.');
    }

    $mapSt = $pdo->prepare('SELECT remote_id, remote_variant_id FROM site_product_map WHERE site_id = ? AND product_id = ? LIMIT 1');
    $mapSt->execute([$siteId, $productId]);
    $map = $mapSt->fetch();
    if (!$map) {
      throw new RuntimeException('Falta mapping en site_product_map para site_id=' . $siteId . ' product_id=' . $productId);
    }

    $qty = (int)$payload['qty'];
    $connType = stock_sync_conn_type($site);
    if ($connType === 'prestashop') {
      PrestashopAdapter::updateStock((string)$site['ps_base_url'], (string)$site['ps_api_key'], (string)$map['remote_id'], $map['remote_variant_id'] !== null ? (string)$map['remote_variant_id'] : null, $qty);
    } elseif ($connType === 'mercadolibre') {
      MercadoLibreAdapter::updateStock((string)$site['ml_access_token'], (string)$map['remote_id'], $map['remote_variant_id'] !== null ? (string)$map['remote_variant_id'] : null, $qty);
    } else {
      throw new RuntimeException('Tipo de conexión no soportado para sync de stock.');
    }

    $pdo->prepare("UPDATE ts_sync_jobs SET status = 'done', last_error = NULL, updated_at = NOW() WHERE id = ?")->execute([$jobId]);
  } catch (Throwable $e) {
    $pdo->prepare("UPDATE ts_sync_jobs SET status = 'error', last_error = ?, updated_at = NOW() WHERE id = ?")
      ->execute([mb_substr($e->getMessage(), 0, 2000), $jobId]);
  }
}

echo json_encode(['ok' => true, 'processed' => count($jobs)], JSON_UNESCAPED_UNICODE) . PHP_EOL;
