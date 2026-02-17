<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../include/stock.php';

header('Content-Type: application/json; charset=utf-8');

function stock_webhook_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  stock_webhook_json(['ok' => false, 'error' => 'Método inválido.'], 405);
}

ensure_sites_schema();
ensure_stock_schema();

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || trim($rawBody) === '') {
  stock_webhook_json(['ok' => false, 'error' => 'Body vacío.'], 422);
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
  stock_webhook_json(['ok' => false, 'error' => 'JSON inválido.'], 422);
}

$siteId = (int)($data['site_id'] ?? 0);
$sku = trim((string)($data['sku'] ?? ''));
$qtyRaw = $data['qty_new'] ?? null;
$event = trim((string)($data['event'] ?? ''));
$timestamp = trim((string)($data['timestamp'] ?? ''));
$signature = strtolower(trim((string)($data['signature'] ?? '')));

if ($siteId <= 0 || $sku === '' || !is_numeric($qtyRaw) || $timestamp === '' || $signature === '') {
  stock_webhook_json(['ok' => false, 'error' => 'Payload inválido.'], 422);
}

if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
  stock_webhook_json(['ok' => false, 'error' => 'signature inválida.'], 422);
}

$pdo = db();
$siteSt = $pdo->prepare('SELECT s.id, s.name, sc.webhook_secret FROM sites s LEFT JOIN site_connections sc ON sc.site_id = s.id WHERE s.id = ? LIMIT 1');
$siteSt->execute([$siteId]);
$site = $siteSt->fetch();
if (!$site) {
  stock_webhook_json(['ok' => false, 'error' => 'Site no encontrado.'], 404);
}

$secret = trim((string)($site['webhook_secret'] ?? ''));
if ($secret === '') {
  stock_webhook_json(['ok' => false, 'error' => 'Site sin webhook_secret configurado.'], 422);
}

$signedPayload = [
  'site_id' => $siteId,
  'sku' => $sku,
  'qty_new' => (int)$qtyRaw,
  'event' => $event,
  'timestamp' => $timestamp,
];
$expectedSignature = hash_hmac('sha256', json_encode($signedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);
if (!hash_equals($expectedSignature, $signature)) {
  stock_webhook_json(['ok' => false, 'error' => 'signature inválida.'], 403);
}

$qtyNew = (int)$qtyRaw;

$st = $pdo->prepare('SELECT id, sku FROM products WHERE sku = ? ORDER BY id ASC');
$st->execute([$sku]);
$products = $st->fetchAll();
if (!$products) {
  stock_webhook_json(['ok' => false, 'error' => 'SKU no encontrado en TS Work.', 'site_id' => $siteId, 'sku' => $sku], 404);
}

if (count($products) > 1) {
  stock_webhook_json([
    'ok' => false,
    'error' => 'SKU duplicado en TS Work. Abortado para evitar inconsistencias.',
    'site_id' => $siteId,
    'sku' => $sku,
    'matches' => array_map(static fn(array $row): int => (int)$row['id'], $products),
  ], 409);
}

$productId = (int)$products[0]['id'];
$prev = get_stock($productId);
$note = sprintf(
  'sync_pull_webhook site_id=%d sku=%s prev=%d new=%d event=%s ts=%s',
  $siteId,
  $sku,
  (int)$prev['qty'],
  $qtyNew,
  $event,
  $timestamp
);
$eventId = 'ps-webhook-' . sha1($siteId . '|' . $sku . '|' . $timestamp . '|' . $event);
$stock = set_stock($productId, $qtyNew, $note, 0, 'prestashop', $siteId, $eventId, 'sync_pull_webhook');

stock_webhook_json([
  'ok' => true,
  'site_id' => $siteId,
  'sku' => $sku,
  'product_id' => $productId,
  'prev_qty' => (int)$prev['qty'],
  'new_qty' => (int)$stock['qty'],
]);
