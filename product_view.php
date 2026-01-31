<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$id = (int)get('id','0');
if ($id <= 0) abort(400, 'Falta id.');

$st = db()->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
$st->execute([$id]);
$product = $st->fetch();
if (!$product) abort(404, 'Producto no encontrado.');

$error = '';
$message = '';

if (is_post() && post('action') === 'update') {
  $sku = post('sku');
  $name = post('name');
  $brand = post('brand');

  if ($sku === '' || $name === '') {
    $error = 'SKU y Nombre son obligatorios.';
  } else {
    try {
      $st = db()->prepare("UPDATE products SET sku=?, name=?, brand=?, updated_at=NOW() WHERE id=?");
      $st->execute([$sku,$name,$brand,$id]);
      $message = 'Producto actualizado.';
    } catch (Throwable $t) {
      $error = 'No se pudo actualizar. Puede que el SKU ya exista.';
    }
  }
}

if (is_post() && post('action') === 'add_code') {
  $code = post('code');
  $code_type = post('code_type');
  if (!in_array($code_type, ['BARRA','MPN'], true)) {
    $code_type = 'BARRA';
  }
  if ($code === '') $error = 'Escaneá un código.';
  else {
    try {
      $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, ?)");
      $st->execute([$id, $code, $code_type]);
      $message = 'Código agregado.';
    } catch (Throwable $t) {
      $error = 'Ese código ya existe en otro producto.';
    }
  }
}

if (is_post() && post('action') === 'delete_code') {
  $code_id = (int)post('code_id','0');
  if ($code_id > 0) {
    $st = db()->prepare("DELETE FROM product_codes WHERE id = ? AND product_id = ?");
    $st->execute([$code_id, $id]);
    $message = 'Código eliminado.';
  }
}

// recargar
$st = db()->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
$st->execute([$id]);
$product = $st->fetch();

$st = db()->prepare("SELECT id, code, code_type, created_at FROM product_codes WHERE product_id = ? ORDER BY id DESC");
$st->execute([$id]);
$codes = $st->fetchAll();

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Producto <?= e($product['sku']) ?></title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>

<div style="padding:10px;">
  <h2>Producto</h2>

  <?php if ($message): ?><p style="color:green;"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p style="color:red;"><?= e($error) ?></p><?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="update">
    <div>
      <label>SKU</label><br>
      <input type="text" name="sku" value="<?= e($product['sku']) ?>" required>
    </div>
    <div style="margin-top:6px;">
      <label>Nombre</label><br>
      <input type="text" name="name" value="<?= e($product['name']) ?>" required>
    </div>
    <div style="margin-top:6px;">
      <label>Marca</label><br>
      <input type="text" name="brand" value="<?= e($product['brand']) ?>">
    </div>
    <div style="margin-top:10px;">
      <button type="submit">Guardar cambios</button>
      <a href="product_list.php">Volver</a>
    </div>
  </form>

  <hr>

  <h3>Códigos</h3>
  <form method="post">
    <input type="hidden" name="action" value="add_code">
    <input type="text" name="code" placeholder="Escaneá código" autofocus>
    <select name="code_type">
      <option value="BARRA">BARRA</option>
      <option value="MPN">MPN</option>
    </select>
    <button type="submit">Agregar</button>
  </form>

  <table border="1" cellpadding="6" cellspacing="0" style="margin-top:10px;">
    <thead>
      <tr><th>código</th><th>tipo</th><th>fecha</th><th>acciones</th></tr>
    </thead>
    <tbody>
      <?php if (!$codes): ?>
        <tr><td colspan="4">Sin códigos todavía.</td></tr>
      <?php else: ?>
        <?php foreach ($codes as $c): ?>
          <tr>
            <td><?= e($c['code']) ?></td>
            <td><?= e($c['code_type']) ?></td>
            <td><?= e($c['created_at']) ?></td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="delete_code">
                <input type="hidden" name="code_id" value="<?= (int)$c['id'] ?>">
                <button type="submit">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

</div>

</body>
</html>
