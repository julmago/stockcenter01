<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/include/pricing.php';
require_once __DIR__ . '/include/stock.php';
require_login();
ensure_product_suppliers_schema();
ensure_brands_schema();
ensure_sites_schema();
ensure_stock_schema();

$id = (int)get('id','0');
if ($id <= 0) abort(400, 'Falta id.');

$st = db()->prepare("SELECT p.*, b.name AS brand_name
  FROM products p
  LEFT JOIN brands b ON b.id = p.brand_id
  WHERE p.id = ?
  LIMIT 1");
$st->execute([$id]);
$product = $st->fetch();
if (!$product) abort(404, 'Producto no encontrado.');

$error = '';
$message = '';
$can_edit = can_edit_product();
$can_add_code = can_add_code();

$parse_supplier_cost_decimal = static function (string $supplier_cost_raw): ?int {
  $supplier_cost_raw = str_replace(',', '.', trim($supplier_cost_raw));
  if ($supplier_cost_raw === '') {
    return null;
  }

  return max(0, (int)round((float)$supplier_cost_raw));
};

if (is_post() && post('action') === 'update') {
  require_permission($can_edit);
  $sku = post('sku');
  $name = post('name');
  $brand_id = (int)post('brand_id', '0');
  $sale_mode = post('sale_mode', 'UNIDAD');
  $sale_units_per_pack = post('sale_units_per_pack');

  if (!in_array($sale_mode, ['UNIDAD', 'PACK'], true)) {
    $sale_mode = 'UNIDAD';
  }

  $sale_units_per_pack_value = null;
  if ($sale_mode === 'PACK') {
    $sale_units_per_pack_value = (int)$sale_units_per_pack;
    if ($sale_units_per_pack_value <= 0) {
      $error = 'Si el modo de venta es Pack, indicá unidades por pack mayores a 0.';
    }
  }

  if ($error === '' && ($sku === '' || $name === '')) {
    $error = 'SKU y Nombre son obligatorios.';
  } elseif ($error === '') {
    try {
      $brand_name = '';
      $brand_id_value = null;
      if ($brand_id > 0) {
        $st = db()->prepare("SELECT id, name FROM brands WHERE id = ? LIMIT 1");
        $st->execute([$brand_id]);
        $brand_row = $st->fetch();
        if ($brand_row) {
          $brand_id_value = (int)$brand_row['id'];
          $brand_name = (string)$brand_row['name'];
        }
      }

      $st = db()->prepare("UPDATE products SET sku=?, name=?, brand=?, brand_id=?, sale_mode=?, sale_units_per_pack=?, updated_at=NOW() WHERE id=?");
      $st->execute([$sku, $name, $brand_name, $brand_id_value, $sale_mode, $sale_units_per_pack_value, $id]);
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

if (is_post() && post('action') === 'add_supplier_link') {
  require_permission($can_edit);
  $supplier_id = (int)post('supplier_id', '0');
  $supplier_sku = post('supplier_sku');
  $cost_type = post('cost_type', 'UNIDAD');
  $units_per_pack = post('units_per_pack');
  $supplier_cost_raw = trim((string)post('supplier_cost'));

  if (!in_array($cost_type, ['UNIDAD', 'PACK'], true)) {
    $cost_type = 'UNIDAD';
  }

  $units_per_pack_value = null;
  if ($cost_type === 'PACK') {
    $units_per_pack_value = (int)$units_per_pack;
    if ($units_per_pack_value <= 0) {
      $error = 'Si el costo del proveedor es Pack, indicá unidades por pack mayores a 0.';
    }
  }


  $supplier_cost_value = $parse_supplier_cost_decimal($supplier_cost_raw);
  $supplier_for_cost = [];
  if ($supplier_id > 0) {
    $st = db()->prepare('SELECT import_default_units_per_pack FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplier_id]);
    $supplier_for_cost = $st->fetch() ?: [];
  }

  $cost_unitario_value = get_effective_unit_cost([
    'supplier_cost' => $supplier_cost_value,
    'cost_type' => $cost_type,
    'units_per_pack' => $units_per_pack_value,
    'units_pack' => (int)($product['sale_units_per_pack'] ?? 0),
  ], $supplier_for_cost);
  $cost_unitario_value = ($cost_unitario_value === null) ? null : (int)round($cost_unitario_value, 0);

  if ($error === '' && $supplier_id <= 0) {
    $error = 'Seleccioná un proveedor.';
  }

  if ($error === '') {
    try {
      db()->beginTransaction();
      $st = db()->prepare("UPDATE product_suppliers SET is_active = 0, updated_at = NOW() WHERE product_id = ?");
      $st->execute([$id]);

      $st = db()->prepare("INSERT INTO product_suppliers(product_id, supplier_id, supplier_sku, cost_type, units_per_pack, supplier_cost, cost_unitario, is_active, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE supplier_sku = VALUES(supplier_sku), cost_type = VALUES(cost_type), units_per_pack = VALUES(units_per_pack), supplier_cost = VALUES(supplier_cost), cost_unitario = VALUES(cost_unitario), is_active = 1, updated_at = NOW()");
      $st->execute([$id, $supplier_id, $supplier_sku, $cost_type, $units_per_pack_value, $supplier_cost_value, $cost_unitario_value]);
      db()->commit();
      $message = 'Proveedor vinculado.';
    } catch (Throwable $t) {
      if (db()->inTransaction()) db()->rollBack();
      $error = 'No se pudo vincular el proveedor.';
    }
  }
}

if (is_post() && post('action') === 'update_supplier_link') {
  require_permission($can_edit);
  $link_id = (int)post('edit_link_id', '0');
  $supplier_id = (int)post('supplier_id', '0');
  $supplier_sku = post('supplier_sku');
  $cost_type = post('cost_type', 'UNIDAD');
  $units_per_pack = post('units_per_pack');
  $supplier_cost_raw = trim((string)post('supplier_cost'));

  if ($link_id <= 0) {
    $error = 'Vínculo inválido para editar.';
  }

  if (!in_array($cost_type, ['UNIDAD', 'PACK'], true)) {
    $cost_type = 'UNIDAD';
  }

  $units_per_pack_value = null;
  if ($cost_type === 'PACK') {
    $units_per_pack_value = (int)$units_per_pack;
    if ($units_per_pack_value <= 0) {
      $error = 'Si el costo del proveedor es Pack, indicá unidades por pack mayores a 0.';
    }
  }

  $supplier_cost_value = $parse_supplier_cost_decimal($supplier_cost_raw);
  $supplier_for_cost = [];
  if ($supplier_id > 0) {
    $st = db()->prepare('SELECT import_default_units_per_pack FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplier_id]);
    $supplier_for_cost = $st->fetch() ?: [];
  }

  $cost_unitario_value = get_effective_unit_cost([
    'supplier_cost' => $supplier_cost_value,
    'cost_type' => $cost_type,
    'units_per_pack' => $units_per_pack_value,
    'units_pack' => (int)($product['sale_units_per_pack'] ?? 0),
  ], $supplier_for_cost);
  $cost_unitario_value = ($cost_unitario_value === null) ? null : (int)round($cost_unitario_value, 0);

  if ($error === '' && $supplier_id <= 0) {
    $error = 'Seleccioná un proveedor.';
  }

  if ($error === '') {
    try {
      $st = db()->prepare("UPDATE product_suppliers SET supplier_id = ?, supplier_sku = ?, cost_type = ?, units_per_pack = ?, supplier_cost = ?, cost_unitario = ?, updated_at = NOW() WHERE id = ? AND product_id = ?");
      $st->execute([$supplier_id, $supplier_sku, $cost_type, $units_per_pack_value, $supplier_cost_value, $cost_unitario_value, $link_id, $id]);
      $message = 'Proveedor actualizado.';
    } catch (Throwable $t) {
      $error = 'No se pudo actualizar el proveedor vinculado.';
    }
  }
}

if (is_post() && post('action') === 'delete_supplier_link') {
  require_permission($can_edit);
  $link_id = (int)post('link_id', '0');
  if ($link_id > 0) {
    $st = db()->prepare("DELETE FROM product_suppliers WHERE id = ? AND product_id = ?");
    $st->execute([$link_id, $id]);
    $message = 'Proveedor desvinculado.';
  }
}

if (is_post() && post('action') === 'set_active_supplier') {
  require_permission($can_edit);
  $link_id = (int)post('link_id', '0');
  if ($link_id > 0) {
    try {
      db()->beginTransaction();
      $st = db()->prepare("UPDATE product_suppliers SET is_active = 0, updated_at = NOW() WHERE product_id = ?");
      $st->execute([$id]);

      $st = db()->prepare("UPDATE product_suppliers SET is_active = 1, updated_at = NOW() WHERE id = ? AND product_id = ?");
      $st->execute([$link_id, $id]);
      db()->commit();
      $message = 'Proveedor activo actualizado.';
    } catch (Throwable $t) {
      if (db()->inTransaction()) db()->rollBack();
      $error = 'No se pudo marcar el proveedor activo.';
    }
  }
}

if (is_post() && post('action') === 'create_supplier_inline') {
  require_permission($can_edit);

  header('Content-Type: application/json; charset=utf-8');

  $supplier_name = trim(post('supplier_name'));
  $default_margin_percent = normalize_margin_percent_value(post('default_margin_percent'));

  if ($supplier_name === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Ingresá el nombre del proveedor.']);
    exit;
  }

  if ($default_margin_percent === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Base (%) inválida. Usá un valor entre 0 y 999.99.']);
    exit;
  }

  try {
    $st = db()->prepare('SELECT id, name, default_margin_percent FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $st->execute([$supplier_name]);
    $existing = $st->fetch();

    if ($existing) {
      echo json_encode([
        'ok' => true,
        'supplier' => [
          'id' => (int)$existing['id'],
          'name' => (string)$existing['name'],
          'default_margin_percent' => number_format((float)$existing['default_margin_percent'], 2, '.', ''),
        ],
        'existing' => true,
        'message' => 'Ese proveedor ya existe. Se seleccionó el existente.',
      ]);
      exit;
    }

    $st = db()->prepare('INSERT INTO suppliers(name, default_margin_percent, is_active, updated_at) VALUES(?, ?, 1, NOW())');
    $st->execute([$supplier_name, $default_margin_percent]);

    $supplier_id = (int)db()->lastInsertId();

    echo json_encode([
      'ok' => true,
      'supplier' => [
        'id' => $supplier_id,
        'name' => $supplier_name,
        'default_margin_percent' => $default_margin_percent,
      ],
      'existing' => false,
    ]);
    exit;
  } catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'No se pudo crear el proveedor.']);
    exit;
  }
}

if (is_post() && post('action') === 'stock_set') {
  require_permission($can_edit);
  $qty_raw = trim(post('stock_set_qty'));
  $note = post('stock_note');

  if ($qty_raw === '' || !preg_match('/^-?\d+$/', $qty_raw)) {
    $error = 'El stock debe ser un entero.';
  } else {
    try {
      set_stock($id, (int)$qty_raw, $note, (int)(current_user()['id'] ?? 0));
      $message = 'Stock actualizado.';
    } catch (InvalidArgumentException $e) {
      $error = $e->getMessage();
    } catch (Throwable $e) {
      $error = 'No se pudo actualizar el stock.';
    }
  }
}

if (is_post() && post('action') === 'stock_add') {
  require_permission($can_edit);
  $delta_raw = trim(post('stock_delta'));
  $note = post('stock_note');

  if ($delta_raw === '' || !preg_match('/^-?\d+$/', $delta_raw)) {
    $error = 'El ajuste debe ser un entero.';
  } else {
    try {
      add_stock($id, (int)$delta_raw, $note, (int)(current_user()['id'] ?? 0));
      $message = 'Stock ajustado.';
    } catch (InvalidArgumentException $e) {
      $error = $e->getMessage();
    } catch (Throwable $e) {
      $error = 'No se pudo ajustar el stock.';
    }
  }
}

$brands = fetch_brands();

// recargar
$st = db()->prepare("SELECT p.*, b.name AS brand_name
  FROM products p
  LEFT JOIN brands b ON b.id = p.brand_id
  WHERE p.id = ?
  LIMIT 1");
$st->execute([$id]);
$product = $st->fetch();

$st = db()->prepare("SELECT id, code, code_type, created_at FROM product_codes WHERE product_id = ? ORDER BY id DESC");
$st->execute([$id]);
$codes = $st->fetchAll();

$st = db()->query("SELECT id, name, default_margin_percent FROM suppliers WHERE is_active = 1 ORDER BY name ASC");
$suppliers = $st->fetchAll();

$st = db()->prepare("SELECT ps.id, ps.supplier_id, ps.supplier_sku, ps.cost_type, ps.units_per_pack, ps.supplier_cost, ps.cost_unitario, ps.is_active, s.name AS supplier_name,
  CASE
    WHEN ps.supplier_cost IS NULL THEN NULL
    WHEN ps.cost_type = 'PACK' THEN ROUND(ps.supplier_cost / COALESCE(NULLIF(COALESCE(ps.units_per_pack, s.import_default_units_per_pack, p.sale_units_per_pack, 1), 0), 1), 4)
    ELSE ps.supplier_cost
  END AS normalized_unit_cost,
  CASE
    WHEN p.sale_mode = 'PACK' AND COALESCE(p.sale_units_per_pack, 0) > 0 THEN
      ROUND((CASE
        WHEN ps.supplier_cost IS NULL THEN NULL
        WHEN ps.cost_type = 'PACK' THEN ps.supplier_cost / COALESCE(NULLIF(COALESCE(ps.units_per_pack, s.import_default_units_per_pack, p.sale_units_per_pack, 1), 0), 1)
        ELSE ps.supplier_cost
      END) * p.sale_units_per_pack, 0)
    ELSE
      ROUND((CASE
        WHEN ps.supplier_cost IS NULL THEN NULL
        WHEN ps.cost_type = 'PACK' THEN ps.supplier_cost / COALESCE(NULLIF(COALESCE(ps.units_per_pack, s.import_default_units_per_pack, p.sale_units_per_pack, 1), 0), 1)
        ELSE ps.supplier_cost
      END), 0)
  END AS normalized_product_cost
  FROM product_suppliers ps
  INNER JOIN products p ON p.id = ps.product_id
  INNER JOIN suppliers s ON s.id = ps.supplier_id
  WHERE ps.product_id = ?
  ORDER BY ps.is_active DESC, ps.id DESC");
$st->execute([$id]);
$supplier_links = $st->fetchAll();

$ts_stock = get_stock($id);
$ts_stock_moves = get_stock_moves($id, 20);
$running_qty = (int)$ts_stock['qty'];
foreach ($ts_stock_moves as $index => $move) {
  $ts_stock_moves[$index]['result_qty'] = $running_qty;
  $running_qty -= (int)$move['delta'];
}

$supplier_margin_column = 'default_margin_percent';
$supplier_discount_column = null;
$supplier_columns_st = db()->query("SHOW COLUMNS FROM suppliers");
if ($supplier_columns_st) {
  $supplier_margin_column = null;
  foreach ($supplier_columns_st->fetchAll() as $supplier_column) {
    $field = (string)($supplier_column['Field'] ?? '');
    if ($field === 'base_percent' || $field === 'base_margin_percent') {
      $supplier_margin_column = $field;
    }
    if ($field === 'default_margin_percent' && $supplier_margin_column === null) {
      $supplier_margin_column = $field;
    } elseif ($supplier_margin_column === null && stripos($field, 'margin') !== false) {
      $supplier_margin_column = $field;
    }
    if ($field === 'discount_percent') {
      $supplier_discount_column = $field;
    }
    if ($field === 'import_discount_default' && $supplier_discount_column === null) {
      $supplier_discount_column = $field;
    }
  }
}

$supplier_margin_expr = '0';
if ($supplier_margin_column !== null) {
  $safe_supplier_margin_column = str_replace('`', '``', $supplier_margin_column);
  $supplier_margin_expr = "COALESCE(s.`{$safe_supplier_margin_column}`, 0)";
}

$supplier_discount_expr = '0';
if ($supplier_discount_column !== null) {
  $safe_supplier_discount_column = str_replace('`', '``', $supplier_discount_column);
  $supplier_discount_expr = "COALESCE(s.`{$safe_supplier_discount_column}`, 0)";
}

$st = db()->prepare("SELECT ps.id, ps.supplier_cost, ps.cost_unitario, ps.cost_type, ps.units_per_pack,
  {$supplier_margin_expr} AS supplier_base_percent,
  {$supplier_discount_expr} AS supplier_discount_percent,
  COALESCE(s.import_default_units_per_pack, 0) AS supplier_default_units_per_pack,
  COALESCE(p.sale_units_per_pack, 0) AS units_pack
  FROM product_suppliers ps
  INNER JOIN suppliers s ON s.id = ps.supplier_id
  INNER JOIN products p ON p.id = ps.product_id
  WHERE ps.product_id = ? AND ps.is_active = 1
  ORDER BY ps.id ASC
  LIMIT 1");
$st->execute([$id]);
$active_supplier_link = $st->fetch();

$site_prices = [];
$st = db()->query("SELECT id, name, margin_percent, is_active, is_visible, show_in_product FROM sites WHERE show_in_product = 1 ORDER BY id ASC");
if ($st) {
  $site_prices = $st->fetchAll();
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
  <style>
    .inline-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(6, 10, 18, 0.72);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1200;
      padding: 16px;
    }

    .inline-modal-backdrop.is-open {
      display: flex;
    }

    .inline-modal {
      width: 100%;
      max-width: 420px;
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, 0.12);
      background: var(--panel, #131a2a);
      box-shadow: 0 18px 44px rgba(0, 0, 0, 0.45);
      padding: 18px;
    }

    .inline-modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 12px;
    }

    .inline-modal-feedback {
      margin-top: 10px;
      color: #ff7d7d;
      font-size: 13px;
    }

    .product-table-wrapper {
      margin-top: var(--space-4);
    }

    .supplier-form-row {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: var(--space-3);
      align-items: start;
    }

    .supplier-col-2 {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: var(--space-3);
      align-items: start;
    }

    .supplier-col-3 {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: var(--space-3);
      align-items: start;
    }

    .supplier-col-3.is-pack {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .supplier-col-3 .form-group {
      margin-bottom: 0;
    }

    .supplier-col-3 .form-group.is-hidden {
      display: none;
    }

    @media (max-width: 980px) {
      .supplier-form-row,
      .supplier-col-2,
      .supplier-col-3,
      .supplier-col-3.is-pack {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>
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
      <div class="card-header">
        <h3 class="card-title">Datos del producto</h3>
      </div>
      <?php if ($can_edit): ?>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="update">
          <div class="form-row" style="grid-template-columns:repeat(3, minmax(0, 1fr));">
            <div class="form-group">
              <label class="form-label">SKU</label>
              <input class="form-control" type="text" name="sku" value="<?= e($product['sku']) ?>" required>
            </div>
            <div class="form-group" style="grid-column: span 2;">
              <label class="form-label">Nombre</label>
              <input class="form-control" type="text" name="name" value="<?= e($product['name']) ?>" required>
            </div>
          </div>
          <div class="form-row" style="grid-template-columns:repeat(3, minmax(0, 1fr));">
            <div class="form-group">
              <label class="form-label">Marca</label>
              <select class="form-control" name="brand_id">
                <option value="">Sin marca</option>
                <?php foreach ($brands as $brand): ?>
                  <option value="<?= (int)$brand['id'] ?>" <?= (int)($product['brand_id'] ?? 0) === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Modo de venta</label>
              <select class="form-control" name="sale_mode" id="sale-mode-select" required>
                <option value="UNIDAD" <?= ($product['sale_mode'] ?? 'UNIDAD') === 'UNIDAD' ? 'selected' : '' ?>>Unidad</option>
                <option value="PACK" <?= ($product['sale_mode'] ?? '') === 'PACK' ? 'selected' : '' ?>>Pack</option>
              </select>
            </div>
            <div class="form-group" id="sale-units-group" style="display:none;">
              <label class="form-label">Unidades pack</label>
              <input class="form-control" type="number" min="1" step="1" name="sale_units_per_pack" id="sale-units-input" value="<?= e((string)($product['sale_units_per_pack'] ?? '')) ?>">
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
          <div><strong>Marca:</strong> <?= e($product['brand_name'] ?? $product['brand']) ?></div>
          <div><strong>Modo de venta:</strong> <?= e(($product['sale_mode'] ?? 'UNIDAD') === 'PACK' ? 'Pack' : 'Unidad') ?></div>
          <?php if (($product['sale_mode'] ?? 'UNIDAD') === 'PACK'): ?>
            <div><strong>Unidades por pack:</strong> <?= (int)($product['sale_units_per_pack'] ?? 0) ?></div>
          <?php endif; ?>
          <div class="form-actions">
            <a class="btn btn-ghost" href="product_list.php">Volver</a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Stock (TS Work)</h3>
        <span class="muted small">Actual: <?= (int)$ts_stock['qty'] ?></span>
      </div>
      <div class="card-body stack">
        <div><strong>Stock actual:</strong> <?= (int)$ts_stock['qty'] ?></div>

        <?php if ($can_edit): ?>
          <form method="post" class="stack">
            <input type="hidden" name="action" value="stock_set">
            <div class="form-row" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
              <div class="form-group">
                <label class="form-label">Setear stock</label>
                <input class="form-control" type="number" step="1" name="stock_set_qty" required>
              </div>
              <div class="form-group">
                <label class="form-label">Nota (opcional)</label>
                <input class="form-control" type="text" name="stock_note" maxlength="1000" placeholder="Motivo o comentario">
              </div>
            </div>
            <div class="form-actions">
              <button class="btn" type="submit">Guardar</button>
            </div>
          </form>

          <form method="post" class="stack">
            <input type="hidden" name="action" value="stock_add">
            <div class="form-row" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
              <div class="form-group">
                <label class="form-label">Sumar / Restar</label>
                <input class="form-control" type="number" step="1" name="stock_delta" required>
              </div>
              <div class="form-group">
                <label class="form-label">Nota (opcional)</label>
                <input class="form-control" type="text" name="stock_note" maxlength="1000" placeholder="Motivo o comentario">
              </div>
            </div>
            <div class="form-actions">
              <button class="btn" type="submit">Guardar</button>
            </div>
          </form>
        <?php endif; ?>

        <div class="table-wrapper product-table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>fecha</th>
                <th>delta</th>
                <th>stock resultante</th>
                <th>motivo</th>
                <th>usuario</th>
                <th>nota</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$ts_stock_moves): ?>
                <tr><td colspan="6">Sin movimientos todavía.</td></tr>
              <?php else: ?>
                <?php foreach ($ts_stock_moves as $move): ?>
                  <?php
                    $delta = (int)$move['delta'];
                    $user_name = trim((string)($move['user_name'] ?? ''));
                    if ($user_name === '') {
                      $user_name = (string)($move['user_email'] ?? 'Sistema');
                    }
                  ?>
                  <tr>
                    <td><?= e((string)$move['created_at']) ?></td>
                    <td><?= $delta > 0 ? '+' . $delta : (string)$delta ?></td>
                    <td><?= (int)$move['result_qty'] ?></td>
                    <td><?= e((string)$move['reason']) ?></td>
                    <td><?= e($user_name) ?></td>
                    <td><?= e((string)($move['note'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
<div class="card">
      <div class="card-header">
        <h3 class="card-title">Códigos</h3>
        <span class="muted small"><?= count($codes) ?> registrados</span>
      </div>
      <div class="card-body product-codes-body">
        <?php if ($can_add_code): ?>
          <form method="post" class="form-row product-codes-form">
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

<div class="table-wrapper product-table-wrapper">
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
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Proveedores vinculados</h3>
        <span class="muted small"><?= count($supplier_links) ?> vinculados</span>
      </div>
      <div class="card-body product-linked-suppliers-body">
        <?php if ($can_edit): ?>
          <form method="post" class="stack product-linked-suppliers-form">
            <input type="hidden" name="action" value="add_supplier_link" id="supplier-link-action">
            <input type="hidden" name="edit_link_id" value="" id="edit-link-id-input">
            <div class="form-row product-supplier-form supplier-form-row">
              <div class="form-group">
                <label class="form-label">SKU / Código del proveedor</label>
                <input class="form-control" type="text" name="supplier_sku">
              </div>
              <div class="supplier-col-2">
                <div class="form-group">
                  <label class="form-label">Proveedor</label>
                  <select class="form-control" name="supplier_id" id="supplier-id-select" required>
                    <option value="">Seleccionar</option>
                    <option value="__new__">+ Agregar proveedor…</option>
                    <?php foreach ($suppliers as $supplier): ?>
                      <option value="<?= (int)$supplier['id'] ?>"><?= e($supplier['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Costo del proveedor</label>
                  <input class="form-control" type="number" step="1" min="0" pattern="\d*" inputmode="numeric" name="supplier_cost" id="supplier-cost-input" placeholder="0">
                </div>
              </div>
              <div>
                <div class="product-supplier-cost-layout supplier-col-3" id="cost-layout-group">
                  <div class="form-group">
                    <label class="form-label">Tipo de costo recibido</label>
                    <select class="form-control" name="cost_type" id="cost-type-select">
                      <option value="UNIDAD">Unidad</option>
                      <option value="PACK">Pack</option>
                    </select>
                  </div>
                  <div class="form-group is-hidden" id="cost-units-group" data-toggle-hidden="1">
                    <label class="form-label">Unidades por pack</label>
                    <input class="form-control" type="number" min="1" step="1" name="units_per_pack" id="cost-units-input">
                  </div>
                </div>
              </div>
            </div>
            <div class="form-actions product-supplier-actions">
              <button class="btn" type="submit" id="supplier-link-submit-btn">Agregar proveedor</button>
              <button class="btn btn-ghost" type="button" id="supplier-link-cancel-btn" style="display:none;">Cancelar edición</button>
            </div>
          </form>
        <?php endif; ?>

        <div class="table-wrapper product-table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>proveedor</th>
                <th>sku proveedor</th>
                <th>costo recibido</th>
                <th>unidades pack</th>
                <th>costo proveedor</th>
                <th>costo unitario</th>
                <th>activo</th>
                <?php if ($can_edit): ?>
                  <th>acciones</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (!$supplier_links): ?>
                <tr><td colspan="<?= $can_edit ? 8 : 7 ?>">Sin proveedores vinculados.</td></tr>
              <?php else: ?>
                <?php foreach ($supplier_links as $link): ?>
                  <tr>
                    <td><?= e($link['supplier_name']) ?></td>
                    <td><?= e($link['supplier_sku']) ?></td>
                    <td><?= e($link['cost_type'] === 'PACK' ? 'Pack' : 'Unidad') ?></td>
                    <td><?= $link['cost_type'] === 'PACK' ? (int)$link['units_per_pack'] : '-' ?></td>
                    <td><?= ($link['supplier_cost'] === null || trim((string)$link['supplier_cost']) === '') ? '—' : number_format(round((float)$link['supplier_cost']), 0, '', '') ?></td>
                    <td><?= ($link['normalized_unit_cost'] === null || trim((string)$link['normalized_unit_cost']) === '') ? '—' : number_format(round((float)$link['normalized_unit_cost']), 0, '', '') ?></td>
                    <td><?= (int)$link['is_active'] === 1 ? 'Sí' : 'No' ?></td>
                    <?php if ($can_edit): ?>
                      <td class="table-actions">
                        <button
                          class="btn btn-ghost js-edit-supplier-link"
                          type="button"
                          data-link-id="<?= (int)$link['id'] ?>"
                          data-supplier-id="<?= (int)$link['supplier_id'] ?>"
                          data-supplier-sku="<?= e($link['supplier_sku']) ?>"
                          data-cost-type="<?= e($link['cost_type']) ?>"
                          data-units-per-pack="<?= (int)($link['units_per_pack'] ?? 0) ?>"
                          data-supplier-cost="<?= ($link['supplier_cost'] === null || trim((string)$link['supplier_cost']) === '') ? '' : number_format(round((float)$link['supplier_cost']), 0, '', '') ?>"
                          style="margin-right:6px;"
                        >Modificar</button>
                        <?php if ((int)$link['is_active'] !== 1): ?>
                          <form method="post" style="display:inline; margin-right:6px;">
                            <input type="hidden" name="action" value="set_active_supplier">
                            <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                            <button class="btn btn-ghost" type="submit">Marcar activo</button>
                          </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;">
                          <input type="hidden" name="action" value="delete_supplier_link">
                          <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
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
    </div>

    

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Precios por sitio</h3>
        <span class="muted small"><?= count($site_prices) ?> visibles en producto</span>
      </div>
      <div class="card-body">
        <div class="table-wrapper product-table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>sitio</th>
                <th>margen (%)</th>
                <th>estado</th>
                <th>mostrar en lista</th>
                <th>mostrar en producto</th>
                <th>precio</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$site_prices): ?>
                <tr><td colspan="6">Sin sitios configurados para producto.</td></tr>
              <?php else: ?>
                <?php foreach ($site_prices as $site): ?>
                  <tr>
                    <td><?= e($site['name']) ?></td>
                    <td><?= e(number_format((float)$site['margin_percent'], 2, '.', '')) ?></td>
                    <td><?= (int)$site['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                    <td><?= (int)$site['is_visible'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                    <td><?= (int)$site['show_in_product'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                    <td>
                      <?php
                        if (!$active_supplier_link) {
                          echo '—';
                        } else {
                          $effective_unit_cost = get_effective_unit_cost($active_supplier_link, [
                            'import_default_units_per_pack' => $active_supplier_link['supplier_default_units_per_pack'] ?? 0,
                            'discount_percent' => $active_supplier_link['supplier_discount_percent'] ?? 0,
                          ]);
                          $cost_for_mode = get_cost_for_product_mode($effective_unit_cost, $product);
                          $price_reason = get_price_unavailable_reason($active_supplier_link, $product);

                          if ($cost_for_mode === null) {
                            $title = $price_reason ?? 'Precio incompleto';
                            echo '<span title="' . e($title) . '">—</span>';
                          } else {
                            $final_price = get_final_site_price($cost_for_mode, [
                              'base_percent' => $active_supplier_link['supplier_base_percent'] ?? 0,
                            ], $site, 0.0);

                            if ($final_price === null) {
                              $title = $price_reason ?? 'Precio incompleto';
                              echo '<span title="' . e($title) . '">—</span>';
                            } else {
                              echo e((string)(int)$final_price);
                            }
                          }
                        }
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php require_once __DIR__ . '/include/partials/messages_block.php'; ?>
    <?php ts_messages_block('product', $id, ['accordion' => true]); ?>
  </div>
</main>

<div class="inline-modal-backdrop" id="supplier-inline-modal" aria-hidden="true">
  <div class="inline-modal" role="dialog" aria-modal="true" aria-labelledby="supplier-inline-modal-title">
    <div class="card-header" style="padding:0; margin-bottom:10px;">
      <h3 class="card-title" id="supplier-inline-modal-title">Nuevo proveedor</h3>
    </div>
    <form id="supplier-inline-form" class="stack">
      <div class="form-group">
        <label class="form-label" for="supplier-inline-name">Nombre del proveedor</label>
        <input class="form-control" type="text" id="supplier-inline-name" name="supplier_name" maxlength="190" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="supplier-inline-margin">Base (%)</label>
        <input class="form-control" type="number" id="supplier-inline-margin" name="default_margin_percent" min="0" max="999.99" step="0.01" placeholder="0, 20, 30..." value="0" required>
      </div>
      <p class="inline-modal-feedback" id="supplier-inline-feedback" hidden></p>
      <div class="inline-modal-actions">
        <button class="btn btn-ghost" type="button" id="supplier-inline-cancel">Cancelar</button>
        <button class="btn" type="submit" id="supplier-inline-submit">Agregar</button>
      </div>
    </form>
  </div>
</div>

<script>
  const saleModeSelect = document.getElementById('sale-mode-select');
  const saleUnitsGroup = document.getElementById('sale-units-group');
  const saleUnitsInput = document.getElementById('sale-units-input');

  const costTypeSelect = document.getElementById('cost-type-select');
  const costUnitsGroup = document.getElementById('cost-units-group');
  const costUnitsInput = document.getElementById('cost-units-input');

  const toggleByMode = (select, group, input) => {
    if (!select || !group || !input) return;
    const isPack = select.value === 'PACK';
    if (group.dataset.toggleHidden === '1') {
      group.classList.toggle('is-hidden', !isPack);
      const packLayout = document.getElementById('cost-layout-group');
      if (packLayout) {
        packLayout.classList.toggle('is-pack', isPack);
      }
    } else {
      group.style.display = isPack ? '' : 'none';
    }
    input.required = isPack;
    if (!isPack) {
      input.value = '';
    }
  };

  if (saleModeSelect) {
    saleModeSelect.addEventListener('change', () => toggleByMode(saleModeSelect, saleUnitsGroup, saleUnitsInput));
    toggleByMode(saleModeSelect, saleUnitsGroup, saleUnitsInput);
  }

  if (costTypeSelect) {
    costTypeSelect.addEventListener('change', () => toggleByMode(costTypeSelect, costUnitsGroup, costUnitsInput));
    toggleByMode(costTypeSelect, costUnitsGroup, costUnitsInput);
  }

  const supplierSelect = document.getElementById('supplier-id-select');
  const supplierLinkForm = document.querySelector('.product-linked-suppliers-form');
  const supplierLinkActionInput = document.getElementById('supplier-link-action');
  const editLinkIdInput = document.getElementById('edit-link-id-input');
  const supplierSkuInput = supplierLinkForm ? supplierLinkForm.querySelector('input[name="supplier_sku"]') : null;
  const supplierCostInput = document.getElementById('supplier-cost-input');
  const supplierLinkSubmitBtn = document.getElementById('supplier-link-submit-btn');
  const supplierLinkCancelBtn = document.getElementById('supplier-link-cancel-btn');
  const editSupplierButtons = document.querySelectorAll('.js-edit-supplier-link');

  const normalizeSupplierCostValue = (value) => {
    if (value === undefined || value === null) return '';
    const normalized = String(value).replace(',', '.').replace(/[^0-9.]/g, '');
    if (normalized === '') return '';

    const firstDot = normalized.indexOf('.');
    const compact = firstDot >= 0
      ? normalized.slice(0, firstDot + 1) + normalized.slice(firstDot + 1).replace(/\./g, '')
      : normalized;

    const parsed = parseFloat(compact);
    if (!Number.isFinite(parsed) || parsed < 0) return '';
    return String(Math.round(parsed));
  };

  const bindCostInput = (input) => {
    if (!input) return;

    const sanitize = () => {
      input.value = normalizeSupplierCostValue(input.value);
    };

    input.addEventListener('input', sanitize);
    input.addEventListener('blur', sanitize);
    sanitize();
  };

  const resetSupplierLinkForm = () => {
    if (!supplierLinkForm) return;
    supplierLinkForm.reset();
    if (supplierLinkActionInput) supplierLinkActionInput.value = 'add_supplier_link';
    if (editLinkIdInput) editLinkIdInput.value = '';
    if (supplierLinkSubmitBtn) supplierLinkSubmitBtn.textContent = 'Agregar proveedor';
    if (supplierLinkCancelBtn) supplierLinkCancelBtn.style.display = 'none';
    toggleByMode(costTypeSelect, costUnitsGroup, costUnitsInput);
  };

  editSupplierButtons.forEach((button) => {
    button.addEventListener('click', () => {
      if (!supplierLinkForm) return;
      if (supplierLinkActionInput) supplierLinkActionInput.value = 'update_supplier_link';
      if (editLinkIdInput) editLinkIdInput.value = button.dataset.linkId || '';
      if (supplierSkuInput) supplierSkuInput.value = button.dataset.supplierSku || '';
      if (supplierSelect) supplierSelect.value = button.dataset.supplierId || '';
      if (costTypeSelect) costTypeSelect.value = button.dataset.costType || 'UNIDAD';
      if (costUnitsInput) {
        costUnitsInput.value = button.dataset.costType === 'PACK'
          ? (button.dataset.unitsPerPack || '')
          : '';
      }
      if (supplierCostInput) supplierCostInput.value = normalizeSupplierCostValue(button.dataset.supplierCost || '');
      if (supplierLinkSubmitBtn) supplierLinkSubmitBtn.textContent = 'Guardar cambios';
      if (supplierLinkCancelBtn) supplierLinkCancelBtn.style.display = '';
      toggleByMode(costTypeSelect, costUnitsGroup, costUnitsInput);
      supplierLinkForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  if (supplierLinkForm) {
    supplierLinkForm.addEventListener('submit', () => {
      if (supplierCostInput) {
        supplierCostInput.value = normalizeSupplierCostValue(supplierCostInput.value);
      }
    });
  }

  bindCostInput(supplierCostInput);

  if (supplierLinkCancelBtn) {
    supplierLinkCancelBtn.addEventListener('click', () => {
      resetSupplierLinkForm();
    });
  }

  const supplierModal = document.getElementById('supplier-inline-modal');
  const supplierInlineForm = document.getElementById('supplier-inline-form');
  const supplierInlineNameInput = document.getElementById('supplier-inline-name');
  const supplierInlineMarginInput = document.getElementById('supplier-inline-margin');
  const supplierInlineCancelBtn = document.getElementById('supplier-inline-cancel');
  const supplierInlineSubmitBtn = document.getElementById('supplier-inline-submit');
  const supplierInlineFeedback = document.getElementById('supplier-inline-feedback');
  const supplierNewValue = '__new__';
  let previousSupplierValue = supplierSelect ? supplierSelect.value : '';

  const closeSupplierModal = () => {
    if (!supplierModal) return;
    supplierModal.classList.remove('is-open');
    supplierModal.setAttribute('aria-hidden', 'true');
    supplierInlineForm.reset();
    supplierInlineFeedback.hidden = true;
    supplierInlineFeedback.textContent = '';
    supplierInlineSubmitBtn.disabled = false;
  };

  const openSupplierModal = () => {
    if (!supplierModal) return;
    supplierModal.classList.add('is-open');
    supplierModal.setAttribute('aria-hidden', 'false');
    setTimeout(() => supplierInlineNameInput.focus(), 0);
  };

  if (supplierSelect) {
    supplierSelect.addEventListener('focus', () => {
      if (supplierSelect.value !== supplierNewValue) {
        previousSupplierValue = supplierSelect.value;
      }
    });

    supplierSelect.addEventListener('change', () => {
      if (supplierSelect.value === supplierNewValue) {
        openSupplierModal();
      } else {
        previousSupplierValue = supplierSelect.value;
      }
    });
  }

  if (supplierInlineCancelBtn) {
    supplierInlineCancelBtn.addEventListener('click', () => {
      if (supplierSelect) supplierSelect.value = previousSupplierValue;
      closeSupplierModal();
    });
  }

  if (supplierModal) {
    supplierModal.addEventListener('click', (event) => {
      if (event.target === supplierModal) {
        if (supplierSelect) supplierSelect.value = previousSupplierValue;
        closeSupplierModal();
      }
    });
  }

  if (supplierInlineForm) {
    supplierInlineForm.addEventListener('submit', async (event) => {
      event.preventDefault();

      const supplierName = supplierInlineNameInput.value.trim();
      const supplierMargin = supplierInlineMarginInput ? supplierInlineMarginInput.value.trim() : '0';
      if (supplierName === '') {
        supplierInlineFeedback.hidden = false;
        supplierInlineFeedback.textContent = 'Ingresá un nombre.';
        supplierInlineNameInput.focus();
        return;
      }

      if (supplierMargin === '') {
        supplierInlineFeedback.hidden = false;
        supplierInlineFeedback.textContent = 'Ingresá una base (%).';
        supplierInlineMarginInput?.focus();
        return;
      }

      supplierInlineSubmitBtn.disabled = true;
      supplierInlineFeedback.hidden = true;

      try {
        const body = new URLSearchParams();
        body.append('action', 'create_supplier_inline');
        body.append('supplier_name', supplierName);
        body.append('default_margin_percent', supplierMargin);

        const response = await fetch(window.location.href, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: body.toString(),
        });

        const data = await response.json();
        if (!response.ok || !data.ok || !data.supplier) {
          throw new Error(data.message || 'No se pudo crear el proveedor.');
        }

        const supplierId = String(data.supplier.id);
        let option = supplierSelect.querySelector('option[value="' + supplierId.replace(/"/g, '\"') + '"]');
        if (!option) {
          option = document.createElement('option');
          option.value = supplierId;
          option.textContent = data.supplier.name;
          supplierSelect.appendChild(option);
        }
        supplierSelect.value = supplierId;
        previousSupplierValue = supplierSelect.value;
        closeSupplierModal();
      } catch (error) {
        supplierInlineFeedback.hidden = false;
        supplierInlineFeedback.textContent = error instanceof Error ? error.message : 'No se pudo crear el proveedor.';
      } finally {
        supplierInlineSubmitBtn.disabled = false;
      }
    });
  }
</script>

</body>
</html>
