<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_product_suppliers_schema();

$pdo = db();
$id = (int)get('id', post('id', '0'));
if ($id <= 0) {
  abort(400, 'Proveedor inválido.');
}

$st = $pdo->prepare('SELECT id, name, global_adjust_percent, global_adjust_enabled FROM suppliers WHERE id = ? LIMIT 1');
$st->execute([$id]);
$supplier = $st->fetch();
if (!$supplier) {
  abort(404, 'Proveedor no encontrado.');
}

$error = '';
$message = '';
$form = [
  'global_adjust_enabled' => (int)$supplier['global_adjust_enabled'] === 1 ? '1' : '0',
  'global_adjust_percent' => number_format((float)$supplier['global_adjust_percent'], 2, '.', ''),
];

if (is_post() && post('action') === 'save_supplier_adjust') {
  $form['global_adjust_enabled'] = post('global_adjust_enabled', '0') === '1' ? '1' : '0';
  $form['global_adjust_percent'] = trim((string)post('global_adjust_percent', '0'));

  if ($form['global_adjust_percent'] === '' || !is_numeric($form['global_adjust_percent'])) {
    $error = 'Porcentaje (%) inválido.';
  } else {
    $percent = round((float)$form['global_adjust_percent'], 2);
    $enabled = (int)$form['global_adjust_enabled'];

    try {
      $st = $pdo->prepare('UPDATE suppliers SET global_adjust_enabled = ?, global_adjust_percent = ?, updated_at = NOW() WHERE id = ?');
      $st->execute([$enabled, $percent, $id]);
      $form['global_adjust_percent'] = number_format($percent, 2, '.', '');
      $message = 'Ajuste global actualizado.';
    } catch (Throwable $t) {
      $error = 'No se pudo guardar el ajuste global.';
    }
  }
}

$examplePercent = (float)$form['global_adjust_percent'];
$examplePrefix = $examplePercent >= 0 ? '+' : '';
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
        <h2 class="page-title">Ajuste global por proveedor</h2>
        <span class="muted">Proveedor: <?= e($supplier['name']) ?></span>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="suppliers.php">Volver</a>
      </div>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>

    <div class="card">
      <form method="post" class="stack">
        <input type="hidden" name="action" value="save_supplier_adjust">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <label class="form-field">
          <span class="form-label">Proveedor</span>
          <input class="form-control" type="text" value="<?= e($supplier['name']) ?>" readonly>
        </label>

        <label class="form-field">
          <span class="form-label">Ajuste global</span>
          <select class="form-control" name="global_adjust_enabled">
            <option value="1" <?= $form['global_adjust_enabled'] === '1' ? 'selected' : '' ?>>Activo</option>
            <option value="0" <?= $form['global_adjust_enabled'] === '0' ? 'selected' : '' ?>>Inactivo</option>
          </select>
        </label>

        <label class="form-field">
          <span class="form-label">Porcentaje (%)</span>
          <input class="form-control" type="number" name="global_adjust_percent" step="0.01" min="-1000" max="1000" value="<?= e($form['global_adjust_percent']) ?>" required>
        </label>

        <p class="muted">Se aplica sobre el costo actual guardado del proveedor. No modifica el costo almacenado; solo afecta el cálculo del costo efectivo y los precios por sitio.</p>
        <p class="muted">Ejemplo: con ajuste activo: <?= e($examplePrefix . number_format($examplePercent, 2, '.', '')) ?>%</p>

        <div class="inline-actions">
          <button class="btn" type="submit">Guardar</button>
          <a class="btn btn-ghost" href="suppliers.php">Volver</a>
        </div>
      </form>
    </div>
  </div>
</main>
</body>
</html>
