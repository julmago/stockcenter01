<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_product_suppliers_schema();

if (!is_post()) {
  redirect('suppliers.php');
}

$runId = (int)post('run_id', '0');
if ($runId <= 0) {
  abort(400, 'Importación inválida.');
}

$stRun = db()->prepare('SELECT * FROM supplier_import_runs WHERE id = ? LIMIT 1');
$stRun->execute([$runId]);
$run = $stRun->fetch();
if (!$run) {
  abort(404, 'Importación no encontrada.');
}
if (!empty($run['applied_at'])) {
  redirect('supplier_import_preview.php?run_id=' . $runId);
}

$rowsSt = db()->prepare("SELECT * FROM supplier_import_rows WHERE run_id = ? AND status = 'MATCHED' AND chosen_by_rule = 1");
$rowsSt->execute([$runId]);
$rows = $rowsSt->fetchAll();

$changedBy = (int)(current_user()['id'] ?? 0);
if ($changedBy <= 0) {
  $changedBy = null;
}

try {
  db()->beginTransaction();

  $stBefore = db()->prepare('SELECT supplier_cost, cost_type, units_per_pack FROM product_suppliers WHERE id = ? LIMIT 1');
  $stUpdate = db()->prepare("UPDATE product_suppliers
    SET supplier_cost = ?,
        cost_type = COALESCE(?, cost_type),
        units_per_pack = CASE
          WHEN COALESCE(?, cost_type) = 'PACK' THEN COALESCE(?, units_per_pack)
          ELSE NULL
        END,
        updated_at = NOW()
    WHERE id = ?");
  $stHist = db()->prepare('INSERT INTO product_supplier_cost_history(product_supplier_id, run_id, cost_before, cost_after, changed_by, note) VALUES(?, ?, ?, ?, ?, ?)');

  foreach ($rows as $row) {
    $psId = (int)$row['matched_product_supplier_id'];
    if ($psId <= 0) {
      continue;
    }

    $stBefore->execute([$psId]);
    $before = $stBefore->fetch();

    $normalized = (int)round((float)$row['normalized_unit_cost'], 0);
    $rawCostType = (string)($row['raw_cost_type'] ?? '');
    if (!in_array($rawCostType, ['UNIDAD', 'PACK'], true)) {
      $rawCostType = null;
    }

    $rawUnits = $row['raw_units_per_pack'] !== null ? (int)$row['raw_units_per_pack'] : null;
    if ($rawUnits !== null && $rawUnits <= 0) {
      $rawUnits = null;
    }

    $stUpdate->execute([$normalized, $rawCostType, $rawCostType, $rawUnits, $psId]);
    $stHist->execute([$psId, $runId, $before['supplier_cost'] ?? null, $normalized, $changedBy, 'supplier import apply']);
  }

  $stDone = db()->prepare('UPDATE supplier_import_runs SET applied_at = NOW() WHERE id = ?');
  $stDone->execute([$runId]);

  db()->commit();
} catch (Throwable $t) {
  if (db()->inTransaction()) {
    db()->rollBack();
  }
  abort(500, 'No se pudo aplicar la importación.');
}

redirect('supplier_import_preview.php?run_id=' . $runId);
