<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_product_suppliers_schema();
require_permission(can_create_product());

$error = '';
$message = '';

if (is_post()) {
  $sku = post('sku');
  $name = post('name');
  $brand = post('brand');
  $sale_mode = post('sale_mode', 'UNIDAD');
  $sale_units_per_pack = post('sale_units_per_pack');
  $code = post('code');

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
      db()->beginTransaction();
      $st = db()->prepare("INSERT INTO products(sku, name, brand, sale_mode, sale_units_per_pack, updated_at) VALUES(?, ?, ?, ?, ?, NOW())");
      $st->execute([$sku, $name, $brand, $sale_mode, $sale_units_per_pack_value]);
      $pid = (int)db()->lastInsertId();

      if ($code !== '') {
        $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, 'BARRA')");
        $st->execute([$pid, $code]);
      }

      db()->commit();
      redirect("product_view.php?id={$pid}");
    } catch (Throwable $t) {
      if (db()->inTransaction()) db()->rollBack();
      $error = 'No se pudo crear. Verificá que el SKU no esté repetido y que el código (si cargaste) no exista.';
    }
  }
}
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
      <h2 class="page-title">Nuevo Producto</h2>
      <span class="muted">Cargá la información base del producto.</span>
    </div>
    <div class="card">
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

      <form method="post" class="stack">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">SKU (primordial)</label>
            <input class="form-control" type="text" name="sku" required>
          </div>
          <div class="form-group">
            <label class="form-label">Nombre</label>
            <input class="form-control" type="text" name="name" required>
          </div>
          <div class="form-group">
            <label class="form-label">Marca</label>
            <input class="form-control" type="text" name="brand">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Modo de venta</label>
            <select class="form-control" name="sale_mode" id="sale-mode-select" required>
              <option value="UNIDAD" <?= post('sale_mode', 'UNIDAD') === 'UNIDAD' ? 'selected' : '' ?>>Unidad</option>
              <option value="PACK" <?= post('sale_mode') === 'PACK' ? 'selected' : '' ?>>Pack</option>
            </select>
          </div>
          <div class="form-group" id="sale-units-group" style="display:none;">
            <label class="form-label">Unidades por pack</label>
            <input class="form-control" type="number" min="1" step="1" name="sale_units_per_pack" id="sale-units-input" value="<?= e(post('sale_units_per_pack')) ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Primer código (opcional, luego podés cargar más)</label>
          <input class="form-control" type="text" name="code" placeholder="Escaneá código">
        </div>

        <div class="form-actions">
          <button class="btn" type="submit">Crear</button>
          <a class="btn btn-ghost" href="dashboard.php">Volver</a>
        </div>
      </form>
    </div>
  </div>
</main>

<script>
  const saleModeSelect = document.getElementById('sale-mode-select');
  const saleUnitsGroup = document.getElementById('sale-units-group');
  const saleUnitsInput = document.getElementById('sale-units-input');

  const toggleSaleUnits = () => {
    if (!saleModeSelect || !saleUnitsGroup || !saleUnitsInput) return;
    const isPack = saleModeSelect.value === 'PACK';
    saleUnitsGroup.style.display = isPack ? '' : 'none';
    saleUnitsInput.required = isPack;
    if (!isPack) {
      saleUnitsInput.value = '';
    }
  };

  if (saleModeSelect) {
    saleModeSelect.addEventListener('change', toggleSaleUnits);
    toggleSaleUnits();
  }
</script>

</body>
</html>
