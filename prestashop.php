<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function ps_base_url(): string {
  $url = trim(setting_get('prestashop_url', ''));
  // Normalizar: sin slash final
  $url = rtrim($url, "/");
  return $url;
}

function ps_api_key(): string {
  return trim(setting_get('prestashop_api_key', ''));
}

function ps_mode(): string {
  $m = trim(setting_get('prestashop_mode', 'replace'));
  return in_array($m, ['replace','add'], true) ? $m : 'replace';
}

function ps_build_url(string $path): string {
  $base = ps_base_url();
  if ($base === '') {
    throw new RuntimeException("Falta configurar PrestaShop (URL base).");
  }
  $normalized = $path;
  if (!str_starts_with($normalized, '/api')) {
    if (!str_starts_with($normalized, '/')) {
      $normalized = '/' . $normalized;
    }
    $normalized = '/api' . $normalized;
  }
  return $base . $normalized;
}

function ps_has_header(array $headers, string $needle): bool {
  foreach ($headers as $header) {
    if (stripos($header, $needle . ':') === 0) {
      return true;
    }
  }
  return false;
}

function ps_request(string $method, string $path, ?string $body = null, array $headers = []): array {
  $base = ps_base_url();
  $key  = ps_api_key();
  if ($base === '' || $key === '') {
    throw new RuntimeException("Falta configurar PrestaShop (URL / API Key).");
  }

  $url = ps_build_url($path);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_USERPWD, $key . ":"); // basic auth, password vacía
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

  if (!ps_has_header($headers, 'Accept')) {
    $headers[] = 'Accept: application/xml';
  }

  if ($body !== null) {
    if (!ps_has_header($headers, 'Content-Type')) {
      $headers[] = 'Content-Type: application/xml; charset=utf-8';
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }

  if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  error_log("[PrestaShop] Request: {$method} {$url}");

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  if ($resp === false) {
    throw new RuntimeException("Error cURL: " . $err);
  }

  $snippet = substr($resp, 0, 1000);
  error_log("[PrestaShop] Response: HTTP {$code} | Content-Type: " . ($contentType ?: 'n/a'));
  error_log("[PrestaShop] Body (first 1000 chars): " . $snippet);

  return ['code' => $code, 'body' => $resp, 'content_type' => $contentType, 'url' => $url];
}

function ps_xml_load(string $xml): SimpleXMLElement {
  libxml_use_internal_errors(true);
  $sx = simplexml_load_string($xml);
  if (!$sx) {
    $errs = libxml_get_errors();
    libxml_clear_errors();
    throw new RuntimeException("Respuesta XML inválida desde PrestaShop.");
  }
  return $sx;
}

function ps_extract_product_id(SimpleXMLElement $product): int {
  $id_text = trim((string)$product->id);
  if ($id_text !== '') {
    return (int)$id_text;
  }

  $attr_id = trim((string)$product->attributes()->id);
  if ($attr_id !== '') {
    return (int)$attr_id;
  }

  return 0;
}

function ps_find_first_product_id(SimpleXMLElement $sx): ?int {
  if (!isset($sx->products->product)) {
    return null;
  }

  $products = $sx->products->product;

  if (is_array($products)) {
    foreach ($products as $product) {
      $id = ps_extract_product_id($product);
      if ($id > 0) {
        return $id;
      }
    }
    return null;
  }

  foreach ($products as $product) {
    $id = ps_extract_product_id($product);
    if ($id > 0) {
      return $id;
    }
  }

  return null;
}

/**
 * Busca un producto o combinación por SKU (reference).
 * Retorna:
 *  - ['type'=>'combination','id_product'=>int,'id_product_attribute'=>int]
 *  - ['type'=>'product','id_product'=>int,'id_product_attribute'=>0]
 */
function ps_find_by_reference(string $sku): ?array {
  $sku = trim($sku);
  if ($sku === '') return null;
  $encoded_sku = rawurlencode($sku);

  // 1) probar combinaciones por reference (si existen)
  // display=[id,id_product,reference]
  $q = "/api/combinations?display=[id,id_product,reference]&filter[reference]=[" . $encoded_sku . "]";
  error_log("[PrestaShop] Lookup combinations by reference URL: " . ps_build_url($q));
  $r = ps_request("GET", $q);
  if ($r['code'] >= 200 && $r['code'] < 300) {
    $sx = ps_xml_load($r['body']);
    if (isset($sx->combinations->combination)) {
      $comb = $sx->combinations->combination[0];
      $id_attr = (int)$comb->attributes()->id;
      $id_prod = (int)trim((string)$comb->id_product);
      if ($id_attr > 0 && $id_prod > 0) {
        return ['type' => 'combination', 'id_product' => $id_prod, 'id_product_attribute' => $id_attr];
      }
    }
  }

  // 2) producto simple por reference
  $q = "/api/products?display=[id,reference]&filter[reference]=[" . $encoded_sku . "]";
  error_log("[PrestaShop] Lookup products by reference URL: " . ps_build_url($q));
  $r = ps_request("GET", $q);
  if ($r['code'] >= 200 && $r['code'] < 300) {
    $sx = ps_xml_load($r['body']);
    $id_prod = ps_find_first_product_id($sx);
    if ($id_prod !== null && $id_prod > 0) {
      return ['type' => 'product', 'id_product' => $id_prod, 'id_product_attribute' => 0];
    }
  }

  return null;
}

function ps_find_stock_available_id(int $id_product, int $id_product_attribute): ?int {
  $q = "/api/stock_availables?display=[id,id_product,id_product_attribute]&filter[id_product]=[" . $id_product . "]&filter[id_product_attribute]=[" . $id_product_attribute . "]";
  $r = ps_request("GET", $q);
  if (!($r['code'] >= 200 && $r['code'] < 300)) return null;

  $sx = ps_xml_load($r['body']);
  if (isset($sx->stock_availables->stock_available)) {
    $sa = $sx->stock_availables->stock_available[0];
    $id = (int)$sa->attributes()->id;
    return $id > 0 ? $id : null;
  }
  return null;
}

function ps_get_stock_available(int $id_stock_available): array {
  $r = ps_request("GET", "/api/stock_availables/" . $id_stock_available);
  if (!($r['code'] >= 200 && $r['code'] < 300)) {
    throw new RuntimeException("No se pudo leer stock_available #{$id_stock_available} (HTTP {$r['code']}).");
  }
  $sx = ps_xml_load($r['body']);
  // Estructura: <prestashop><stock_available>...</stock_available></prestashop>
  $qty = (int)$sx->stock_available->quantity;
  return ['xml' => $r['body'], 'qty' => $qty];
}

function ps_update_stock_available_quantity(int $id_stock_available, int $new_qty): void {
  $current = ps_get_stock_available($id_stock_available);
  $sx = ps_xml_load($current['xml']);
  $sx->stock_available->quantity = (string)$new_qty;

  // Necesitamos re-emitir XML
  // SimpleXML no conserva exactamente headers, pero PrestaShop acepta el XML.
  $xml = $sx->asXML();
  if ($xml === false) {
    throw new RuntimeException("No se pudo generar XML para actualizar stock.");
  }

  $r = ps_request("PUT", "/api/stock_availables/" . $id_stock_available, $xml);
  if (!($r['code'] >= 200 && $r['code'] < 300)) {
    throw new RuntimeException("Falló actualización stock_available #{$id_stock_available} (HTTP {$r['code']}).");
  }
}

function ps_extract_created_stock_available_id(SimpleXMLElement $sx): ?int {
  if (isset($sx->stock_available->id)) {
    $id = (int)trim((string)$sx->stock_available->id);
    return $id > 0 ? $id : null;
  }
  if (isset($sx->stock_available) && $sx->stock_available->attributes() && isset($sx->stock_available->attributes()->id)) {
    $id = (int)$sx->stock_available->attributes()->id;
    return $id > 0 ? $id : null;
  }
  if (isset($sx->stock_available)) {
    $id_text = trim((string)$sx->stock_available);
    $id = (int)$id_text;
    return $id > 0 ? $id : null;
  }
  return null;
}

function ps_create_stock_available(int $id_product, int $id_product_attribute, int $quantity): int {
  $qty = max(0, $quantity);
  $sx = new SimpleXMLElement('<prestashop></prestashop>');
  $sa = $sx->addChild('stock_available');
  $sa->addChild('id_product', (string)$id_product);
  $sa->addChild('id_product_attribute', (string)$id_product_attribute);
  $sa->addChild('quantity', (string)$qty);
  $sa->addChild('depends_on_stock', '0');
  $sa->addChild('out_of_stock', '2');

  $xml = $sx->asXML();
  if ($xml === false) {
    throw new RuntimeException("No se pudo generar XML para crear stock.");
  }

  $r = ps_request("POST", "/api/stock_availables", $xml);
  if (!($r['code'] >= 200 && $r['code'] < 300)) {
    throw new RuntimeException("Falló creación de stock_available (HTTP {$r['code']}).");
  }

  $resp = ps_xml_load($r['body']);
  $id = ps_extract_created_stock_available_id($resp);
  if ($id === null) {
    throw new RuntimeException("No se obtuvo ID de stock_available creado.");
  }
  return $id;
}
