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
$can_edit_list_action = can_edit_list();
$can_scan_action = can_scan();
$can_close_action = can_close_list();

// Toggle open/closed
if (is_post() && post('action') === 'toggle_status') {
  require_permission($can_close_action);
  $new = ($list['status'] === 'open') ? 'closed' : 'open';
  $st = db()->prepare("UPDATE stock_lists SET status = ? WHERE id = ?");
  $st->execute([$new, $list_id]);
  redirect("list_view.php?id={$list_id}");
}

// Delete item
if (is_post() && post('action') === 'delete_item') {
  require_permission(can_delete_list_item());
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
  require_permission($can_scan_action);
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
          $st = db()->prepare("SELECT qty, synced_qty FROM stock_list_items WHERE stock_list_id = ? AND product_id = ? LIMIT 1");
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
            $new_qty = (int)$item['qty'] - 1;
            $new_synced = min((int)$item['synced_qty'], $new_qty);
            $st = db()->prepare("UPDATE stock_list_items SET qty = ?, synced_qty = ?, updated_at = NOW() WHERE stock_list_id = ? AND product_id = ?");
            $st->execute([$new_qty, $new_synced, $list_id, $pid]);
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
  require_permission($can_edit_list_action);
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
  require_permission($can_edit_list_action);
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
  SELECT p.id AS product_id, p.sku, p.name, i.qty, i.synced_qty, i.updated_at
  FROM stock_list_items i
  JOIN products p ON p.id = i.product_id
  WHERE i.stock_list_id = ?
  ORDER BY i.updated_at DESC, p.name ASC
");
$items->execute([$list_id]);
$items = $items->fetchAll();

$total_units = 0;
$total_pending = 0;
foreach ($items as $it) {
  $qty = (int)$it['qty'];
  $synced_qty = min((int)$it['synced_qty'], $qty);
  $total_units += $qty;
  $total_pending += max(0, $qty - $synced_qty);
}

$sync_blocked = $list['status'] !== 'open';
$can_sync = !$sync_blocked && $total_pending > 0;
$can_sync_action = can_sync_prestashop();
$can_delete_action = can_delete_list_item();

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Listado #<?= (int)$list['id'] ?></title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/_header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Listado #<?= (int)$list['id'] ?></h2>
      <span class="muted"><?= e($list['name']) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card stack">
      <div class="form-row">
        <div><strong>id:</strong> <?= (int)$list['id'] ?></div>
        <div><strong>fecha:</strong> <?= e($list['created_at']) ?></div>
        <div><strong>creador:</strong> <?= e($list['first_name'] . ' ' . $list['last_name']) ?></div>
        <div>
          <strong>sync:</strong>
          <?php if ($list['sync_target'] === 'prestashop'): ?>
            <span class="badge badge-success">prestashop</span>
          <?php else: ?>
            <span class="badge badge-muted">sin sync</span>
          <?php endif; ?>
        </div>
        <div>
          <strong>estado:</strong>
          <?php if ($list['status'] === 'open'): ?>
            <span class="badge badge-success">Abierto</span>
          <?php else: ?>
            <span class="badge badge-warning">Cerrado</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="inline-actions">
        <a class="btn btn-ghost" href="download_excel.php?id=<?= (int)$list['id'] ?>">Descargar Excel</a>

        <?php if ($can_close_action): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="toggle_status">
            <button class="btn btn-secondary" type="submit"><?= $list['status'] === 'open' ? 'Cerrar' : 'Abrir' ?></button>
          </form>
        <?php endif; ?>

        <?php if ($can_sync_action): ?>
          <form method="post" style="display:inline;" action="ps_sync.php?id=<?= (int)$list['id'] ?>">
            <button class="btn" type="submit" <?= $can_sync ? '' : 'disabled' ?>>
              Sincronizar a PrestaShop
            </button>
            <?php if ($sync_blocked): ?>
              <span class="muted small">(listado cerrado)</span>
            <?php elseif (!$can_sync): ?>
              <span class="muted small">(sin pendientes)</span>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>

      <div class="inline-actions">
        <span class="kpi">Total unidades: <?= (int)$total_units ?></span>
        <span class="kpi">Productos distintos: <?= count($items) ?></span>
      </div>
    </div>

    <?php if ($unknown_code !== '' && $can_edit_list_action): ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Código no encontrado</h3>
          <span class="badge badge-danger"><?= e($unknown_code) ?></span>
        </div>

        <div class="stack">
          <div>
            <h4>1) Asociar a un producto existente</h4>
            <form method="post" class="stack">
              <input type="hidden" name="action" value="search_products">
              <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
              <div class="form-group">
                <label class="form-label">Buscar producto (por nombre / sku / marca)</label>
                <input class="form-control" type="text" name="product_search" value="<?= e($search_term) ?>" placeholder="Escribí y buscá abajo" />
              </div>
              <div class="form-actions">
                <button class="btn" type="submit">Buscar</button>
              </div>
            </form>
            <?php if ($search_term === ''): ?>
              <p class="muted small">Usá el botón Buscar para ver resultados y elegir el producto.</p>
            <?php elseif (!$search_results): ?>
              <p class="muted">No hubo resultados con esa búsqueda.</p>
            <?php else: ?>
              <div class="table-wrapper">
                <table class="table">
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
                        <td class="table-actions">
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="associate_code">
                            <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
                            <input type="hidden" name="product_id" value="<?= (int)$res['id'] ?>">
                            <button class="btn" type="submit">Asociar</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <div>
            <h4>2) Crear producto nuevo y sumar</h4>
            <form method="post" class="stack">
              <input type="hidden" name="action" value="create_product_from_code">
              <input type="hidden" name="unknown_code" value="<?= e($unknown_code) ?>">
              <div class="form-row">
                <div class="form-group">
                  <label class="form-label">SKU (obligatorio)</label>
                  <input class="form-control" type="text" name="new_sku" value="<?= e(post('new_sku')) ?>" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Nombre (obligatorio)</label>
                  <input class="form-control" type="text" name="new_name" value="<?= e(post('new_name')) ?>" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Marca</label>
                  <input class="form-control" type="text" name="new_brand" value="<?= e(post('new_brand')) ?>">
                </div>
              </div>
              <div class="form-actions">
                <button class="btn" type="submit">Crear y sumar +1</button>
                <a class="btn btn-ghost" href="list_view.php?id=<?= (int)$list_id ?>">Cancelar</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($can_scan_action): ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Cargar por escaneo</h3>
          <?php if ($list['status'] !== 'open'): ?>
            <span class="badge badge-warning">Listado cerrado</span>
          <?php endif; ?>
        </div>
        <form method="post" class="form-row">
          <input type="hidden" name="action" value="scan">
          <div class="form-group">
            <label class="form-label">Modo</label>
            <select name="scan_mode">
              <option value="add" <?= $scan_mode === 'add' ? 'selected' : '' ?>>Sumar +1</option>
              <option value="subtract" <?= $scan_mode === 'subtract' ? 'selected' : '' ?>>Restar -1</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Código</label>
            <input class="form-control" type="text" id="scan-code" name="code" value="<?= e($unknown_code !== '' ? $unknown_code : post('code')) ?>" autofocus placeholder="Escaneá acá (enter)..." <?= $list['status'] !== 'open' ? 'disabled' : '' ?>>
          </div>
          <div class="form-group" style="align-self:end;">
            <button class="btn" type="submit" <?= $list['status'] !== 'open' ? 'disabled' : '' ?>>Aplicar</button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Items</h3>
        <span class="muted small"><?= count($items) ?> productos</span>
      </div>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr><th>sku</th><th>nombre</th><th>cantidad</th><th>sincronizado</th><th>acciones</th></tr>
          </thead>
          <tbody>
            <?php if (!$items): ?>
              <tr><td colspan="5">Sin items todavía.</td></tr>
            <?php else: ?>
              <?php foreach ($items as $it): ?>
                <?php
                  $qty = (int)$it['qty'];
                  $synced_qty = min((int)$it['synced_qty'], $qty);
                ?>
                <tr>
                  <td><?= e($it['sku']) ?></td>
                  <td><?= e($it['name']) ?></td>
                  <td><?= $qty ?></td>
                  <td>
                    <?php if ($synced_qty >= $qty): ?>
                      <span class="badge badge-success"><?= $synced_qty ?>/<?= $qty ?></span>
                    <?php elseif ($synced_qty > 0): ?>
                      <span class="badge badge-warning"><?= $synced_qty ?>/<?= $qty ?></span>
                    <?php else: ?>
                      <span class="badge badge-muted">0/<?= $qty ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="table-actions">
                    <?php if ($can_delete_action): ?>
                      <form method="post" style="margin:0;" onsubmit="return confirm('¿Eliminar este item del listado?');">
                        <input type="hidden" name="action" value="delete_item">
                        <input type="hidden" name="product_id" value="<?= (int)$it['product_id'] ?>">
                        <button class="btn btn-danger" type="submit">Eliminar</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<?php if ($can_scan_action && $list['status'] === 'open' && ($should_focus_scan || $clear_scan_input)): ?>
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
