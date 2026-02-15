<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_product_suppliers_schema();

$runId = (int)get('run_id', '0');
if ($runId <= 0) {
  abort(400, 'Importación inválida.');
}

$stRun = db()->prepare('SELECT r.*, s.name AS supplier_name FROM supplier_import_runs r INNER JOIN suppliers s ON s.id = r.supplier_id WHERE r.id = ? LIMIT 1');
$stRun->execute([$runId]);
$run = $stRun->fetch();
if (!$run) {
  abort(404, 'Importación no encontrada.');
}

$summarySt = db()->prepare("SELECT
  COUNT(*) AS total,
  SUM(CASE WHEN status = 'MATCHED' AND chosen_by_rule = 1 THEN 1 ELSE 0 END) AS matched,
  SUM(CASE WHEN status = 'UNMATCHED' THEN 1 ELSE 0 END) AS unmatched,
  SUM(CASE WHEN status = 'DUPLICATE_SKU' THEN 1 ELSE 0 END) AS duplicates,
  SUM(CASE WHEN status = 'INVALID' THEN 1 ELSE 0 END) AS invalid
  FROM supplier_import_rows WHERE run_id = ?");
$summarySt->execute([$runId]);
$summary = $summarySt->fetch() ?: ['total' => 0, 'matched' => 0, 'unmatched' => 0, 'duplicates' => 0, 'invalid' => 0];

$matchedSt = db()->prepare("SELECT r.*, ps.supplier_sku AS linked_supplier_sku, p.sku AS product_sku, p.name AS product_name
  FROM supplier_import_rows r
  LEFT JOIN product_suppliers ps ON ps.id = r.matched_product_supplier_id
  LEFT JOIN products p ON p.id = r.matched_product_id
  WHERE r.run_id = ? AND r.status = 'MATCHED'
  ORDER BY r.supplier_sku ASC, r.id ASC");
$matchedSt->execute([$runId]);
$matchedRows = $matchedSt->fetchAll();

$duplicateSt = db()->prepare("SELECT * FROM supplier_import_rows WHERE run_id = ? AND status = 'DUPLICATE_SKU' ORDER BY supplier_sku ASC, id ASC");
$duplicateSt->execute([$runId]);
$duplicateRows = $duplicateSt->fetchAll();

$unmatchedSt = db()->prepare("SELECT * FROM supplier_import_rows WHERE run_id = ? AND status IN ('UNMATCHED','INVALID') ORDER BY supplier_sku ASC, id ASC");
$unmatchedSt->execute([$runId]);
$unmatchedRows = $unmatchedSt->fetchAll();
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
        <h2 class="page-title">Previsualización importación</h2>
        <span class="muted">Proveedor: <?= e((string)$run['supplier_name']) ?> | Fuente: <?= e((string)$run['source_type']) ?></span>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="suppliers.php?edit_id=<?= (int)$run['supplier_id'] ?>">Volver</a>
      </div>
    </div>

    <div class="card">
      <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: var(--space-4);">
        <div><strong>Total</strong><br><?= (int)$summary['total'] ?></div>
        <div><strong>Matched</strong><br><?= (int)$summary['matched'] ?></div>
        <div><strong>Unmatched</strong><br><?= (int)$summary['unmatched'] ?></div>
        <div><strong>Duplicados</strong><br><?= (int)$summary['duplicates'] ?></div>
        <div><strong>Invalid</strong><br><?= (int)$summary['invalid'] ?></div>
      </div>
      <div class="inline-actions" style="margin-top:var(--space-3);">
        <?php if ((int)$summary['matched'] > 0 && empty($run['applied_at'])): ?>
          <form method="post" action="supplier_import_apply.php">
            <input type="hidden" name="run_id" value="<?= (int)$run['id'] ?>">
            <button class="btn" type="submit">Aplicar importación</button>
          </form>
        <?php endif; ?>
        <?php if (!empty($run['applied_at'])): ?><span class="muted">Aplicada en <?= e((string)$run['applied_at']) ?></span><?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Matcheados (OK)</h3></div>
      <div class="table-wrapper"><table class="table">
        <thead><tr><th>SKU proveedor</th><th>SKU interno</th><th>Producto</th><th>Costo unitario</th><th>Costo raw</th><th>Regla</th></tr></thead>
        <tbody>
        <?php if (!$matchedRows): ?>
          <tr><td colspan="6">Sin filas.</td></tr>
        <?php else: foreach ($matchedRows as $row): ?>
          <tr>
            <td><?= e((string)$row['supplier_sku']) ?></td>
            <td><?= e((string)($row['product_sku'] ?? '—')) ?></td>
            <td><?= e((string)($row['product_name'] ?? '—')) ?></td>
            <td><?= $row['normalized_unit_cost'] !== null ? (int)round((float)$row['normalized_unit_cost'], 0) : '—' ?></td>
            <td><?= $row['raw_price'] !== null ? (int)round((float)$row['raw_price'], 0) : '—' ?></td>
            <td><?= e((string)($row['reason'] ?? '')) ?><?= (int)$row['chosen_by_rule'] === 1 ? ' [elegida]' : '' ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Duplicados</h3></div>
      <div class="table-wrapper"><table class="table">
        <thead><tr><th>SKU proveedor</th><th>Descripción</th><th>Costo unitario</th><th>Estado</th><th>Motivo</th></tr></thead>
        <tbody>
        <?php if (!$duplicateRows): ?>
          <tr><td colspan="5">Sin duplicados descartados.</td></tr>
        <?php else: foreach ($duplicateRows as $row): ?>
          <tr>
            <td><?= e((string)$row['supplier_sku']) ?></td>
            <td><?= e((string)($row['description'] ?? '')) ?></td>
            <td><?= $row['normalized_unit_cost'] !== null ? (int)round((float)$row['normalized_unit_cost'], 0) : '—' ?></td>
            <td><?= e((string)$row['status']) ?></td>
            <td><?= e((string)($row['reason'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">No matcheados / inválidos</h3></div>
      <div class="table-wrapper"><table class="table">
        <thead><tr><th>SKU proveedor</th><th>Descripción</th><th>Precio</th><th>Estado</th><th>Motivo</th></tr></thead>
        <tbody>
        <?php if (!$unmatchedRows): ?>
          <tr><td colspan="5">Sin filas no vinculadas.</td></tr>
        <?php else: foreach ($unmatchedRows as $row): ?>
          <tr>
            <td><?= e((string)$row['supplier_sku']) ?></td>
            <td><?= e((string)($row['description'] ?? '')) ?></td>
            <td><?= $row['raw_price'] !== null ? (int)round((float)$row['raw_price'], 0) : '—' ?></td>
            <td><?= e((string)$row['status']) ?></td>
            <td><?= e((string)($row['reason'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</main>
</body>
</html>
