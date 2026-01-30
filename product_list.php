<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$q = get('q','');
$params = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $st = db()->prepare("SELECT id, sku, name, brand FROM products WHERE sku LIKE ? OR name LIKE ? OR brand LIKE ? ORDER BY name ASC LIMIT 500");
  $st->execute([$like,$like,$like]);
  $products = $st->fetchAll();
} else {
  $st = db()->query("SELECT id, sku, name, brand FROM products ORDER BY name ASC LIMIT 500");
  $products = $st->fetchAll();
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Listado de productos</title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>

<div style="padding:10px;">
  <h2>Listado de productos</h2>

  <form method="get" action="product_list.php">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por sku, nombre o marca">
    <button type="submit">Buscar</button>
    <?php if ($q !== ''): ?><a href="product_list.php">Limpiar</a><?php endif; ?>
  </form>

  <table border="1" cellpadding="6" cellspacing="0" style="margin-top:10px;">
    <thead>
      <tr><th>sku</th><th>nombre</th><th>marca</th></tr>
    </thead>
    <tbody>
      <?php if (!$products): ?>
        <tr><td colspan="3">Sin productos.</td></tr>
      <?php else: ?>
        <?php foreach ($products as $p): ?>
          <tr style="cursor:pointer;" onclick="window.location='product_view.php?id=<?= (int)$p['id'] ?>'">
            <td><?= e($p['sku']) ?></td>
            <td><?= e($p['name']) ?></td>
            <td><?= e($p['brand']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

</body>
</html>
