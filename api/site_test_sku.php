<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../prestashop.php';

header('Content-Type: application/json; charset=utf-8');
require_login();
ensure_sites_schema();

function respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function channel_norm(string $value): string {
  $value = strtoupper(trim($value));
  if (!in_array($value, ['NONE', 'PRESTASHOP', 'MERCADOLIBRE'], true)) {
    return 'NONE';
  }
  return $value;
}

function to_int_number($value): int {
  if (is_int($value)) {
    return $value;
  }
  if (is_float($value)) {
    return (int)round($value);
  }
  if (is_string($value)) {
    $parsed = (float)str_replace(',', '.', $value);
    return (int)round($parsed);
  }
  return 0;
}

function ps_xml_first_text(SimpleXMLElement $node, string $name): string {
  if (!isset($node->{$name})) {
    return '';
  }
  $target = $node->{$name};
  if ($target instanceof SimpleXMLElement) {
    if (isset($target->language)) {
      foreach ($target->language as $language) {
        $text = trim((string)$language);
        if ($text !== '') {
          return $text;
        }
      }
    }
    return trim((string)$target);
  }
  return '';
}

function ps_fetch_product_row(string $baseUrl, string $apiKey, int $idProduct, string $fallbackSku): array {
  $response = ps_request_with_credentials('GET', '/api/products/' . $idProduct . '?display=[id,reference,name,price]', $baseUrl, $apiKey);
  if ($response['code'] < 200 || $response['code'] >= 300) {
    throw new RuntimeException('No se pudo consultar el producto en PrestaShop (HTTP ' . $response['code'] . ').');
  }
  $sx = ps_xml_load($response['body']);
  if (!isset($sx->product)) {
    throw new RuntimeException('Respuesta inválida de PrestaShop al leer producto.');
  }
  $product = $sx->product;
  $sku = trim((string)$product->reference);
  if ($sku === '') {
    $sku = $fallbackSku;
  }
  $title = ps_xml_first_text($product, 'name');
  $price = to_int_number((string)$product->price);

  return [
    'sku' => $sku,
    'title' => $title,
    'price' => $price,
  ];
}

function ps_fetch_stock_value(string $baseUrl, string $apiKey, int $idProduct, int $idProductAttribute): int {
  $query = http_build_query([
    'display' => '[id,id_product,id_product_attribute,quantity]',
    'filter[id_product]' => '[' . $idProduct . ']',
    'filter[id_product_attribute]' => '[' . $idProductAttribute . ']',
  ], '', '&', PHP_QUERY_RFC3986);
  $response = ps_request_with_credentials('GET', '/api/stock_availables?' . $query, $baseUrl, $apiKey);
  if ($response['code'] < 200 || $response['code'] >= 300) {
    throw new RuntimeException('No se pudo consultar stock en PrestaShop (HTTP ' . $response['code'] . ').');
  }
  $sx = ps_xml_load($response['body']);
  if (!isset($sx->stock_availables->stock_available)) {
    return 0;
  }

  foreach ($sx->stock_availables->stock_available as $sa) {
    $id = (int)($sa->attributes()->id ?? 0);
    if ($id <= 0) {
      $id = (int)trim((string)$sa->id);
    }
    if ($id <= 0) {
      continue;
    }
    $detail = ps_request_with_credentials('GET', '/api/stock_availables/' . $id, $baseUrl, $apiKey);
    if ($detail['code'] < 200 || $detail['code'] >= 300) {
      continue;
    }
    $sxDetail = ps_xml_load($detail['body']);
    if (!isset($sxDetail->stock_available->quantity)) {
      continue;
    }
    return to_int_number((string)$sxDetail->stock_available->quantity);
  }

  return 0;
}

function ml_http_get(string $url, string $accessToken): array {
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

function ml_extract_sku(array $item, string $defaultSku): string {
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

$siteId = (int)get('site_id', '0');
$sku = trim((string)get('sku', ''));
if ($siteId <= 0) {
  respond(['ok' => false, 'rows' => [], 'error' => 'site_id inválido.'], 400);
}
if ($sku === '') {
  respond(['ok' => false, 'rows' => [], 'error' => 'Ingresá un SKU.'], 400);
}

$pdo = db();
$st = $pdo->prepare('SELECT s.id, s.channel_type AS site_channel_type, sc.channel_type, sc.enabled, sc.ps_base_url, sc.ps_api_key, sc.ml_client_id, sc.ml_client_secret, sc.ml_access_token, sc.ml_refresh_token, sc.ml_user_id, sc.ml_status
  FROM sites s
  LEFT JOIN site_connections sc ON sc.site_id = s.id
  WHERE s.id = ?
  LIMIT 1');
$st->execute([$siteId]);
$row = $st->fetch();
if (!$row) {
  respond(['ok' => false, 'rows' => [], 'error' => 'Sitio no encontrado.'], 404);
}

$channel = channel_norm((string)($row['channel_type'] ?? $row['site_channel_type'] ?? 'NONE'));
if ($channel === 'NONE') {
  respond(['ok' => false, 'rows' => [], 'error' => 'Sitio sin conexión configurada.'], 400);
}

try {
  if ($channel === 'PRESTASHOP') {
    $baseUrl = trim((string)($row['ps_base_url'] ?? ''));
    $apiKey = trim((string)($row['ps_api_key'] ?? ''));
    if ($baseUrl === '' || $apiKey === '') {
      respond(['ok' => false, 'rows' => [], 'error' => 'Sitio sin conexión configurada.'], 400);
    }

    $matches = ps_find_by_reference_all($sku, $baseUrl, $apiKey);
    $rows = [];
    foreach ($matches as $match) {
      $idProduct = (int)($match['id_product'] ?? 0);
      $idProductAttribute = (int)($match['id_product_attribute'] ?? 0);
      if ($idProduct <= 0) {
        continue;
      }
      $productRow = ps_fetch_product_row($baseUrl, $apiKey, $idProduct, $sku);
      $stock = ps_fetch_stock_value($baseUrl, $apiKey, $idProduct, $idProductAttribute);
      $rows[] = [
        'sku' => (string)$productRow['sku'],
        'title' => (string)$productRow['title'],
        'price' => to_int_number($productRow['price']),
        'stock' => to_int_number($stock),
      ];
    }

    respond(['ok' => true, 'rows' => $rows]);
  }

  if ($channel === 'MERCADOLIBRE') {
    $clientId = trim((string)($row['ml_client_id'] ?? ''));
    $clientSecret = trim((string)($row['ml_client_secret'] ?? ''));
    $accessToken = trim((string)($row['ml_access_token'] ?? ''));
    $refreshToken = trim((string)($row['ml_refresh_token'] ?? ''));
    $mlStatus = strtoupper(trim((string)($row['ml_status'] ?? '')));
    $mlUserId = trim((string)($row['ml_user_id'] ?? ''));
    if ($clientId === '' || $clientSecret === '') {
      respond(['ok' => false, 'rows' => [], 'error' => 'Sitio sin conexión configurada.'], 400);
    }
    if ($refreshToken === '' && $mlStatus !== 'CONNECTED') {
      respond(['ok' => false, 'rows' => [], 'error' => 'MercadoLibre: falta conectar y obtener token (refresh_token vacío).'], 400);
    }
    if ($accessToken === '') {
      respond(['ok' => false, 'rows' => [], 'error' => 'MercadoLibre: falta token de acceso para probar SKU. Reconectá la cuenta.'], 400);
    }

    if ($mlUserId === '') {
      $me = ml_http_get('https://api.mercadolibre.com/users/me', $accessToken);
      if ($me['code'] < 200 || $me['code'] >= 300) {
        throw new RuntimeException('No se pudo consultar usuario de MercadoLibre (HTTP ' . $me['code'] . ').');
      }
      $mlUserId = trim((string)($me['json']['id'] ?? ''));
      if ($mlUserId === '') {
        throw new RuntimeException('MercadoLibre no devolvió user_id en /users/me.');
      }
      $up = $pdo->prepare('UPDATE site_connections SET ml_user_id = ?, updated_at = NOW() WHERE site_id = ?');
      $up->execute([$mlUserId, $siteId]);
    }

    $query = http_build_query(['seller_sku' => $sku], '', '&', PHP_QUERY_RFC3986);
    $search = ml_http_get('https://api.mercadolibre.com/users/' . rawurlencode($mlUserId) . '/items/search?' . $query, $accessToken);
    if ($search['code'] < 200 || $search['code'] >= 300) {
      throw new RuntimeException('No se pudo buscar SKU en MercadoLibre (HTTP ' . $search['code'] . ').');
    }

    $itemIds = $search['json']['results'] ?? [];
    if (!is_array($itemIds)) {
      $itemIds = [];
    }

    if (count($itemIds) === 0) {
      $fallbackQuery = http_build_query(['q' => $sku], '', '&', PHP_QUERY_RFC3986);
      $fallbackSearch = ml_http_get('https://api.mercadolibre.com/users/' . rawurlencode($mlUserId) . '/items/search?' . $fallbackQuery, $accessToken);
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
        $item = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($fallbackItemId), $accessToken);
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
      $item = ml_http_get('https://api.mercadolibre.com/items/' . rawurlencode($itemId), $accessToken);
      if ($item['code'] < 200 || $item['code'] >= 300) {
        continue;
      }
      $itemJson = $item['json'];
      $baseSku = ml_extract_sku($itemJson, $sku);
      $baseTitle = trim((string)($itemJson['title'] ?? ''));
      $basePrice = to_int_number($itemJson['price'] ?? 0);
      $baseStock = to_int_number($itemJson['available_quantity'] ?? 0);

      $rows[] = ['sku' => $baseSku, 'title' => $baseTitle, 'price' => $basePrice, 'stock' => $baseStock, 'item_id' => $itemId, 'variation_id' => ''];

      $variations = $itemJson['variations'] ?? [];
      if (is_array($variations)) {
        foreach ($variations as $variation) {
          if (!is_array($variation)) {
            continue;
          }
          $variationId = trim((string)($variation['id'] ?? ''));
          $rows[] = [
            'sku' => $sku . ($variationId !== '' ? ' (var ' . $variationId . ')' : ' (var)'),
            'title' => $baseTitle . ' (variante)',
            'price' => array_key_exists('price', $variation) ? to_int_number($variation['price']) : $basePrice,
            'stock' => array_key_exists('available_quantity', $variation) ? to_int_number($variation['available_quantity']) : $baseStock,
            'item_id' => $itemId,
            'variation_id' => $variationId,
          ];
        }
      }
    }

    respond(['ok' => true, 'rows' => $rows]);
  }

  respond(['ok' => false, 'rows' => [], 'error' => 'Tipo de conexión no soportado.'], 400);
} catch (Throwable $t) {
  respond(['ok' => false, 'rows' => [], 'error' => $t->getMessage()], 500);
}
