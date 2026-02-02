<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
require_permission(can_create_product());

$error = '';
$message = '';

if (is_post()) {
  $sku = post('sku');
  $name = post('name');
  $brand = post('brand');
  $code = post('code');

  if ($sku === '' || $name === '') {
    $error = 'SKU y Nombre son obligatorios.';
  } else {
    try {
      db()->beginTransaction();
      $st = db()->prepare("INSERT INTO products(sku, name, brand, updated_at) VALUES(?, ?, ?, NOW())");
      $st->execute([$sku, $name, $brand]);
      $pid = (int)db()->lastInsertId();

      if ($code !== '') {
        $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, 'BARRA')");
        $st->execute([$pid, $code]);
      }

      db()->commit();
      redirect("product_view.php?id={$pid}");
    } catch (Throwable $t) {
      if (db()->inTransaction()) db()->rollBack();
      $error = 'No se pudo crear. Verificá que el SKU no esté repetido y que el código (si cargaste) no exista.';
    }
  }
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
      <h2 class="page-title">Nuevo Producto</h2>
      <span class="muted">Cargá la información base del producto.</span>
    </div>
    <div class="card">
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

      <form method="post" class="stack">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">SKU (primordial)</label>
            <input class="form-control" type="text" name="sku" required>
          </div>
          <div class="form-group">
            <label class="form-label">Nombre</label>
            <input class="form-control" type="text" name="name" required>
          </div>
          <div class="form-group">
            <label class="form-label">Marca</label>
            <input class="form-control" type="text" name="brand">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Primer código (opcional, luego podés cargar más)</label>
          <input class="form-control" type="text" name="code" placeholder="Escaneá código">
        </div>

        <div class="form-actions">
          <button class="btn" type="submit">Crear</button>
          <a class="btn btn-ghost" href="dashboard.php">Volver</a>
        </div>
      </form>
    </div>
  </div>
</main>

</body>
</html>
