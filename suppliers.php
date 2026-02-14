<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
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

  if ($action === 'create_supplier') {
    $name = trim(post('name'));
    $margin = normalize_margin_percent_value(post('default_margin_percent'));

    if ($name === '') {
      $error = 'Ingresá el nombre del proveedor.';
    } elseif ($margin === null) {
      $error = 'Base (%) inválida. Usá un valor entre 0 y 999.99.';
    } else {
      try {
        $st = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
        $st->execute([$name]);
        if ($st->fetch()) {
          $error = 'Ese proveedor ya existe.';
        } else {
          $st = $pdo->prepare('INSERT INTO suppliers(name, default_margin_percent, is_active, updated_at) VALUES(?, ?, 1, NOW())');
          $st->execute([$name, $margin]);
          header('Location: suppliers.php?created=1');
          exit;
        }
      } catch (Throwable $t) {
        $error = 'No se pudo crear el proveedor.';
      }
    }
  }

  if ($action === 'update_supplier') {
    $id = (int)post('id', '0');
    $name = trim(post('name'));
    $margin = normalize_margin_percent_value(post('default_margin_percent'));

    if ($id <= 0) {
      $error = 'Proveedor inválido.';
    } elseif ($name === '') {
      $error = 'Ingresá el nombre del proveedor.';
    } elseif ($margin === null) {
      $error = 'Base (%) inválida. Usá un valor entre 0 y 999.99.';
    } else {
      try {
        $st = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
        $st->execute([$name, $id]);
        if ($st->fetch()) {
          $error = 'Ese proveedor ya existe.';
        } else {
          $st = $pdo->prepare('UPDATE suppliers SET name = ?, default_margin_percent = ?, updated_at = NOW() WHERE id = ?');
          $st->execute([$name, $margin, $id]);
          header('Location: suppliers.php?updated=1');
          exit;
        }
      } catch (Throwable $t) {
        $error = 'No se pudo modificar el proveedor.';
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

$listSql = "SELECT s.id, s.name, s.default_margin_percent, s.is_active
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
  $st = $pdo->prepare('SELECT id, name, default_margin_percent FROM suppliers WHERE id = ? LIMIT 1');
  $st->execute([$editId]);
  $editSupplier = $st->fetch();
}

$queryBase = [];
if ($q !== '') $queryBase['q'] = $q;
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);
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
        <span class="muted">Administrá proveedores y su base (%).</span>
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
            <input class="form-control" type="number" name="default_margin_percent" min="0" max="999.99" step="0.01" placeholder="0, 20, 30..." required value="<?= e($editSupplier ? number_format((float)$editSupplier['default_margin_percent'], 2, '.', '') : '0') ?>">
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

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Base (%)</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$suppliers): ?>
              <tr><td colspan="4">Sin proveedores.</td></tr>
            <?php else: ?>
              <?php foreach ($suppliers as $supplier): ?>
                <tr>
                  <td><?= e($supplier['name']) ?></td>
                  <td><?= e(number_format((float)$supplier['default_margin_percent'], 2, '.', '')) ?></td>
                  <td><?= (int)$supplier['is_active'] === 1 ? 'Activo' : 'No' ?></td>
                  <td>
                    <a class="btn btn-ghost btn-sm" href="suppliers.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $page, 'edit_id' => (int)$supplier['id']])) ) ?>">Modificar</a>
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
</body>
</html>
