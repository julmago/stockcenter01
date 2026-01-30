<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$q = get('q','');
$params = [];
$products = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $st = db()->prepare("SELECT id, sku, name, brand FROM products WHERE sku LIKE ? OR name LIKE ? OR brand LIKE ? ORDER BY name ASC LIMIT 200");
  $st->execute([$like,$like,$like]);
  $products = $st->fetchAll();
}

$st = db()->query("
  SELECT sl.id, sl.created_at, sl.name, sl.sync_target, sl.status,
         u.first_name, u.last_name
  FROM stock_lists sl
  JOIN users u ON u.id = sl.created_by
  ORDER BY sl.id DESC
  LIMIT 200
");
$lists = $st->fetchAll();
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Dashboard</title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>

<div style="padding:10px;">
  <h2>Principal</h2>

  <h3>Buscador de productos</h3>
  <form method="get" action="dashboard.php">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por sku, nombre o marca">
    <button type="submit">Buscar</button>
    <?php if ($q !== ''): ?>
      <a href="dashboard.php">Limpiar</a>
    <?php endif; ?>
  </form>

  <?php if ($q !== ''): ?>
    <h4>Resultados</h4>
    <?php if (!$products): ?>
      <p>No se encontraron productos.</p>
    <?php else: ?>
      <table border="1" cellpadding="6" cellspacing="0">
        <thead>
          <tr><th>SKU</th><th>Nombre</th><th>Marca</th></tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): ?>
            <tr>
              <td><a href="product_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['sku']) ?></a></td>
              <td><?= e($p['name']) ?></td>
              <td><?= e($p['brand']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  <h3 style="margin-top:22px;">Listados</h3>
  <table border="1" cellpadding="6" cellspacing="0">
    <thead>
      <tr>
        <th>id</th>
        <th>fecha</th>
        <th>nombre</th>
        <th>creador</th>
        <th>sync</th>
        <th>estado</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($lists as $l): ?>
        <tr style="cursor:pointer;" onclick="window.location='list_view.php?id=<?= (int)$l['id'] ?>'">
          <td><?= (int)$l['id'] ?></td>
          <td><?= e($l['created_at']) ?></td>
          <td><?= e($l['name']) ?></td>
          <td><?= e($l['first_name'] . ' ' . $l['last_name']) ?></td>
          <td><?= $l['sync_target'] === 'prestashop' ? 'prestashop' : '' ?></td>
          <td><?= $l['status'] === 'open' ? 'Abierto' : 'Cerrado' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

</body>
</html>
