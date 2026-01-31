<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$list_id = (int)get('id','0');
if ($list_id <= 0) abort(400, 'Falta id de listado.');

$st = db()->prepare("
  SELECT sl.*, u.first_name, u.last_name
  FROM stock_lists sl
  JOIN users u ON u.id = sl.created_by
  WHERE sl.id = ?
  LIMIT 1
");
$st->execute([$list_id]);
$list = $st->fetch();
if (!$list) abort(404, 'Listado no encontrado.');

$message = '';
$error = '';
$unknown_code = '';

// Toggle open/closed
if (is_post() && post('action') === 'toggle_status') {
  $new = ($list['status'] === 'open') ? 'closed' : 'open';
  $st = db()->prepare("UPDATE stock_lists SET status = ? WHERE id = ?");
  $st->execute([$new, $list_id]);
  redirect("list_view.php?id={$list_id}");
}

// Scan code
if (is_post() && post('action') === 'scan') {
  if ($list['status'] !== 'open') {
    $error = 'El listado está cerrado. Abrilo para seguir cargando.';
  } else {
    $code = trim((string)post('code'));
    if ($code === '') {
      $error = 'Escaneá o pegá un código.';
    } else {
      $st = db()->prepare("
        SELECT pc.product_id, p.sku, p.name
        FROM product_codes pc
        JOIN products p ON p.id = pc.product_id
        WHERE pc.code_type = 'BARRA' AND LOWER(pc.code) = LOWER(?)
        LIMIT 1
      ");
      $st->execute([$code]);
      $found = $st->fetch();
      if (!$found) {
        $st = db()->prepare("
          SELECT p.id AS product_id, p.sku, p.name
          FROM products p
          WHERE LOWER(p.sku) = LOWER(?)
          LIMIT 1
        ");
        $st->execute([$code]);
        $found = $st->fetch();
      }

      if (!$found) {
        $st = db()->prepare("
          SELECT pc.product_id, p.sku, p.name
          FROM product_codes pc
          JOIN products p ON p.id = pc.product_id
          WHERE pc.code_type = 'MPN' AND LOWER(pc.code) = LOWER(?)
          LIMIT 1
        ");
        $st->execute([$code]);
        $found = $st->fetch();
      }

      if (!$found) {
        $error = 'Producto no encontrado';
      } else {
        $pid = (int)$found['product_id'];
        // upsert item
        $st = db()->prepare("INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, 1)
          ON DUPLICATE KEY UPDATE qty = qty + 1, updated_at = NOW()");
        $st->execute([$list_id, $pid]);
        $message = "Sumado: {$found['sku']} - {$found['name']}";
      }
    }
  }
}

// Asociar código a producto existente (desde el cartel)
if (is_post() && post('action') === 'associate_code') {
  if ($list['status'] !== 'open') {
    $error = 'El listado está cerrado. Abrilo para seguir cargando.';
  } else {
    $code = post('unknown_code');
    $product_id = (int)post('product_id','0');
    if ($code === '' || $product_id <= 0) {
      $error = 'Falta código o producto.';
    } else {
      // Insert code (si ya existe, error por UNIQUE)
      try {
        $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, 'BARRA')");
        $st->execute([$product_id, $code]);
      } catch (Throwable $t) {
        $error = 'Ese código ya está asignado a otro producto.';
      }
      if (!$error) {
        // Sumar al listado
        $st = db()->prepare("INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, 1)
          ON DUPLICATE KEY UPDATE qty = qty + 1, updated_at = NOW()");
        $st->execute([$list_id, $product_id]);
        $message = "Código asociado y sumado al listado.";
      }
    }
  }
}

// Crear producto nuevo desde el cartel
if (is_post() && post('action') === 'create_product_from_code') {
  if ($list['status'] !== 'open') {
    $error = 'El listado está cerrado. Abrilo para seguir cargando.';
  } else {
    $code = post('unknown_code');
    $sku  = post('new_sku');
    $name = post('new_name');
    $brand = post('new_brand');
    if ($code === '' || $sku === '' || $name === '') {
      $error = 'Completá al menos SKU y Nombre para crear el producto.';
    } else {
      try {
        db()->beginTransaction();
        $st = db()->prepare("INSERT INTO products(sku, name, brand, updated_at) VALUES(?, ?, ?, NOW())");
        $st->execute([$sku, $name, $brand]);
        $pid = (int)db()->lastInsertId();

        $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, 'BARRA')");
        $st->execute([$pid, $code]);

        $st = db()->prepare("INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, 1)
          ON DUPLICATE KEY UPDATE qty = qty + 1, updated_at = NOW()");
        $st->execute([$list_id, $pid]);

        db()->commit();
        $message = "Producto creado y sumado al listado.";
      } catch (Throwable $t) {
        if (db()->inTransaction()) db()->rollBack();
        $error = 'No se pudo crear. Verificá que el SKU y el código no estén repetidos.';
      }
    }
  }
}

// Volver a cargar list data after actions
$st = db()->prepare("
  SELECT sl.*, u.first_name, u.last_name
  FROM stock_lists sl
  JOIN users u ON u.id = sl.created_by
  WHERE sl.id = ?
  LIMIT 1
");
$st->execute([$list_id]);
$list = $st->fetch();

$items = db()->prepare("
  SELECT p.sku, p.name, i.qty, i.updated_at
  FROM stock_list_items i
  JOIN products p ON p.id = i.product_id
  WHERE i.stock_list_id = ?
  ORDER BY i.updated_at DESC, p.name ASC
");
$items->execute([$list_id]);
$items = $items->fetchAll();

$total_units = 0;
foreach ($items as $it) $total_units += (int)$it['qty'];

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Listado #<?= (int)$list['id'] ?></title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>

<div style="padding:10px;">
  <h2>Listado #<?= (int)$list['id'] ?></h2>

  <?php if ($message): ?><p style="color:green;"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p style="color:red;"><?= e($error) ?></p><?php endif; ?>

  <div style="border:1px solid #ccc; padding:10px;">
    <div><strong>id:</strong> <?= (int)$list['id'] ?></div>
    <div><strong>fecha:</strong> <?= e($list['created_at']) ?></div>
    <div><strong>nombre:</strong> <?= e($list['name']) ?></div>
    <div><strong>creador:</strong> <?= e($list['first_name'] . ' ' . $list['last_name']) ?></div>
    <div><strong>sync:</strong> <?= $list['sync_target'] === 'prestashop' ? 'prestashop' : '' ?></div>
    <div><strong>estado:</strong> <?= $list['status'] === 'open' ? 'Abierto' : 'Cerrado' ?></div>

    <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
      <a href="download_excel.php?id=<?= (int)$list['id'] ?>">Descargar Excel</a>

      <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="toggle_status">
        <button type="submit"><?= $list['status'] === 'open' ? 'Cerrar' : 'Abrir' ?></button>
      </form>

      <form method="post" style="display:inline;" action="ps_sync.php?id=<?= (int)$list['id'] ?>">
        <button type="submit" <?= $list['sync_target'] === 'prestashop' ? 'disabled' : '' ?>>
          Sincronizar a PrestaShop
        </button>
        <?php if ($list['sync_target'] === 'prestashop'): ?>
          <small>(bloqueado: ya sincronizado)</small>
        <?php endif; ?>
      </form>
    </div>

    <div style="margin-top:10px;">
      <strong>Total unidades:</strong> <?= (int)$total_units ?> |
      <strong>Productos distintos:</strong> <?= count($items) ?>
    </div>
  </div>

  <?php if ($unknown_code !== ''): ?>
    <div style="border:2px solid #f00; padding:10px; margin-top:12px;">
      <h3>Código no encontrado</h3>
      <p><strong>Código:</strong> <?= e($unknown_code) ?></p>

      <h4>1) Asociar a un producto existente</h4>
      <form method="post">
        <input type="hidden" name="action" value="associate_code">
        <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
        <div>
          <label>Buscar producto (por nombre / sku / marca)</label><br>
          <input type="text" name="product_search" value="<?= e(post('product_search')) ?>" placeholder="Escribí y buscá abajo" />
          <button type="submit" formaction="list_view.php?id=<?= (int)$list_id ?>&show_search=1">Buscar</button>
        </div>
        <?php
          // Si show_search=1, mostramos resultados del buscador para seleccionar
          $show_search = get('show_search','') === '1';
          if ($show_search) {
            $ps = trim((string)post('product_search'));
            if ($ps !== '') {
              $like = '%' . $ps . '%';
              $stp = db()->prepare("SELECT id, sku, name, brand FROM products WHERE sku LIKE ? OR name LIKE ? OR brand LIKE ? ORDER BY name ASC LIMIT 200");
              $stp->execute([$like,$like,$like]);
              $res = $stp->fetchAll();
              if ($res) {
                echo '<div style="margin-top:8px;">';
                echo '<label>Elegí producto:</label><br>';
                echo '<select name="product_id" required>';
                echo '<option value="">-- seleccionar --</option>';
                foreach ($res as $r) {
                  echo '<option value="'.(int)$r['id'].'">'.e($r['sku'].' | '.$r['name'].' | '.$r['brand']).'</option>';
                }
                echo '</select>';
                echo '</div>';
                echo '<div style="margin-top:8px;"><button type="submit">Asociar y sumar +1</button></div>';
              } else {
                echo '<p>No hubo resultados con esa búsqueda.</p>';
              }
            } else {
              echo '<p>Escribí algo para buscar.</p>';
            }
          } else {
            echo '<p><small>Usá el botón Buscar para ver resultados y elegir el producto.</small></p>';
          }
        ?>
      </form>

      <h4 style="margin-top:14px;">2) Crear producto nuevo y sumar</h4>
      <form method="post">
        <input type="hidden" name="action" value="create_product_from_code">
        <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
        <div>
          <label>SKU (obligatorio)</label><br>
          <input type="text" name="new_sku" required>
        </div>
        <div style="margin-top:6px;">
          <label>Nombre (obligatorio)</label><br>
          <input type="text" name="new_name" required>
        </div>
        <div style="margin-top:6px;">
          <label>Marca</label><br>
          <input type="text" name="new_brand">
        </div>
        <div style="margin-top:8px;">
          <button type="submit">Crear y sumar +1</button>
          <a href="list_view.php?id=<?= (int)$list_id ?>">Cancelar</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div style="margin-top:16px;">
    <h3>Cargar por escaneo</h3>
    <form method="post">
      <input type="hidden" name="action" value="scan">
      <input type="text" name="code" autofocus placeholder="Escaneá acá (enter)..." <?= $list['status'] !== 'open' ? 'disabled' : '' ?>>
      <button type="submit" <?= $list['status'] !== 'open' ? 'disabled' : '' ?>>Sumar +1</button>
      <?php if ($list['status'] !== 'open'): ?>
        <small>(listado cerrado)</small>
      <?php endif; ?>
    </form>
  </div>

  <div style="margin-top:14px;">
    <h3>Items</h3>
    <table border="1" cellpadding="6" cellspacing="0">
      <thead>
        <tr><th>sku</th><th>nombre</th><th>cantidad</th></tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="3">Sin items todavía.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= e($it['sku']) ?></td>
              <td><?= e($it['name']) ?></td>
              <td><?= (int)$it['qty'] ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
</body>
</html>
