<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$list_id = (int)get('id','0');
if ($list_id <= 0) abort(400, 'Falta id.');

$st = db()->prepare("SELECT id, name FROM stock_lists WHERE id = ? LIMIT 1");
$st->execute([$list_id]);
$list = $st->fetch();
if (!$list) abort(404, 'Listado no encontrado.');

$st = db()->prepare("
  SELECT p.sku, p.name, i.qty
  FROM stock_list_items i
  JOIN products p ON p.id = i.product_id
  WHERE i.stock_list_id = ?
  ORDER BY p.name ASC
");
$st->execute([$list_id]);
$rows = $st->fetchAll();

$filename = "listado_{$list_id}.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$out = fopen('php://output', 'w');
fputcsv($out, ['sku','nombre','cantidad']);
foreach ($rows as $r) {
  fputcsv($out, [$r['sku'], $r['name'], (int)$r['qty']]);
}
fclose($out);
exit;
