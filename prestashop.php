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

function ps_request(string $method, string $path, ?string $body = null, array $headers = []): array {
  $base = ps_base_url();
  $key  = ps_api_key();
  if ($base === '' || $key === '') {
    throw new RuntimeException("Falta configurar PrestaShop (URL / API Key).");
  }

  $url = $base . $path;

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_USERPWD, $key . ":"); // basic auth, password vacía
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

  if ($body !== null) {
    $headers[] = 'Content-Type: application/xml; charset=utf-8';
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }

  if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) {
    throw new RuntimeException("Error cURL: " . $err);
  }

  return ['code' => $code, 'body' => $resp];
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

/**
 * Busca un producto o combinación por SKU (reference).
 * Retorna:
 *  - ['type'=>'combination','id_product'=>int,'id_product_attribute'=>int]
 *  - ['type'=>'product','id_product'=>int,'id_product_attribute'=>0]
 */
function ps_find_by_reference(string $sku): ?array {
  $sku = trim($sku);
  if ($sku === '') return null;
  $base = ps_base_url();
  $encoded_sku = rawurlencode($sku);

  // 1) probar combinaciones por reference (si existen)
  // display=[id,id_product,reference]
  $q = "/api/combinations?display=[id,id_product,reference]&filter[reference]=[" . $encoded_sku . "]";
  error_log("PrestaShop URL: " . $base . $q);
  $r = ps_request("GET", $q);
  error_log("PrestaShop response: " . $r['body']);
  if ($r['code'] >= 200 && $r['code'] < 300) {
    $sx = ps_xml_load($r['body']);
    if (isset($sx->combinations->combination)) {
      $comb = $sx->combinations->combination[0];
      $id_attr = (int)$comb->attributes()->id;
      $id_prod = (int)$comb->id_product;
      if ($id_attr > 0 && $id_prod > 0) {
        return ['type' => 'combination', 'id_product' => $id_prod, 'id_product_attribute' => $id_attr];
      }
    }
  }

  // 2) producto simple por reference
  $q = "/api/products?display=[id,reference]&filter[reference]=[" . $encoded_sku . "]";
  error_log("PrestaShop URL: " . $base . $q);
  $r = ps_request("GET", $q);
  error_log("PrestaShop response: " . $r['body']);
  if ($r['code'] >= 200 && $r['code'] < 300) {
    $sx = ps_xml_load($r['body']);
    if (isset($sx->products->product)) {
      $p = $sx->products->product[0];
      $id_prod = (int)$p->attributes()->id;
      if ($id_prod > 0) {
        return ['type' => 'product', 'id_product' => $id_prod, 'id_product_attribute' => 0];
      }
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
