<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/prestashop.php';
require_login();

$list_id = (int)get('id','0');
if ($list_id <= 0) abort(400, 'Falta id de listado.');

$st = db()->prepare("SELECT * FROM stock_lists WHERE id = ? LIMIT 1");
$st->execute([$list_id]);
$list = $st->fetch();
if (!$list) abort(404, 'Listado no encontrado.');
if ($list['sync_target'] === 'prestashop') abort(400, 'Este listado ya fue sincronizado y está bloqueado.');
if ($list['status'] !== 'open' && $list['status'] !== 'closed') abort(400, 'Estado inválido.');

$st = db()->prepare("
  SELECT p.sku, p.name, i.qty
  FROM stock_list_items i
  JOIN products p ON p.id = i.product_id
  WHERE i.stock_list_id = ?
  ORDER BY p.name ASC
");
$st->execute([$list_id]);
$items = $st->fetchAll();

$results = [];
$ok = 0; $fail = 0;
$mode = ps_mode(); // replace|add

foreach ($items as $it) {
  $sku = (string)$it['sku'];
  $qty = (int)$it['qty'];

  try {
    $match = ps_find_by_reference($sku);
    if (!$match) {
      throw new RuntimeException("No existe en PrestaShop por reference/SKU.");
    }

    $id_product = (int)$match['id_product'];
    $id_attr = (int)$match['id_product_attribute'];

    $id_sa = ps_find_stock_available_id($id_product, $id_attr);
    if (!$id_sa) {
      throw new RuntimeException("No se encontró stock_available para id_product={$id_product}, id_attr={$id_attr}.");
    }

    if ($mode === 'add') {
      $cur = ps_get_stock_available($id_sa);
      $new_qty = (int)$cur['qty'] + $qty;
    } else {
      $new_qty = $qty;
    }

    if ($new_qty < 0) $new_qty = 0;

    ps_update_stock_available_quantity($id_sa, $new_qty);

    $results[] = ['sku'=>$sku,'name'=>$it['name'],'qty'=>$qty,'status'=>'OK','detail'=>"stock_available #{$id_sa} => {$new_qty}"];
    $ok++;
  } catch (Throwable $t) {
    $results[] = ['sku'=>$sku,'name'=>$it['name'],'qty'=>$qty,'status'=>'ERROR','detail'=>$t->getMessage()];
    $fail++;
  }
}

// Si todo OK, marcamos sincronizado (y bloqueamos)
if ($fail === 0) {
  $st = db()->prepare("UPDATE stock_lists SET sync_target='prestashop', synced_at=NOW() WHERE id = ?");
  $st->execute([$list_id]);
  $synced = true;
} else {
  $synced = false;
}

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Sincronización PrestaShop</title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>
<div style="padding:10px;">
  <h2>Sincronización PrestaShop - Listado #<?= (int)$list_id ?></h2>
  <p><strong>Modo:</strong> <?= e($mode === 'add' ? 'Sumar (+)' : 'Reemplazar (=)') ?></p>

  <?php if ($synced): ?>
    <p style="color:green;"><strong>OK:</strong> sincronización completa. El listado quedó bloqueado para re-sincronizar.</p>
  <?php else: ?>
    <p style="color:red;"><strong>Atención:</strong> hubo errores (<?= (int)$fail ?>). No se marcó como sincronizado.</p>
    <p><small>Solución típica: corregí SKUs/reference en PrestaShop o en tu catálogo y volvé a intentar.</small></p>
  <?php endif; ?>

  <table border="1" cellpadding="6" cellspacing="0" style="margin-top:10px;">
    <thead>
      <tr><th>SKU</th><th>Nombre</th><th>Cant.</th><th>Resultado</th><th>Detalle</th></tr>
    </thead>
    <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?= e($r['sku']) ?></td>
          <td><?= e($r['name']) ?></td>
          <td><?= (int)$r['qty'] ?></td>
          <td><?= e($r['status']) ?></td>
          <td><?= e($r['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$results): ?>
        <tr><td colspan="5">El listado no tiene items.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top:12px;">
    <a href="list_view.php?id=<?= (int)$list_id ?>">Volver al listado</a>
  </div>
</div>
</body>
</html>
