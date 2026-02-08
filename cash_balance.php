<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();
require_permission(hasPerm('cashbox_view_balance'), 'Sin permiso para ver el balance.');

$cashbox = require_cashbox_selected();

$st = db()->prepare("SELECT
  SUM(CASE WHEN type = 'entry' THEN amount ELSE 0 END) AS total_entries,
  SUM(CASE WHEN type = 'exit' THEN amount ELSE 0 END) AS total_exits
  FROM cash_movements
  WHERE cashbox_id = ?");
$st->execute([(int)$cashbox['id']]);
$totals = $st->fetch();
$total_entries = (float)($totals['total_entries'] ?? 0);
$total_exits = (float)($totals['total_exits'] ?? 0);
$balance = $total_entries - $total_exits;
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
      <h2 class="page-title">Balance de caja</h2>
      <span class="muted">Caja activa: <?= e($cashbox['name']) ?></span>
    </div>

    <div class="grid grid-3">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Total Entradas</h3>
        </div>
        <strong style="font-size: 1.4rem;">$<?= number_format($total_entries, 2, ',', '.') ?></strong>
      </div>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Total Salidas</h3>
        </div>
        <strong style="font-size: 1.4rem;">$<?= number_format($total_exits, 2, ',', '.') ?></strong>
      </div>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Balance</h3>
        </div>
        <strong style="font-size: 1.4rem;">$<?= number_format($balance, 2, ',', '.') ?></strong>
      </div>
    </div>

    <div class="form-actions" style="margin-top: var(--space-4);">
      <a class="btn btn-ghost" href="<?= url_path('cash_select.php') ?>">Volver</a>
    </div>
  </div>
</main>

</body>
</html>
