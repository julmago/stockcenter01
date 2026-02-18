<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');

function ml_webhook_ack(array $payload = ['ok' => true], int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
  }
}

function ml_webhook_fail(string $error, int $status = 422): void {
  http_response_code($status);
  echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  ml_webhook_fail('Método inválido.', 405);
}

ensure_sites_schema();
ensure_stock_schema();
ensure_stock_sync_schema();

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || trim($rawBody) === '') {
  ml_webhook_fail('Body vacío.');
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
  ml_webhook_fail('JSON inválido.');
}

$topic = strtolower(trim((string)($data['topic'] ?? '')));
$resource = trim((string)($data['resource'] ?? ''));
$mlUserId = trim((string)($data['user_id'] ?? ''));

if ($topic === '' || $resource === '' || $mlUserId === '') {
  ml_webhook_fail('Payload incompleto.');
}

$site = stock_sync_ml_find_site_by_user_id($mlUserId);
if (!$site) {
  ml_webhook_fail('No existe sitio ML activo para user_id.', 404);
}
$siteId = (int)($site['id'] ?? 0);
if ($siteId <= 0) {
  ml_webhook_fail('Sitio inválido.', 404);
}

ml_webhook_ack(['ok' => true, 'accepted' => true, 'topic' => $topic, 'site_id' => $siteId], 200);

if ($topic !== 'items') {
  stock_sync_log('ML webhook recibido (ignorado topic no-items)', ['topic' => $topic, 'site_id' => $siteId, 'resource' => $resource]);
  exit;
}

$itemId = stock_sync_ml_extract_item_id_from_resource($resource);
if ($itemId === null || $itemId === '') {
  stock_sync_log('ML webhook sin item_id válido', ['site_id' => $siteId, 'resource' => $resource]);
  exit;
}

$accessToken = trim((string)($site['ml_access_token'] ?? ''));
if ($accessToken === '') {
  stock_sync_log('ML webhook sin token de acceso', ['site_id' => $siteId, 'item_id' => $itemId]);
  exit;
}

$itemResponse = stock_sync_ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($itemId), $accessToken);
if ((int)$itemResponse['code'] < 200 || (int)$itemResponse['code'] >= 300) {
  stock_sync_log('ML webhook fallo al consultar item', ['site_id' => $siteId, 'item_id' => $itemId, 'http_code' => $itemResponse['code']]);
  exit;
}

$item = $itemResponse['json'];
if (!is_array($item)) {
  $item = [];
}

$rows = [];
$variations = $item['variations'] ?? [];
if (is_array($variations) && count($variations) > 0) {
  foreach ($variations as $variation) {
    if (!is_array($variation)) {
      continue;
    }
    $variationId = trim((string)($variation['id'] ?? ''));
    if ($variationId === '') {
      continue;
    }
    $rows[] = ['item_id' => $itemId, 'variation_id' => $variationId, 'qty' => (int)($variation['available_quantity'] ?? 0)];
  }
} else {
  $rows[] = ['item_id' => $itemId, 'variation_id' => null, 'qty' => (int)($item['available_quantity'] ?? 0)];
}

$pdo = db();
foreach ($rows as $row) {
  $variationId = $row['variation_id'] !== null ? trim((string)$row['variation_id']) : null;
  if ($variationId === '') {
    $variationId = null;
  }

  $mapSt = $pdo->prepare('SELECT spm.product_id, p.sku FROM site_product_map spm INNER JOIN products p ON p.id = spm.product_id WHERE spm.site_id = ? AND spm.ml_item_id = ? AND ((? IS NULL AND (spm.ml_variation_id IS NULL OR spm.ml_variation_id = "")) OR spm.ml_variation_id = ?) LIMIT 1');
  $mapSt->execute([$siteId, $itemId, $variationId, $variationId]);
  $map = $mapSt->fetch();
  if (!$map) {
    stock_sync_log('ML webhook sin vínculo en TSWork', ['site_id' => $siteId, 'item_id' => $itemId, 'variation_id' => $variationId]);
    continue;
  }

  $productId = (int)($map['product_id'] ?? 0);
  $sku = trim((string)($map['sku'] ?? ''));
  if ($productId <= 0 || $sku === '') {
    continue;
  }

  $qty = (int)$row['qty'];

  if (stock_sync_ml_recent_push_matches_qty($productId, $siteId, $qty, 20)) {
    stock_sync_log('ML webhook anti-loop: ignorado por push reciente', ['site_id' => $siteId, 'product_id' => $productId, 'qty' => $qty]);
    continue;
  }

  $eventId = 'ml-webhook-' . sha1($siteId . '|' . $itemId . '|' . ($variationId ?? '0') . '|' . $qty . '|' . gmdate('YmdHi'));
  $note = sprintf(
    'sync_pull_ml site_id=%d item_id=%s variation_id=%s qty=%d resource=%s',
    $siteId,
    $itemId,
    $variationId ?? '-',
    $qty,
    $resource
  );

  set_stock($productId, $qty, $note, 0, 'mercadolibre', $siteId, $eventId, 'sync_pull_ml');
  stock_sync_write_log($productId, $siteId, 'sync_pull_ml', [
    'connector' => 'mercadolibre',
    'source_site_id' => $siteId,
    'item_id' => $itemId,
    'variation_id' => $variationId,
    'qty' => $qty,
    'resource' => $resource,
  ]);

  sync_push_stock_to_sites($sku, $qty, $siteId, $productId);
}

exit;
