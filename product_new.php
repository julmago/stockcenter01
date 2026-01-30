<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

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
        $st = db()->prepare("INSERT INTO product_codes(product_id, code) VALUES(?, ?)");
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
<head><meta charset="utf-8"><title>Nuevo Producto</title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>

<div style="padding:10px;">
  <h2>Nuevo Producto</h2>
  <?php if ($error): ?><p style="color:red;"><?= e($error) ?></p><?php endif; ?>

  <form method="post">
    <div>
      <label>SKU (primordial)</label><br>
      <input type="text" name="sku" required>
    </div>
    <div style="margin-top:6px;">
      <label>Nombre</label><br>
      <input type="text" name="name" required>
    </div>
    <div style="margin-top:6px;">
      <label>Marca</label><br>
      <input type="text" name="brand">
    </div>

    <div style="margin-top:10px;border-top:1px solid #ccc;padding-top:10px;">
      <label>Primer código (opcional, luego podés cargar más)</label><br>
      <input type="text" name="code" placeholder="Escaneá código">
    </div>

    <div style="margin-top:12px;">
      <button type="submit">Crear</button>
      <a href="dashboard.php">Volver</a>
    </div>
  </form>
</div>

</body>
</html>
