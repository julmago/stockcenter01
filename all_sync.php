<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/include/stock_sync.php';
require_login();
require_permission(can_sync_prestashop());

$list_id = (int)get('id','0');
if ($list_id <= 0) abort(400, 'Falta id de listado.');

$st = db()->prepare("SELECT * FROM stock_lists WHERE id = ? LIMIT 1");
$st->execute([$list_id]);
$list = $st->fetch();
if (!$list) abort(404, 'Listado no encontrado.');
if ($list['status'] !== 'open') abort(400, 'Este listado está cerrado y no se puede sincronizar.');

$st = db()->prepare("
  SELECT p.id AS product_id, p.sku, p.name, i.qty, i.synced_qty
  FROM stock_list_items i
  JOIN products p ON p.id = i.product_id
  WHERE i.stock_list_id = ?
  ORDER BY p.name ASC
");
$st->execute([$list_id]);
$items = $st->fetchAll();

$sites = stock_sync_active_sites();
$site_names = [];
$omitted_channels = [];
foreach ($sites as $site) {
  $site_id = (int)($site['id'] ?? 0);
  $site_name = trim((string)($site['name'] ?? 'Sitio #' . $site_id));
  if ($site_name === '') {
    $site_name = 'Sitio #' . $site_id;
  }
  $site_names[$site_id] = $site_name;

  $conn_enabled = (int)($site['conn_enabled'] ?? 0) === 1 || (int)($site['connection_enabled'] ?? 0) === 1;
  $sync_enabled = (int)($site['sync_stock_enabled'] ?? 0) === 1;
  $conn_type = stock_sync_conn_type($site);

  if (!$conn_enabled) {
    $omitted_channels[] = $site_name . ' (Sin conexión)';
    continue;
  }
  if (!$sync_enabled) {
    $omitted_channels[] = $site_name . ' (Habilitado=No)';
    continue;
  }
  if (!in_array($conn_type, ['prestashop', 'mercadolibre'], true)) {
    $omitted_channels[] = $site_name . ' (Canal omitido)';
  }
}

$results = [];
$ok = 0; $fail = 0; $skip = 0; $omitted = 0;
$total_sent = 0;
$pending_after_total = 0;

foreach ($items as $it) {
  $sku = (string)$it['sku'];
  $qty = (int)$it['qty'];
  $synced_qty = min((int)$it['synced_qty'], $qty);
  $pending_qty = $qty - $synced_qty;

  if ($pending_qty <= 0) {
    $results[] = [
      'sku' => $sku,
      'name' => $it['name'],
      'qty' => 0,
      'status' => 'SIN PENDIENTE',
      'progress' => $synced_qty . '/' . $qty,
      'detail' => 'No hay unidades nuevas para enviar.',
    ];
    $skip++;
    continue;
  }

  try {
    $push_status = sync_push_stock_to_sites_by_product((int)$it['product_id'], $sku, $qty, null);
    $attempted = count($push_status);

    if ($attempted <= 0) {
      $pending_after_total += $pending_qty;
      $detail = count($omitted_channels) > 0
        ? 'Sin canales activos para enviar. Omitidos: ' . implode(' | ', $omitted_channels)
        : 'Sin canales activos para enviar.';
      $results[] = [
        'sku' => $sku,
        'name' => $it['name'],
        'qty' => 0,
        'status' => 'OMITIDO',
        'progress' => $synced_qty . '/' . $qty,
        'detail' => $detail,
      ];
      $omitted++;
      continue;
    }

    $errors = [];
    foreach ($push_status as $channel_result) {
      if (!($channel_result['ok'] ?? false)) {
        $site_id = (int)($channel_result['site_id'] ?? 0);
        $site_name = $site_names[$site_id] ?? ('Sitio #' . $site_id);
        $err = trim((string)($channel_result['error'] ?? 'Error desconocido.'));
        $errors[] = $site_name . ': ' . $err;
      }
    }

    if (count($errors) > 0) {
      $pending_after_total += $pending_qty;
      $results[] = [
        'sku' => $sku,
        'name' => $it['name'],
        'qty' => 0,
        'status' => 'ERROR',
        'progress' => $synced_qty . '/' . $qty,
        'detail' => implode(' | ', $errors),
      ];
      $fail++;
      continue;
    }

    $st = db()->prepare("UPDATE stock_list_items SET synced_qty = LEAST(qty, synced_qty + ?) WHERE stock_list_id = ? AND product_id = ?");
    $st->execute([$pending_qty, $list_id, (int)$it['product_id']]);

    $new_synced_qty = min($qty, $synced_qty + $pending_qty);
    $total_sent += $pending_qty;
    $pending_after_total += max(0, $qty - $new_synced_qty);

    $detail = "Canales OK: {$attempted}";
    if (count($omitted_channels) > 0) {
      $detail .= ' | Omitidos: ' . implode(' | ', $omitted_channels);
    }

    $results[] = [
      'sku' => $sku,
      'name' => $it['name'],
      'qty' => $pending_qty,
      'status' => 'OK',
      'progress' => $new_synced_qty . '/' . $qty,
      'detail' => $detail,
    ];
    $ok++;
  } catch (Throwable $t) {
    $pending_after_total += $pending_qty;
    $results[] = [
      'sku' => $sku,
      'name' => $it['name'],
      'qty' => 0,
      'status' => 'ERROR',
      'progress' => $synced_qty . '/' . $qty,
      'detail' => $t->getMessage(),
    ];
    $fail++;
  }
}

$synced = $fail === 0 && $total_sent > 0;
if ($total_sent > 0) {
  $st = db()->prepare("UPDATE stock_lists SET sync_target='all', synced_at=NOW() WHERE id = ?");
  $st->execute([$list_id]);
}
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
      <h2 class="page-title">Sincronización completa (Todos los canales)</h2>
      <span class="muted">Listado #<?= (int)$list_id ?></span>
    </div>

    <div class="card stack">
      <div class="form-row">
        <div><strong>Total enviados en esta sincronización:</strong> <?= (int)$total_sent ?></div>
        <div><strong>Pendiente después de sincronizar:</strong> <?= (int)$pending_after_total ?></div>
        <div><strong>Productos omitidos:</strong> <?= (int)$omitted ?></div>
      </div>

      <?php if ($synced): ?>
        <div class="alert alert-success"><strong>OK:</strong> sincronización completa.</div>
      <?php else: ?>
        <div class="alert alert-danger"><strong>Atención:</strong> hubo errores (<?= (int)$fail ?>).</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr><th>SKU</th><th>Nombre</th><th>Enviado</th><th>Progreso</th><th>Resultado</th><th>Detalle</th></tr>
          </thead>
          <tbody>
            <?php foreach ($results as $r): ?>
              <tr>
                <td><?= e($r['sku']) ?></td>
                <td><?= e($r['name']) ?></td>
                <td><?= (int)$r['qty'] ?></td>
                <td><?= e($r['progress']) ?></td>
                <td>
                  <?php if ($r['status'] === 'OK'): ?>
                    <span class="badge badge-success"><?= e($r['status']) ?></span>
                  <?php elseif ($r['status'] === 'SIN PENDIENTE' || $r['status'] === 'OMITIDO'): ?>
                    <span class="badge badge-muted"><?= e($r['status']) ?></span>
                  <?php else: ?>
                    <span class="badge badge-danger"><?= e($r['status']) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= e($r['detail']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$results): ?>
              <tr><td colspan="6">El listado no tiene items.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="form-actions">
        <a class="btn btn-ghost" href="list_view.php?id=<?= (int)$list_id ?>">Volver al listado</a>
      </div>
    </div>
  </div>
</main>
</body>
</html>
