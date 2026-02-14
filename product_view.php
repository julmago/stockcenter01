<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_product_suppliers_schema();
ensure_brands_schema();

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

  if ($error === '' && $supplier_id <= 0) {
    $error = 'Seleccioná un proveedor.';
  }

  if ($error === '') {
    try {
      db()->beginTransaction();
      $st = db()->prepare("UPDATE product_suppliers SET is_active = 0, updated_at = NOW() WHERE product_id = ?");
      $st->execute([$id]);

      $st = db()->prepare("INSERT INTO product_suppliers(product_id, supplier_id, supplier_sku, cost_type, units_per_pack, is_active, updated_at) VALUES(?, ?, ?, ?, ?, ?, NOW())");
      $st->execute([$id, $supplier_id, $supplier_sku, $cost_type, $units_per_pack_value, 1]);
      db()->commit();
      $message = 'Proveedor vinculado.';
    } catch (Throwable $t) {
      if (db()->inTransaction()) db()->rollBack();
      $error = 'No se pudo vincular el proveedor.';
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
  if ($supplier_name === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Ingresá el nombre del proveedor.']);
    exit;
  }

  try {
    $st = db()->prepare('SELECT id, name FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $st->execute([$supplier_name]);
    $existing = $st->fetch();

    if ($existing) {
      http_response_code(409);
      echo json_encode(['ok' => false, 'message' => 'Ya existe un proveedor con ese nombre.']);
      exit;
    }

    $st = db()->prepare('INSERT INTO suppliers(name, is_active, updated_at) VALUES(?, 1, NOW())');
    $st->execute([$supplier_name]);

    $supplier_id = (int)db()->lastInsertId();

    echo json_encode([
      'ok' => true,
      'supplier' => [
        'id' => $supplier_id,
        'name' => $supplier_name,
      ],
    ]);
    exit;
  } catch (Throwable $t) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'No se pudo crear el proveedor.']);
    exit;
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

$st = db()->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC");
$suppliers = $st->fetchAll();

$st = db()->prepare("SELECT ps.id, ps.supplier_id, ps.supplier_sku, ps.cost_type, ps.units_per_pack, ps.is_active, s.name AS supplier_name
  FROM product_suppliers ps
  INNER JOIN suppliers s ON s.id = ps.supplier_id
  WHERE ps.product_id = ?
  ORDER BY ps.is_active DESC, ps.id DESC");
$st->execute([$id]);
$supplier_links = $st->fetchAll();

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

    .product-linked-suppliers-body,
    .product-codes-body {
      /*
       * El espacio se aplica con padding interno para evitar que cualquier
       * margen del primer hijo colapse con el contenedor.
       */
      padding-top: var(--space-4);
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
        <h3 class="card-title">Proveedores vinculados</h3>
        <span class="muted small"><?= count($supplier_links) ?> vinculados</span>
      </div>
      <div class="card-body product-linked-suppliers-body">
        <?php if ($can_edit): ?>
          <form method="post" class="stack product-linked-suppliers-form">
            <input type="hidden" name="action" value="add_supplier_link">
            <div class="form-row product-supplier-form">
              <div class="form-group">
                <label class="form-label">SKU / Código del proveedor</label>
                <input class="form-control" type="text" name="supplier_sku">
              </div>
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
              <div class="product-supplier-cost-column">
                <div class="product-supplier-cost-layout" id="cost-layout-group">
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
              <button class="btn" type="submit">Agregar proveedor</button>
            </div>
          </form>
        <?php endif; ?>

        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>proveedor</th>
                <th>sku proveedor</th>
                <th>costo recibido</th>
                <th>unidades pack</th>
                <th>activo</th>
                <?php if ($can_edit): ?>
                  <th>acciones</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (!$supplier_links): ?>
                <tr><td colspan="<?= $can_edit ? 6 : 5 ?>">Sin proveedores vinculados.</td></tr>
              <?php else: ?>
                <?php foreach ($supplier_links as $link): ?>
                  <tr>
                    <td><?= e($link['supplier_name']) ?></td>
                    <td><?= e($link['supplier_sku']) ?></td>
                    <td><?= e($link['cost_type'] === 'PACK' ? 'Pack' : 'Unidad') ?></td>
                    <td><?= $link['cost_type'] === 'PACK' ? (int)$link['units_per_pack'] : '-' ?></td>
                    <td><?= (int)$link['is_active'] === 1 ? 'Sí' : 'No' ?></td>
                    <?php if ($can_edit): ?>
                      <td class="table-actions">
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
    </div>

    <?php require_once __DIR__ . '/include/partials/messages_block.php'; ?>
    <?php ts_messages_block('product', $id); ?>
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
  const supplierModal = document.getElementById('supplier-inline-modal');
  const supplierInlineForm = document.getElementById('supplier-inline-form');
  const supplierInlineNameInput = document.getElementById('supplier-inline-name');
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
      if (supplierName === '') {
        supplierInlineFeedback.hidden = false;
        supplierInlineFeedback.textContent = 'Ingresá un nombre.';
        supplierInlineNameInput.focus();
        return;
      }

      supplierInlineSubmitBtn.disabled = true;
      supplierInlineFeedback.hidden = true;

      try {
        const body = new URLSearchParams();
        body.append('action', 'create_supplier_inline');
        body.append('supplier_name', supplierName);

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

        const newOption = document.createElement('option');
        newOption.value = String(data.supplier.id);
        newOption.textContent = data.supplier.name;
        supplierSelect.appendChild(newOption);
        supplierSelect.value = String(data.supplier.id);
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
