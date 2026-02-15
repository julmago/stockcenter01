<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/supplier_import_lib.php';
require_login();
ensure_product_suppliers_schema();

$pdo = db();
$q = trim(get('q', ''));
$page = max(1, (int)get('page', '1'));
$limit = 25;
$offset = ($page - 1) * $limit;

$error = '';
$message = '';

if (is_post()) {
  $action = post('action');

  if ($action === 'create_supplier' || $action === 'update_supplier') {
    $id = (int)post('id', '0');
    $name = trim(post('name'));
    $margin = normalize_margin_percent_value(post('base_margin_percent'));
    $dedupeMode = strtoupper(trim((string)post('import_dedupe_mode', 'LAST')));
    $defaultCostType = strtoupper(trim((string)post('import_default_cost_type', 'UNIDAD')));
    $defaultUnitsRaw = trim((string)post('import_default_units_per_pack', ''));
    $defaultDiscount = supplier_import_normalize_discount(post('import_discount_default', '0'));

    if (!in_array($dedupeMode, ['LAST', 'FIRST', 'MIN', 'MAX', 'PREFER_PROMO'], true)) {
      $dedupeMode = 'LAST';
    }
    if (!in_array($defaultCostType, ['UNIDAD', 'PACK'], true)) {
      $defaultCostType = 'UNIDAD';
    }

    $defaultUnits = null;
    if ($defaultUnitsRaw !== '') {
      $defaultUnits = (int)$defaultUnitsRaw;
      if ($defaultUnits <= 0) {
        $error = 'Units por pack default inválido.';
      }
    }

    if ($name === '') {
      $error = 'Ingresá el nombre del proveedor.';
    } elseif ($margin === null) {
      $error = 'Base (%) inválida. Usá un valor entre 0 y 999.99.';
    } elseif ($defaultDiscount === null) {
      $error = 'Descuento default inválido. Usá un valor entre -100 y 100.';
    }

    if ($error === '') {
      try {
        if ($action === 'create_supplier') {
          $st = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
          $st->execute([$name]);
          if ($st->fetch()) {
            $error = 'Ese proveedor ya existe.';
          } else {
            $st = $pdo->prepare('INSERT INTO suppliers(name, default_margin_percent, base_margin_percent, import_dedupe_mode, import_default_cost_type, import_default_units_per_pack, import_discount_default, is_active, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?, 1, NOW())');
            $st->execute([$name, $margin, $margin, $dedupeMode, $defaultCostType, $defaultUnits, $defaultDiscount]);
            header('Location: suppliers.php?created=1');
            exit;
          }
        } else {
          if ($id <= 0) {
            $error = 'Proveedor inválido.';
          } else {
            $st = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
            $st->execute([$name, $id]);
            if ($st->fetch()) {
              $error = 'Ese proveedor ya existe.';
            } else {
              $st = $pdo->prepare('UPDATE suppliers SET name = ?, default_margin_percent = ?, base_margin_percent = ?, import_dedupe_mode = ?, import_default_cost_type = ?, import_default_units_per_pack = ?, import_discount_default = ?, updated_at = NOW() WHERE id = ?');
              $st->execute([$name, $margin, $margin, $dedupeMode, $defaultCostType, $defaultUnits, $defaultDiscount, $id]);
              header('Location: suppliers.php?updated=1');
              exit;
            }
          }
        }
      } catch (Throwable $t) {
        $error = 'No se pudo guardar el proveedor.';
      }
    }
  }
}

if (get('created') === '1') {
  $message = 'Proveedor creado.';
}
if (get('updated') === '1') {
  $message = 'Proveedor modificado.';
}

$where = '';
$params = [];
if ($q !== '') {
  $where = 'WHERE s.name LIKE :q';
  $params[':q'] = '%' . $q . '%';
}

$countSql = "SELECT COUNT(*) FROM suppliers s $where";
$countSt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
  $countSt->bindValue($key, $value, PDO::PARAM_STR);
}
$countSt->execute();
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$listSql = "SELECT s.id, s.name, s.base_margin_percent, s.import_dedupe_mode, s.import_default_cost_type, s.import_default_units_per_pack, s.import_discount_default, s.is_active
  FROM suppliers s
  $where
  ORDER BY s.name ASC
  LIMIT :limit OFFSET :offset";
$listSt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
  $listSt->bindValue($key, $value, PDO::PARAM_STR);
}
$listSt->bindValue(':limit', $limit, PDO::PARAM_INT);
$listSt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listSt->execute();
$suppliers = $listSt->fetchAll();

$editId = (int)get('edit_id', '0');
$editSupplier = null;
if ($editId > 0) {
  $st = $pdo->prepare('SELECT id, name, base_margin_percent, import_dedupe_mode, import_default_cost_type, import_default_units_per_pack, import_discount_default FROM suppliers WHERE id = ? LIMIT 1');
  $st->execute([$editId]);
  $editSupplier = $st->fetch();
}

$queryBase = [];
if ($q !== '') $queryBase['q'] = $q;
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);
$dedupeModeLabels = [
  'LAST' => 'Ultimo precio',
  'FIRST' => 'Primer precio',
  'MIN' => 'Precio mas bajo',
  'MAX' => 'Precio mas alto',
  'PREFER_PROMO' => 'Precio promo',
];
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
      <div>
        <h2 class="page-title">Proveedores</h2>
        <span class="muted">Administrá proveedores y reglas de importación.</span>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
      <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="get" action="suppliers.php" class="stack">
        <div class="input-icon">
          <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre">
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Buscar</button>
          <?php if ($q !== ''): ?><a class="btn btn-ghost" href="suppliers.php">Limpiar</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><?= $editSupplier ? 'Modificar proveedor' : 'Nuevo proveedor' ?></h3>
      </div>
      <form method="post" class="stack">
        <input type="hidden" name="action" value="<?= $editSupplier ? 'update_supplier' : 'create_supplier' ?>">
        <?php if ($editSupplier): ?>
          <input type="hidden" name="id" value="<?= (int)$editSupplier['id'] ?>">
        <?php endif; ?>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-4);">
          <label class="form-field">
            <span class="form-label">Nombre del proveedor</span>
            <input class="form-control" type="text" name="name" maxlength="190" required value="<?= e($editSupplier ? (string)$editSupplier['name'] : '') ?>">
          </label>
          <label class="form-field">
            <span class="form-label">Base (%)</span>
            <input class="form-control" type="number" name="base_margin_percent" min="0" max="999.99" step="0.01" required value="<?= e($editSupplier ? number_format((float)$editSupplier['base_margin_percent'], 2, '.', '') : '0') ?>">
          </label>
          <label class="form-field">
            <span class="form-label">Regla duplicados</span>
            <?php $dedupeValue = $editSupplier ? (string)$editSupplier['import_dedupe_mode'] : 'LAST'; ?>
            <select class="form-control" name="import_dedupe_mode">
              <?php foreach ($dedupeModeLabels as $mode => $label): ?>
                <option value="<?= e($mode) ?>" <?= $dedupeValue === $mode ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="form-field">
            <span class="form-label">Cost type default</span>
            <?php $costTypeValue = $editSupplier ? (string)$editSupplier['import_default_cost_type'] : 'UNIDAD'; ?>
            <select class="form-control" name="import_default_cost_type">
              <option value="UNIDAD" <?= $costTypeValue === 'UNIDAD' ? 'selected' : '' ?>>UNIDAD</option>
              <option value="PACK" <?= $costTypeValue === 'PACK' ? 'selected' : '' ?>>PACK</option>
            </select>
          </label>
          <label class="form-field">
            <span class="form-label">Units por pack default</span>
            <input class="form-control" type="number" min="1" step="1" name="import_default_units_per_pack" value="<?= e($editSupplier && $editSupplier['import_default_units_per_pack'] !== null ? (string)$editSupplier['import_default_units_per_pack'] : '') ?>">
          </label>
          <label class="form-field">
            <span class="form-label">Descuento default (%)</span>
            <input class="form-control" type="number" min="-100" max="100" step="0.01" name="import_discount_default" value="<?= e($editSupplier && $editSupplier['import_discount_default'] !== null ? number_format((float)$editSupplier['import_discount_default'], 2, '.', '') : '0') ?>">
          </label>
        </div>
        <div class="inline-actions">
          <?php if ($editSupplier): ?>
            <a class="btn btn-ghost" href="suppliers.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Cancelar</a>
            <button class="btn" type="submit">Guardar</button>
          <?php else: ?>
            <button class="btn" type="submit">Agregar</button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <?php if ($editSupplier): ?>
      <div class="card" id="importacion">
        <div class="card-header">
          <h3 class="card-title">Importación</h3>
        </div>
        <form method="post" action="supplier_import.php" enctype="multipart/form-data" class="stack">
          <input type="hidden" name="supplier_id" value="<?= (int)$editSupplier['id'] ?>">
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-4);">
            <label class="form-field">
              <span class="form-label">Tipo de fuente</span>
              <select class="form-control" name="source_type" id="source-type-select" required>
                <option value="FILE">Archivo</option>
                <option value="PASTE">Pegar texto</option>
              </select>
            </label>
            <label class="form-field" id="source-file-field">
              <span class="form-label">Archivo</span>
              <input class="form-control" type="file" name="source_file" id="source-file-input" accept=".csv,.xlsx,.xls,.txt,.pdf">
              <small class="muted" id="detected-file-format">Detectado: —</small>
            </label>
          </div>
          <label class="form-field" id="paste-text-field" style="display:none;">
            <span class="form-label">Pegar texto</span>
            <textarea class="form-control" name="paste_text" id="paste-text-input" rows="10" placeholder="Pegá contenido desde WhatsApp / email / txt"></textarea>
          </label>
          <label class="form-field" id="paste-separator-field" style="display:none; max-width: 280px;">
            <span class="form-label">Separador</span>
            <select class="form-control" name="paste_separator">
              <option value="AUTO">Automático</option>
              <option value="TAB">Tab</option>
              <option value="SEMICOLON">;</option>
              <option value="COMMA">,</option>
              <option value="PIPE">| (pipe)</option>
            </select>
          </label>
          <p class="muted">El sistema detecta formato automáticamente para archivos y analiza encabezados antes del Paso 2.</p>
          <div class="inline-actions">
            <button class="btn" type="submit">Continuar a Paso 2</button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Base (%)</th>
              <th>Duplicados</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$suppliers): ?>
              <tr><td colspan="5">Sin proveedores.</td></tr>
            <?php else: ?>
              <?php foreach ($suppliers as $supplier): ?>
                <tr>
                  <td><?= e($supplier['name']) ?></td>
                  <td><?= e(number_format((float)$supplier['base_margin_percent'], 2, '.', '')) ?></td>
                  <td><?= e((string)$supplier['import_dedupe_mode']) ?></td>
                  <td><?= (int)$supplier['is_active'] === 1 ? 'Activo' : 'No' ?></td>
                  <td>
                    <div class="inline-actions">
                      <a class="btn btn-ghost btn-sm" href="suppliers.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $page, 'edit_id' => (int)$supplier['id']])) ) ?>">Modificar</a>
                      <a class="btn btn-ghost btn-sm" href="suppliers.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $page, 'edit_id' => (int)$supplier['id']])) ) ?>#importacion">Importar lista</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="inline-actions">
        <?php
          $prevQuery = $queryBase;
          $prevQuery['page'] = $prevPage;
          $nextQuery = $queryBase;
          $nextQuery['page'] = $nextPage;
        ?>
        <?php if ($page > 1): ?>
          <a class="btn btn-ghost" href="suppliers.php?<?= e(http_build_query($prevQuery)) ?>">&laquo; Anterior</a>
        <?php else: ?>
          <span class="muted">&laquo; Anterior</span>
        <?php endif; ?>
        <span class="muted">Página <?= (int)$page ?> de <?= (int)$totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="btn btn-ghost" href="suppliers.php?<?= e(http_build_query($nextQuery)) ?>">Siguiente &raquo;</a>
        <?php else: ?>
          <span class="muted">Siguiente &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<script>
  const sourceType = document.getElementById('source-type-select');
  const pasteField = document.getElementById('paste-text-field');
  const pasteSeparatorField = document.getElementById('paste-separator-field');
  const fileField = document.getElementById('source-file-field');
  const fileInput = document.getElementById('source-file-input');
  const detectedFileFormat = document.getElementById('detected-file-format');

  const detectFromName = (name) => {
    const ext = (name.split('.').pop() || '').toLowerCase();
    if (ext === 'xlsx') return 'XLSX';
    if (ext === 'xls') return 'XLS';
    if (ext === 'csv') return 'CSV';
    if (ext === 'txt') return 'TXT';
    if (ext === 'pdf') return 'PDF';
    return 'Desconocido';
  };

  if (sourceType && pasteField && fileField && pasteSeparatorField) {
    const sync = () => {
      const isPaste = sourceType.value === 'PASTE';
      pasteField.style.display = isPaste ? 'block' : 'none';
      pasteSeparatorField.style.display = isPaste ? 'block' : 'none';
      fileField.style.display = isPaste ? 'none' : 'block';
    };
    sourceType.addEventListener('change', sync);
    sync();
  }

  if (fileInput && detectedFileFormat) {
    fileInput.addEventListener('change', () => {
      const f = fileInput.files && fileInput.files[0];
      if (!f) {
        detectedFileFormat.textContent = 'Detectado: —';
        return;
      }
      detectedFileFormat.textContent = `Detectado: ${detectFromName(f.name)}`;
    });
  }
</script>
</body>
</html>
