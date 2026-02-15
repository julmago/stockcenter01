<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supplier_import_lib.php';
require_login();
ensure_product_suppliers_schema();

$token = trim((string)get('token', post('token', '')));
if ($token === '' || !isset($_SESSION['supplier_import_mapping'][$token])) {
  abort(400, 'Sesión de importación expirada.');
}

$ctx = $_SESSION['supplier_import_mapping'][$token];
$supplierId = (int)($ctx['supplier_id'] ?? 0);
$st = db()->prepare('SELECT * FROM suppliers WHERE id = ? LIMIT 1');
$st->execute([$supplierId]);
$supplier = $st->fetch();
if (!$supplier) {
  abort(404, 'Proveedor no encontrado.');
}

$analysis = $ctx['analysis'] ?? [];
$headers = (array)($analysis['headers'] ?? []);
$priceCandidates = (array)($analysis['price_candidates'] ?? []);
$skuCandidates = (array)($analysis['sku_candidates'] ?? []);
$costTypeCandidates = (array)($analysis['cost_type_candidates'] ?? []);
$unitsCandidates = (array)($analysis['units_candidates'] ?? []);
$sameHeaderAsMapping = !empty($supplier['import_mapping_header_hash']) && !empty($analysis['header_hash']) && (string)$supplier['import_mapping_header_hash'] === (string)$analysis['header_hash'];

$defaultSku = (string)($supplier['import_sku_column'] ?? '');
if ($defaultSku === '' || !in_array($defaultSku, $headers, true)) {
  $defaultSku = $skuCandidates[0] ?? ($headers[0] ?? '');
}
$defaultPrice = (string)($supplier['import_price_column'] ?? '');
if ($defaultPrice === '' || !in_array($defaultPrice, $headers, true)) {
  $defaultPrice = $priceCandidates[0] ?? '';
}
if (count($priceCandidates) > 1 && !$sameHeaderAsMapping) {
  $defaultPrice = '';
}
$defaultCostTypeCol = (string)($supplier['import_cost_type_column'] ?? '');
if ($defaultCostTypeCol !== '' && !in_array($defaultCostTypeCol, $headers, true)) {
  $defaultCostTypeCol = '';
}
$defaultUnitsCol = (string)($supplier['import_units_per_pack_column'] ?? '');
if ($defaultUnitsCol !== '' && !in_array($defaultUnitsCol, $headers, true)) {
  $defaultUnitsCol = '';
}

$error = '';
if (is_post()) {
  $skuColumn = trim((string)post('sku_column', ''));
  $priceColumn = trim((string)post('price_column', ''));
  $costTypeColumn = trim((string)post('cost_type_column', ''));
  $unitsPerPackColumn = trim((string)post('units_per_pack_column', ''));
  $extraDiscount = post('extra_discount_percent', '0');
  $saveMapping = post('save_mapping', '0') === '1' ? 1 : 0;

  $mustSelectPrice = count($priceCandidates) > 1 && !$sameHeaderAsMapping;
  if ($mustSelectPrice && $priceColumn === '') {
    $error = 'Debés seleccionar la columna de precio antes de procesar.';
  }

  if ($error === '') {
    try {
      $runId = supplier_import_build_run_with_mapping($supplierId, $supplier, $analysis, [
        'source_type' => $ctx['parse_source_type'] ?? 'CSV',
        'filename' => $ctx['filename'] ?? '',
        'sku_column' => $skuColumn,
        'price_column' => $priceColumn,
        'cost_type_column' => $costTypeColumn,
        'units_per_pack_column' => $unitsPerPackColumn,
        'extra_discount_percent' => $extraDiscount,
        'save_mapping' => $saveMapping,
      ]);
      unset($_SESSION['supplier_import_mapping'][$token]);
      redirect('supplier_import_preview.php?run_id=' . $runId);
    } catch (Throwable $t) {
      $error = $t->getMessage();
    }
  }

  $defaultSku = $skuColumn;
  $defaultPrice = $priceColumn;
  $defaultCostTypeCol = $costTypeColumn;
  $defaultUnitsCol = $unitsPerPackColumn;
}

$mustSelectPrice = count($priceCandidates) > 1 && !$sameHeaderAsMapping;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>
<main class="page">
  <div class="container">
    <div class="page-header">
      <div>
        <h2 class="page-title">Paso 2 - Mapping de importación</h2>
        <span class="muted">Proveedor: <?= e((string)$supplier['name']) ?><?= !empty($ctx['filename']) ? ' | Archivo: ' . e((string)$ctx['filename']) : '' ?></span>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="suppliers.php?edit_id=<?= (int)$supplierId ?>#importacion">Volver</a>
      </div>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <p class="muted">Fuente: <?= e((string)($ctx['source_type'] ?? 'file')) ?> | Detectado: <?= e((string)($ctx['detected_label'] ?? ($ctx['detected_format'] ?? 'unknown'))) ?><?= !empty($ctx['detected_delimiter']) ? ' | Separador: ' . e((string)$ctx['detected_delimiter']) : '' ?></p>
      <p class="muted">Headers detectados: <?= e(implode(', ', $headers)) ?></p>
      <?php if ($sameHeaderAsMapping): ?><p class="muted">Se detectó un mapping guardado para este mismo header. Podés editarlo antes de procesar.</p><?php endif; ?>
      <form method="post" class="stack">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: var(--space-4);">
          <label class="form-field">
            <span class="form-label">SKU proveedor</span>
            <select class="form-control" name="sku_column" required>
              <?php foreach ($headers as $header): ?>
                <option value="<?= e($header) ?>" <?= $defaultSku === $header ? 'selected' : '' ?>><?= e($header) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-field">
            <span class="form-label">Precio <?= $mustSelectPrice ? '(obligatorio)' : '' ?></span>
            <select class="form-control" name="price_column" <?= $mustSelectPrice ? 'required' : '' ?>>
              <option value="">Seleccionar...</option>
              <?php foreach (($priceCandidates ?: $headers) as $header): ?>
                <option value="<?= e($header) ?>" <?= $defaultPrice === $header ? 'selected' : '' ?>><?= e($header) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-field">
            <span class="form-label">Columna cost type (opcional)</span>
            <select class="form-control" name="cost_type_column">
              <option value="">Usar default del proveedor</option>
              <?php foreach ($headers as $header): ?>
                <option value="<?= e($header) ?>" <?= $defaultCostTypeCol === $header ? 'selected' : '' ?>><?= e($header) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-field">
            <span class="form-label">Columna units per pack (opcional)</span>
            <select class="form-control" name="units_per_pack_column">
              <option value="">Usar default del proveedor</option>
              <?php foreach ($headers as $header): ?>
                <option value="<?= e($header) ?>" <?= $defaultUnitsCol === $header ? 'selected' : '' ?>><?= e($header) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-field">
            <span class="form-label">Descuento extra del archivo (%)</span>
            <input class="form-control" type="number" step="0.01" min="-100" max="100" name="extra_discount_percent" value="0">
          </label>
        </div>

        <label class="form-check" style="display:flex; gap:8px; align-items:center;">
          <input type="checkbox" name="save_mapping" value="1" <?= $sameHeaderAsMapping ? 'checked' : '' ?>>
          <span>Guardar mapping del proveedor</span>
        </label>

        <?php if ($mustSelectPrice && $defaultPrice === ''): ?>
          <p class="muted">Hay múltiples columnas candidatas de precio y no existe mapping previo coincidente. Debés elegir una.</p>
        <?php endif; ?>

        <div class="inline-actions">
          <button class="btn" type="submit">Procesar importación</button>
        </div>
      </form>
    </div>
  </div>
</main>
</body>
</html>
