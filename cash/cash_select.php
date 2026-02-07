<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();
require_permission(hasPerm('cashbox_access'), 'Sin permiso para acceder a Caja.');

$message = '';
$error = '';
$cashboxes = fetch_active_cashboxes();
$active_cashbox_id = cashbox_selected_id();

if (isset($_GET['error']) && $_GET['error'] === 'invalid') {
  $error = 'No se pudo seleccionar la caja. Verificá que esté activa.';
}

if (is_post() && post('action') === 'select_cashbox') {
  $selected_id = (int)post('cashbox_id');
  $selected_cashbox = fetch_cashbox_by_id($selected_id, true);
  if (!$selected_cashbox) {
    $error = 'Seleccioná una caja activa.';
  } else {
    $_SESSION['cashbox_id'] = $selected_id;
    $active_cashbox_id = $selected_id;
    $message = 'Caja seleccionada correctamente.';
  }
}

$active_cashbox = $active_cashbox_id > 0 ? fetch_cashbox_by_id($active_cashbox_id, false) : null;
$active_cashbox_name = $active_cashbox ? $active_cashbox['name'] : 'Sin seleccionar';
$can_view_balance = hasPerm('cashbox_view_balance');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Caja</h2>
      <span class="muted">Caja activa: <?= e($active_cashbox_name) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Seleccionar caja</h3>
      </div>
      <?php if ($cashboxes): ?>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="select_cashbox">
          <div class="form-row">
            <div class="form-group" style="min-width: 260px;">
              <label class="form-label">Caja disponible</label>
              <select class="form-control" name="cashbox_id" required>
                <option value="" disabled <?= $active_cashbox_id === 0 ? 'selected' : '' ?>>Elegí una caja</option>
                <?php foreach ($cashboxes as $cashbox): ?>
                  <?php $cashbox_id = (int)$cashbox['id']; ?>
                  <option value="<?= $cashbox_id ?>" <?= $cashbox_id === $active_cashbox_id ? 'selected' : '' ?>>
                    <?= e($cashbox['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Guardar selección</button>
          </div>
        </form>
      <?php else: ?>
        <div class="alert alert-info">No hay cajas activas disponibles. Contactá a un administrador.</div>
      <?php endif; ?>
    </div>

    <div class="cash-actions">
      <a class="cash-action-card" href="cash_entry.php">ENTRADA</a>
      <a class="cash-action-card" href="cash_exit.php">SALIDA</a>
      <?php if ($can_view_balance): ?>
        <a class="cash-action-card" href="cash_balance.php">CAJA</a>
      <?php endif; ?>
    </div>
  </div>
</main>

</body>
</html>
