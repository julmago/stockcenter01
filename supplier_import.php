<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supplier_import_lib.php';
require_login();
ensure_product_suppliers_schema();

if (!is_post()) {
  redirect('suppliers.php');
}

$supplierId = (int)post('supplier_id', '0');
if ($supplierId <= 0) {
  abort(400, 'Proveedor inválido.');
}

$st = db()->prepare('SELECT * FROM suppliers WHERE id = ? LIMIT 1');
$st->execute([$supplierId]);
$supplier = $st->fetch();
if (!$supplier) {
  abort(404, 'Proveedor no encontrado.');
}

$sourceType = strtoupper(trim((string)post('source_type', 'CSV')));
$allowed = ['CSV', 'XLSX', 'TXT', 'PASTE', 'PDF'];
if (!in_array($sourceType, $allowed, true)) {
  abort(400, 'Tipo de fuente inválido.');
}
if ($sourceType === 'PDF') {
  redirect('suppliers.php?edit_id=' . $supplierId . '&error=pdf_manual');
}

$tmpName = '';
$filename = '';
$pasteText = (string)post('paste_text', '');
if ($sourceType === 'PASTE') {
  if (trim($pasteText) === '') {
    abort(400, 'Pegá texto para importar.');
  }
} else {
  if (!isset($_FILES['source_file']) || $_FILES['source_file']['error'] !== UPLOAD_ERR_OK) {
    abort(400, 'Subí un archivo válido.');
  }
  $tmpName = (string)$_FILES['source_file']['tmp_name'];
  $filename = (string)$_FILES['source_file']['name'];
}

try {
  $runId = supplier_import_build_run($supplierId, $supplier, [
    'source_type' => $sourceType,
    'tmp_name' => $tmpName,
    'filename' => $filename,
    'paste_text' => $pasteText,
    'extra_discount_percent' => post('extra_discount_percent', $supplier['import_discount_default'] ?? '0'),
    'default_cost_type' => post('default_cost_type', $supplier['import_default_cost_type'] ?? 'UNIDAD'),
    'default_units_per_pack' => post('default_units_per_pack', $supplier['import_default_units_per_pack'] ?? ''),
  ]);
  redirect('supplier_import_preview.php?run_id=' . $runId);
} catch (Throwable $t) {
  abort(400, 'No se pudo procesar la importación: ' . $t->getMessage());
}
