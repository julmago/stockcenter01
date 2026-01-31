<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

if (headers_sent()) {
  abort(500, 'No se pudo generar el XLSX.');
}

while (ob_get_level() > 0) {
  ob_end_clean();
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Listado');

$sheet->setCellValue('A1', 'sku');
$sheet->setCellValue('B1', 'nombre');
$sheet->setCellValue('C1', 'cantidad');
$sheet->getStyle('A1:C1')->getFont()->setBold(true);

$rowIndex = 2;
foreach ($rows as $r) {
  $sheet->setCellValue("A{$rowIndex}", $r['sku']);
  $sheet->setCellValue("B{$rowIndex}", $r['name']);
  $sheet->setCellValue("C{$rowIndex}", (int)$r['qty']);
  $rowIndex++;
}

foreach (['A', 'B', 'C'] as $column) {
  $sheet->getColumnDimension($column)->setAutoSize(true);
}

$filename = "listado_{$list_id}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
