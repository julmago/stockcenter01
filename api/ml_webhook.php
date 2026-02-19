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

function ml_webhook_extract_order_id_from_resource(string $resource): ?string {
  $resource = trim($resource);
  if ($resource === '') {
    return null;
  }
  if (preg_match('~/(?:orders|packs)/([0-9]+)~i', $resource, $m)) {
    return trim((string)$m[1]);
  }
  return null;
}

function ml_webhook_validate_secret(array $site, string $rawBody): bool {
  $secret = trim((string)($site['ml_notification_secret'] ?? ''));
  if ($secret === '') {
    return true;
  }

  $provided = trim((string)($_SERVER['HTTP_X_ML_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_X_MELI_SECRET'] ?? ''));
  if ($provided === '') {
    $provided = trim((string)($_GET['secret'] ?? ''));
  }

  $providedSignature = trim((string)($_SERVER['HTTP_X_ML_SIGNATURE'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
  if (str_starts_with($providedSignature, 'sha256=')) {
    $providedSignature = substr($providedSignature, 7);
  }

  if ($provided !== '' && hash_equals($secret, $provided)) {
    return true;
  }

  if ($providedSignature !== '') {
    $expected = hash_hmac('sha256', $rawBody, $secret);
    if (hash_equals($expected, $providedSignature)) {
      return true;
    }
  }

  return false;
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

if (!ml_webhook_validate_secret($site, $rawBody)) {
  ml_webhook_fail('Webhook no autorizado.', 401);
}

ml_webhook_ack(['ok' => true, 'accepted' => true, 'topic' => $topic, 'site_id' => $siteId], 200);

$accessToken = trim((string)($site['ml_access_token'] ?? ''));
if ($accessToken === '') {
  stock_sync_log('ML webhook sin token de acceso', ['site_id' => $siteId, 'topic' => $topic, 'resource' => $resource]);
  exit;
}

$rows = [];
if ($topic === 'items') {
  $itemId = stock_sync_ml_extract_item_id_from_resource($resource);
  if ($itemId === null || $itemId === '') {
    stock_sync_log('ML webhook items sin item_id válido', ['site_id' => $siteId, 'resource' => $resource]);
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
      $rows[] = ['topic' => $topic, 'resource' => $resource, 'item_id' => $itemId, 'variation_id' => $variationId, 'qty' => (int)($variation['available_quantity'] ?? 0)];
    }
  } else {
    $rows[] = ['topic' => $topic, 'resource' => $resource, 'item_id' => $itemId, 'variation_id' => null, 'qty' => (int)($item['available_quantity'] ?? 0)];
  }
} elseif ($topic === 'orders') {
  $orderId = ml_webhook_extract_order_id_from_resource($resource);
  if ($orderId === null || $orderId === '') {
    stock_sync_log('ML webhook orders sin order_id válido', ['site_id' => $siteId, 'resource' => $resource]);
    exit;
  }

  $orderResponse = stock_sync_ml_http_get('https://api.mercadolibre.com/orders/' . rawurlencode($orderId), $accessToken);
  if ((int)$orderResponse['code'] < 200 || (int)$orderResponse['code'] >= 300) {
    stock_sync_log('ML webhook fallo al consultar order', ['site_id' => $siteId, 'order_id' => $orderId, 'http_code' => $orderResponse['code']]);
    exit;
  }

  $order = $orderResponse['json'];
  if (!is_array($order)) {
    $order = [];
  }
  $orderItems = $order['order_items'] ?? [];
  if (!is_array($orderItems) || count($orderItems) === 0) {
    stock_sync_log('ML webhook order sin items', ['site_id' => $siteId, 'order_id' => $orderId]);
    exit;
  }

  foreach ($orderItems as $orderItemRow) {
    $orderItem = is_array($orderItemRow) ? (array)($orderItemRow['item'] ?? []) : [];
    $itemId = trim((string)($orderItem['id'] ?? ''));
    if ($itemId === '') {
      continue;
    }

    $variationId = trim((string)($orderItem['variation_id'] ?? ''));
    if ($variationId === '') {
      $variationId = null;
    }

    $itemResponse = stock_sync_ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($itemId), $accessToken);
    if ((int)$itemResponse['code'] < 200 || (int)$itemResponse['code'] >= 300) {
      stock_sync_log('ML webhook order fallo al consultar item', ['site_id' => $siteId, 'order_id' => $orderId, 'item_id' => $itemId, 'http_code' => $itemResponse['code']]);
      continue;
    }

    $item = $itemResponse['json'];
    if (!is_array($item)) {
      $item = [];
    }

    if ($variationId === null) {
      $rows[] = ['topic' => $topic, 'resource' => $resource, 'item_id' => $itemId, 'variation_id' => null, 'qty' => (int)($item['available_quantity'] ?? 0)];
      continue;
    }

    $variationQty = null;
    $variations = $item['variations'] ?? [];
    if (is_array($variations)) {
      foreach ($variations as $variation) {
        if (!is_array($variation)) {
          continue;
        }
        if (trim((string)($variation['id'] ?? '')) !== $variationId) {
          continue;
        }
        $variationQty = (int)($variation['available_quantity'] ?? 0);
        break;
      }
    }

    if ($variationQty === null) {
      $variationQty = (int)($item['available_quantity'] ?? 0);
    }

    $rows[] = ['topic' => $topic, 'resource' => $resource, 'item_id' => $itemId, 'variation_id' => $variationId, 'qty' => $variationQty];
  }
} else {
  stock_sync_log('ML webhook recibido (topic ignorado)', ['topic' => $topic, 'site_id' => $siteId, 'resource' => $resource]);
  exit;
}

if (count($rows) === 0) {
  stock_sync_log('ML webhook sin filas para procesar', ['site_id' => $siteId, 'topic' => $topic, 'resource' => $resource]);
  exit;
}

$pdo = db();
foreach ($rows as $row) {
  $itemId = trim((string)($row['item_id'] ?? ''));
  if ($itemId === '') {
    continue;
  }
  $variationId = $row['variation_id'] !== null ? trim((string)$row['variation_id']) : null;
  if ($variationId === '') {
    $variationId = null;
  }

  $mapSt = $pdo->prepare('SELECT spm.product_id, p.sku FROM site_product_map spm INNER JOIN products p ON p.id = spm.product_id WHERE spm.site_id = ? AND spm.ml_item_id = ? AND ((? IS NULL AND (spm.ml_variation_id IS NULL OR spm.ml_variation_id = "")) OR spm.ml_variation_id = ?) LIMIT 1');
  $mapSt->execute([$siteId, $itemId, $variationId, $variationId]);
  $map = $mapSt->fetch();

  if (!$map && $variationId !== null) {
    $fallbackMapSt = $pdo->prepare('SELECT spm.product_id, p.sku FROM site_product_map spm INNER JOIN products p ON p.id = spm.product_id WHERE spm.site_id = ? AND spm.ml_item_id = ? ORDER BY spm.id DESC LIMIT 1');
    $fallbackMapSt->execute([$siteId, $itemId]);
    $map = $fallbackMapSt->fetch();
  }

  if (!$map) {
    stock_sync_log('ML webhook sin vínculo en TSWork', ['site_id' => $siteId, 'item_id' => $itemId, 'variation_id' => $variationId, 'topic' => (string)$row['topic']]);
    continue;
  }

  $productId = (int)($map['product_id'] ?? 0);
  if ($productId <= 0) {
    continue;
  }

  $qty = (int)$row['qty'];
  $eventId = 'ml-webhook-' . sha1($siteId . '|' . $itemId . '|' . ($variationId ?? '0') . '|' . $qty . '|' . gmdate('YmdHi'));
  $topicValue = trim((string)($row['topic'] ?? $topic));
  $note = sprintf('Sync desde MercadoLibre | item_id=%s | variation_id=%s | topic=%s | resource=%s', $itemId, $variationId ?? '-', $topicValue, (string)$row['resource']);

  set_stock($productId, $qty, $note, 0, 'mercadolibre', $siteId, $eventId, 'sync_pull_ml');

  stock_sync_write_log($productId, $siteId, 'sync_pull_ml', [
    'connector' => 'mercadolibre',
    'source_site_id' => $siteId,
    'item_id' => $itemId,
    'variation_id' => $variationId,
    'qty' => $qty,
    'resource' => (string)$row['resource'],
    'topic' => $topicValue,
  ]);
}

exit;
