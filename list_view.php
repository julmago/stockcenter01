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

$message = (string)get('msg', '');
$error = '';
$unknown_code = (string)post('unknown_code','');
$search_term = '';
$search_results = [];
$should_focus_scan = false;
$clear_scan_input = false;
$scan_mode = (string)post('scan_mode', 'add');

// Toggle open/closed
if (is_post() && post('action') === 'toggle_status') {
  $new = ($list['status'] === 'open') ? 'closed' : 'open';
  $st = db()->prepare("UPDATE stock_lists SET status = ? WHERE id = ?");
  $st->execute([$new, $list_id]);
  redirect("list_view.php?id={$list_id}");
}

// Delete item
if (is_post() && post('action') === 'delete_item') {
  $product_id = (int)post('product_id', '0');
  if ($product_id <= 0) {
    $error = 'Producto inválido.';
  } else {
    $st = db()->prepare("DELETE FROM stock_list_items WHERE stock_list_id = ? AND product_id = ?");
    $st->execute([$list_id, $product_id]);
    if ($st->rowCount() <= 0) {
      $error = 'El producto no está en el listado.';
    } else {
      $success = urlencode('Item eliminado.');
      redirect("list_view.php?id={$list_id}&msg={$success}");
    }
  }
}

// Scan code
if (is_post() && post('action') === 'scan') {
  $should_focus_scan = true;
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
        $unknown_code = $code;
      } else {
        $pid = (int)$found['product_id'];
        if ($scan_mode === 'subtract') {
          $st = db()->prepare("SELECT qty FROM stock_list_items WHERE stock_list_id = ? AND product_id = ? LIMIT 1");
          $st->execute([$list_id, $pid]);
          $item = $st->fetch();
          if (!$item) {
            $error = 'No se puede restar: el producto no está en el listado.';
          } elseif ((int)$item['qty'] <= 0) {
            $error = 'No se puede restar: la cantidad ya está en 0.';
          } elseif ((int)$item['qty'] === 1) {
            $st = db()->prepare("DELETE FROM stock_list_items WHERE stock_list_id = ? AND product_id = ?");
            $st->execute([$list_id, $pid]);
            $message = "Restado: {$found['sku']} - {$found['name']}";
            $clear_scan_input = true;
          } else {
            $st = db()->prepare("UPDATE stock_list_items SET qty = qty - 1, updated_at = NOW() WHERE stock_list_id = ? AND product_id = ?");
            $st->execute([$list_id, $pid]);
            $message = "Restado: {$found['sku']} - {$found['name']}";
            $clear_scan_input = true;
          }
        } else {
          // upsert item
          $st = db()->prepare("INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, 1)
            ON DUPLICATE KEY UPDATE qty = qty + 1, updated_at = NOW()");
          $st->execute([$list_id, $pid]);
          $message = "Sumado: {$found['sku']} - {$found['name']}";
          $clear_scan_input = true;
        }
      }
    }
  }
}

// Asociar código a producto existente (desde el cartel)
if (is_post() && post('action') === 'associate_code') {
  if ($list['status'] !== 'open') {
    $error = 'El listado está cerrado. Abrilo para seguir cargando.';
  } else {
    $code = trim((string)post('unknown_code'));
    $product_id = (int)post('product_id','0');
    if ($code === '' || $product_id <= 0) {
      $error = 'Falta código o producto.';
    } else {
      $st = db()->prepare("SELECT product_id FROM product_codes WHERE LOWER(code) = LOWER(?) LIMIT 1");
      $st->execute([$code]);
      $existing = $st->fetch();
      if ($existing && (int)$existing['product_id'] !== $product_id) {
        $error = 'Ese código ya está asignado a otro producto.';
      }
    }
    if (!$error) {
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
        $message = "Código asociado y sumado +1.";
        $unknown_code = '';
        $clear_scan_input = true;
        $should_focus_scan = true;
      }
    }
  }
}

// Crear producto nuevo desde el cartel
if (is_post() && post('action') === 'create_product_from_code') {
  if ($list['status'] !== 'open') {
    $error = 'El listado está cerrado. Abrilo para seguir cargando.';
  } else {
    $code = trim((string)post('unknown_code'));
    $sku  = trim((string)post('new_sku'));
    $name = trim((string)post('new_name'));
    $brand = trim((string)post('new_brand'));
    if ($code === '' || $sku === '' || $name === '') {
      $error = 'Completá al menos SKU y Nombre para crear el producto.';
    } else {
      try {
        db()->beginTransaction();
        $st = db()->prepare("SELECT product_id FROM product_codes WHERE LOWER(code) = LOWER(?) LIMIT 1");
        $st->execute([$code]);
        $existing = $st->fetch();
        if ($existing) {
          throw new RuntimeException('CODE_USED');
        }
        $st = db()->prepare("INSERT INTO products(sku, name, brand, updated_at) VALUES(?, ?, ?, NOW())");
        $st->execute([$sku, $name, $brand]);
        $pid = (int)db()->lastInsertId();

        $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, 'BARRA')");
        $st->execute([$pid, $code]);

        $st = db()->prepare("INSERT INTO stock_list_items(stock_list_id, product_id, qty) VALUES(?, ?, 1)
          ON DUPLICATE KEY UPDATE qty = qty + 1, updated_at = NOW()");
        $st->execute([$list_id, $pid]);

        db()->commit();
        $message = "Producto creado y sumado +1.";
        $unknown_code = '';
        $clear_scan_input = true;
        $should_focus_scan = true;
      } catch (Throwable $t) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $t->getMessage() === 'CODE_USED'
          ? 'Ese código ya está asignado a otro producto.'
          : 'No se pudo crear. Verificá que el SKU y el código no estén repetidos.';
      }
    }
  }
}

// Buscar productos existentes para asociar
if (is_post() && post('action') === 'search_products') {
  $unknown_code = trim((string)post('unknown_code'));
  $search_term = trim((string)post('product_search'));
  if ($search_term !== '') {
    $like = '%' . $search_term . '%';
    $st = db()->prepare("SELECT id, sku, name, brand FROM products WHERE sku LIKE ? OR name LIKE ? OR brand LIKE ? ORDER BY name ASC LIMIT 200");
    $st->execute([$like, $like, $like]);
    $search_results = $st->fetchAll();
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
  SELECT p.id AS product_id, p.sku, p.name, i.qty, i.updated_at
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
        <input type="hidden" name="action" value="search_products">
        <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
        <div>
          <label>Buscar producto (por nombre / sku / marca)</label><br>
          <input type="text" name="product_search" value="<?= e($search_term) ?>" placeholder="Escribí y buscá abajo" />
          <button type="submit">Buscar</button>
        </div>
      </form>
      <?php if ($search_term === ''): ?>
        <p><small>Usá el botón Buscar para ver resultados y elegir el producto.</small></p>
      <?php elseif (!$search_results): ?>
        <p>No hubo resultados con esa búsqueda.</p>
      <?php else: ?>
        <table border="1" cellpadding="6" cellspacing="0" style="margin-top:8px;">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Nombre</th>
              <th>Marca</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($search_results as $res): ?>
              <tr>
                <td><?= e($res['sku']) ?></td>
                <td><?= e($res['name']) ?></td>
                <td><?= e($res['brand']) ?></td>
                <td>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="associate_code">
                    <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
                    <input type="hidden" name="product_id" value="<?= (int)$res['id'] ?>">
                    <button type="submit">Asociar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <h4 style="margin-top:14px;">2) Crear producto nuevo y sumar</h4>
      <form method="post">
        <input type="hidden" name="action" value="create_product_from_code">
        <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
        <div>
          <label>SKU (obligatorio)</label><br>
          <input type="text" name="new_sku" value="<?= e(post('new_sku')) ?>" required>
        </div>
        <div style="margin-top:6px;">
          <label>Nombre (obligatorio)</label><br>
          <input type="text" name="new_name" value="<?= e(post('new_name')) ?>" required>
        </div>
        <div style="margin-top:6px;">
          <label>Marca</label><br>
          <input type="text" name="new_brand" value="<?= e(post('new_brand')) ?>">
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
      <label style="margin-right:8px;">
        Modo:
        <select name="scan_mode">
          <option value="add" <?= $scan_mode === 'add' ? 'selected' : '' ?>>Sumar +1</option>
          <option value="subtract" <?= $scan_mode === 'subtract' ? 'selected' : '' ?>>Restar -1</option>
        </select>
      </label>
      <input type="text" id="scan-code" name="code" value="<?= e($unknown_code !== '' ? $unknown_code : post('code')) ?>" autofocus placeholder="Escaneá acá (enter)..." <?= $list['status'] !== 'open' ? 'disabled' : '' ?>>
      <button type="submit" <?= $list['status'] !== 'open' ? 'disabled' : '' ?>>Aplicar</button>
      <?php if ($list['status'] !== 'open'): ?>
        <small>(listado cerrado)</small>
      <?php endif; ?>
    </form>
  </div>

  <div style="margin-top:14px;">
    <h3>Items</h3>
    <table border="1" cellpadding="6" cellspacing="0">
      <thead>
        <tr><th>sku</th><th>nombre</th><th>cantidad</th><th>acciones</th></tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="4">Sin items todavía.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= e($it['sku']) ?></td>
              <td><?= e($it['name']) ?></td>
              <td><?= (int)$it['qty'] ?></td>
              <td>
                <form method="post" style="margin:0;" onsubmit="return confirm('¿Eliminar este item del listado?');">
                  <input type="hidden" name="action" value="delete_item">
                  <input type="hidden" name="product_id" value="<?= (int)$it['product_id'] ?>">
                  <button type="submit">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
<?php if ($list['status'] === 'open' && ($should_focus_scan || $clear_scan_input)): ?>
<script>
  (function() {
    var input = document.getElementById('scan-code');
    if (!input) return;
    <?php if ($clear_scan_input): ?>
    input.value = '';
    <?php endif; ?>
    input.focus();
  })();
</script>
<?php endif; ?>
</body>
</html>
