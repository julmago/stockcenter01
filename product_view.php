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
$can_edit = can_edit_product();
$can_add_code = can_add_code();

if (is_post() && post('action') === 'update') {
  require_permission($can_edit);
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
  require_permission($can_add_code);
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
  require_permission($can_add_code);
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
      <h2 class="page-title">Producto</h2>
      <span class="muted">SKU <?= e($product['sku']) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <?php if ($can_edit): ?>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="update">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">SKU</label>
              <input class="form-control" type="text" name="sku" value="<?= e($product['sku']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Nombre</label>
              <input class="form-control" type="text" name="name" value="<?= e($product['name']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Marca</label>
              <input class="form-control" type="text" name="brand" value="<?= e($product['brand']) ?>">
            </div>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Guardar cambios</button>
            <a class="btn btn-ghost" href="product_list.php">Volver</a>
          </div>
        </form>
      <?php else: ?>
        <div class="stack">
          <div><strong>SKU:</strong> <?= e($product['sku']) ?></div>
          <div><strong>Nombre:</strong> <?= e($product['name']) ?></div>
          <div><strong>Marca:</strong> <?= e($product['brand']) ?></div>
          <div class="form-actions">
            <a class="btn btn-ghost" href="product_list.php">Volver</a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Códigos</h3>
        <span class="muted small"><?= count($codes) ?> registrados</span>
      </div>
      <?php if ($can_add_code): ?>
        <form method="post" class="form-row">
          <input type="hidden" name="action" value="add_code">
          <div class="form-group">
            <label class="form-label">Código</label>
            <input class="form-control" type="text" name="code" placeholder="Escaneá código" autofocus>
          </div>
          <div class="form-group">
            <label class="form-label">Tipo</label>
            <select name="code_type">
              <option value="BARRA">BARRA</option>
              <option value="MPN">MPN</option>
            </select>
          </div>
          <div class="form-group" style="align-self:end;">
            <button class="btn" type="submit">Agregar</button>
          </div>
        </form>
      <?php endif; ?>

      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>código</th>
              <th>tipo</th>
              <th>fecha</th>
              <?php if ($can_add_code): ?>
                <th>acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$codes): ?>
              <tr><td colspan="<?= $can_add_code ? 4 : 3 ?>">Sin códigos todavía.</td></tr>
            <?php else: ?>
              <?php foreach ($codes as $c): ?>
                <tr>
                  <td><?= e($c['code']) ?></td>
                  <td><?= e($c['code_type']) ?></td>
                  <td><?= e($c['created_at']) ?></td>
                  <?php if ($can_add_code): ?>
                    <td class="table-actions">
                      <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="delete_code">
                        <input type="hidden" name="code_id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-danger" type="submit">Eliminar</button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php require_once __DIR__ . '/include/partials/messages_block.php'; ?>
    <?php ts_messages_block('product', $id); ?>
  </div>
</main>

</body>
</html>
