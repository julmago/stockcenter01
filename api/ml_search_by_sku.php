<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../include/stock_sync.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_sites_schema();
ensure_stock_sync_schema();

function ml_search_respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$siteId = (int)get('site_id', '0');
$sku = trim((string)get('sku', ''));
if ($siteId <= 0 || $sku === '') {
  ml_search_respond(['ok' => false, 'error' => 'Parámetros inválidos.'], 422);
}

$pdo = db();
$siteSt = $pdo->prepare("SELECT s.id, sc.ml_access_token
  FROM sites s
  LEFT JOIN site_connections sc ON sc.site_id = s.id
  WHERE s.id = ?
    AND (
      LOWER(COALESCE(s.conn_type, '')) = 'mercadolibre'
      OR UPPER(COALESCE(sc.channel_type, '')) = 'MERCADOLIBRE'
    )
  LIMIT 1");
$siteSt->execute([$siteId]);
$site = $siteSt->fetch();
if (!$site) {
  ml_search_respond(['ok' => false, 'error' => 'Sitio de MercadoLibre inválido.'], 404);
}

$accessToken = trim((string)($site['ml_access_token'] ?? ''));
if ($accessToken === '') {
  ml_search_respond(['ok' => false, 'error' => 'El sitio no tiene access_token de MercadoLibre.'], 422);
}

try {
  $me = stock_sync_ml_http_get('https://api.mercadolibre.com/users/me', $accessToken);
  if ($me['code'] < 200 || $me['code'] >= 300) {
    ml_search_respond(['ok' => false, 'error' => 'No se pudo consultar /users/me (HTTP ' . $me['code'] . ').'], 500);
  }
  $sellerId = trim((string)($me['json']['id'] ?? ''));
  if ($sellerId === '') {
    ml_search_respond(['ok' => false, 'error' => 'MercadoLibre no devolvió seller_id.'], 500);
  }

  $query = http_build_query(['q' => $sku], '', '&', PHP_QUERY_RFC3986);
  $search = stock_sync_ml_http_get('https://api.mercadolibre.com/users/' . rawurlencode($sellerId) . '/items/search?' . $query, $accessToken);
  if ($search['code'] < 200 || $search['code'] >= 300) {
    ml_search_respond(['ok' => false, 'error' => 'No se pudo buscar items por SKU (HTTP ' . $search['code'] . ').'], 500);
  }

  $itemIds = $search['json']['results'] ?? [];
  if (!is_array($itemIds)) {
    $itemIds = [];
  }

  $rows = [];
  foreach ($itemIds as $itemIdRaw) {
    $itemId = trim((string)$itemIdRaw);
    if ($itemId === '') {
      continue;
    }

    $item = stock_sync_ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($itemId), $accessToken);
    if ($item['code'] < 200 || $item['code'] >= 300) {
      continue;
    }
    $itemJson = $item['json'];
    $variationsOut = [];
    $selectedVariationId = '';
    $variations = $itemJson['variations'] ?? [];
    if (is_array($variations)) {
      foreach ($variations as $variation) {
        if (!is_array($variation)) {
          continue;
        }
        $variationId = trim((string)($variation['id'] ?? ''));
        $attrs = [];
        $attributes = $variation['attribute_combinations'] ?? [];
        if (is_array($attributes)) {
          foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
              continue;
            }
            $name = trim((string)($attribute['name'] ?? ''));
            $value = trim((string)($attribute['value_name'] ?? $attribute['value_id'] ?? ''));
            if ($name !== '' || $value !== '') {
              $attrs[] = trim($name . ': ' . $value, ': ');
            }
          }
        }
        $variationsOut[] = [
          'variation_id' => $variationId,
          'attributes' => $attrs,
          'available_quantity' => (int)($variation['available_quantity'] ?? 0),
        ];
        if ($selectedVariationId === '') {
          $selectedVariationId = $variationId;
        }
      }
    }

    $rows[] = [
      'item_id' => $itemId,
      'title' => trim((string)($itemJson['title'] ?? '')),
      'status' => trim((string)($itemJson['status'] ?? '')),
      'has_variations' => count($variationsOut) > 0,
      'available_quantity' => (int)($itemJson['available_quantity'] ?? 0),
      'variations' => $variationsOut,
      'selected_variation_id' => $selectedVariationId,
      'seller_id' => trim((string)($itemJson['seller_id'] ?? '')),
    ];
  }

  ml_search_respond(['ok' => true, 'seller_id' => $sellerId, 'rows' => $rows]);
} catch (Throwable $t) {
  ml_search_respond(['ok' => false, 'error' => $t->getMessage()], 500);
}
