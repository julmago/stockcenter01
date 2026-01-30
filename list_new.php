<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$error = '';
if (is_post()) {
  $name = post('name');
  if ($name === '') $error = 'Poné un nombre para el listado.';
  else {
    $u = current_user();
    $st = db()->prepare("INSERT INTO stock_lists(name, created_by) VALUES(?, ?)");
    $st->execute([$name, (int)$u['id']]);
    $id = (int)db()->lastInsertId();
    redirect("list_view.php?id={$id}");
  }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Nuevo Listado</title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>

<div style="padding:10px;">
  <h2>Nuevo Listado</h2>
  <?php if ($error): ?><p style="color:red;"><?= e($error) ?></p><?php endif; ?>
  <form method="post">
    <div>
      <label>Nombre</label><br>
      <input type="text" name="name" value="<?= e(post('name')) ?>" required>
    </div>
    <div style="margin-top:10px;">
      <button type="submit">Crear</button>
      <a href="dashboard.php">Cancelar</a>
    </div>
  </form>
</div>
</body>
</html>
