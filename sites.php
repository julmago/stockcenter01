<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_sites_schema();

$pdo = db();
$q = trim(get('q', ''));
$page = max(1, (int)get('page', '1'));
$limit = 25;
$offset = ($page - 1) * $limit;

$error = '';
$message = '';

if (is_post()) {
  $action = post('action');

  if ($action === 'create_site') {
    $name = trim(post('name'));
    $margin = normalize_site_margin_percent_value(post('margin_percent'));
    $isActive = post('is_active') === '1' ? 1 : 0;

    if ($name === '') {
      $error = 'Ingresá el nombre del sitio.';
    } elseif (mb_strlen($name) > 80) {
      $error = 'El nombre del sitio no puede superar los 80 caracteres.';
    } elseif ($margin === null) {
      $error = 'Margen (%) inválido. Usá un valor entre -100 y 999.99.';
    } else {
      try {
        $st = $pdo->prepare('SELECT id FROM sites WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
        $st->execute([$name]);
        if ($st->fetch()) {
          $error = 'Ese sitio ya existe.';
        } else {
          $st = $pdo->prepare('INSERT INTO sites(name, margin_percent, is_active, updated_at) VALUES(?, ?, ?, NOW())');
          $st->execute([$name, $margin, $isActive]);
          header('Location: sites.php?created=1');
          exit;
        }
      } catch (Throwable $t) {
        $error = 'No se pudo crear el sitio.';
      }
    }
  }

  if ($action === 'update_site') {
    $id = (int)post('id', '0');
    $name = trim(post('name'));
    $margin = normalize_site_margin_percent_value(post('margin_percent'));
    $isActive = post('is_active') === '1' ? 1 : 0;

    if ($id <= 0) {
      $error = 'Sitio inválido.';
    } elseif ($name === '') {
      $error = 'Ingresá el nombre del sitio.';
    } elseif (mb_strlen($name) > 80) {
      $error = 'El nombre del sitio no puede superar los 80 caracteres.';
    } elseif ($margin === null) {
      $error = 'Margen (%) inválido. Usá un valor entre -100 y 999.99.';
    } else {
      try {
        $st = $pdo->prepare('SELECT id FROM sites WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
        $st->execute([$name, $id]);
        if ($st->fetch()) {
          $error = 'Ese sitio ya existe.';
        } else {
          $st = $pdo->prepare('UPDATE sites SET name = ?, margin_percent = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
          $st->execute([$name, $margin, $isActive, $id]);
          header('Location: sites.php?updated=1');
          exit;
        }
      } catch (Throwable $t) {
        $error = 'No se pudo modificar el sitio.';
      }
    }
  }

  if ($action === 'toggle_site') {
    $id = (int)post('id', '0');
    if ($id > 0) {
      try {
        $st = $pdo->prepare('UPDATE sites SET is_active = (1 - is_active), updated_at = NOW() WHERE id = ?');
        $st->execute([$id]);
        header('Location: sites.php?toggled=1');
        exit;
      } catch (Throwable $t) {
        $error = 'No se pudo cambiar el estado del sitio.';
      }
    }
  }
}

if (get('created') === '1') {
  $message = 'Sitio creado.';
}
if (get('updated') === '1') {
  $message = 'Sitio modificado.';
}
if (get('toggled') === '1') {
  $message = 'Estado del sitio actualizado.';
}

$where = '';
$params = [];
if ($q !== '') {
  $where = 'WHERE s.name LIKE :q';
  $params[':q'] = '%' . $q . '%';
}

$countSql = "SELECT COUNT(*) FROM sites s $where";
$countSt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
  $countSt->bindValue($key, $value, PDO::PARAM_STR);
}
$countSt->execute();
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$listSql = "SELECT s.id, s.name, s.margin_percent, s.is_active
  FROM sites s
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
$sites = $listSt->fetchAll();

$editId = (int)get('edit_id', '0');
$editSite = null;
if ($editId > 0) {
  $st = $pdo->prepare('SELECT id, name, margin_percent, is_active FROM sites WHERE id = ? LIMIT 1');
  $st->execute([$editId]);
  $editSite = $st->fetch();
}

$showNewForm = get('new') === '1' || $editSite !== null;

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
        <h2 class="page-title">Sitios</h2>
        <span class="muted">Configurá márgenes por canal (extra %).</span>
      </div>
      <div class="inline-actions">
        <?php if ($showNewForm && !$editSite): ?>
          <a class="btn btn-ghost" href="sites.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Cancelar</a>
        <?php else: ?>
          <a class="btn" href="sites.php?<?= e(http_build_query(array_merge($queryBase, ['new' => 1]))) ?>">Nuevo sitio</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
      <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="get" action="sites.php" class="stack">
        <div class="input-icon">
          <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre">
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Buscar</button>
          <?php if ($q !== ''): ?><a class="btn btn-ghost" href="sites.php">Limpiar</a><?php endif; ?>
        </div>
      </form>
    </div>

    <?php if ($showNewForm): ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><?= $editSite ? 'Modificar sitio' : 'Nuevo sitio' ?></h3>
        </div>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="<?= $editSite ? 'update_site' : 'create_site' ?>">
          <?php if ($editSite): ?>
            <input type="hidden" name="id" value="<?= (int)$editSite['id'] ?>">
          <?php endif; ?>
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-4);">
            <label class="form-field">
              <span class="form-label">Nombre del sitio</span>
              <input class="form-control" type="text" name="name" maxlength="80" required value="<?= e($editSite ? (string)$editSite['name'] : '') ?>">
            </label>
            <label class="form-field">
              <span class="form-label">Margen (%)</span>
              <input class="form-control" type="number" name="margin_percent" min="-100" max="999.99" step="0.01" required value="<?= e($editSite ? number_format((float)$editSite['margin_percent'], 2, '.', '') : '0') ?>">
            </label>
            <label class="form-field" style="align-self: end;">
              <span class="form-label">Estado</span>
              <select class="form-control" name="is_active">
                <?php $activeValue = $editSite ? (int)$editSite['is_active'] : 1; ?>
                <option value="1" <?= $activeValue === 1 ? 'selected' : '' ?>>Activo</option>
                <option value="0" <?= $activeValue === 0 ? 'selected' : '' ?>>Inactivo</option>
              </select>
            </label>
          </div>
          <div class="inline-actions">
            <a class="btn btn-ghost" href="sites.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Cancelar</a>
            <button class="btn" type="submit"><?= $editSite ? 'Guardar' : 'Agregar' ?></button>
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
              <th>Margen (%)</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$sites): ?>
              <tr><td colspan="4">Sin sitios.</td></tr>
            <?php else: ?>
              <?php foreach ($sites as $site): ?>
                <tr>
                  <td><?= e($site['name']) ?></td>
                  <td><?= e(number_format((float)$site['margin_percent'], 2, '.', '')) ?></td>
                  <td><?= (int)$site['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                  <td>
                    <div class="inline-actions">
                      <a class="btn btn-ghost btn-sm" href="sites.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $page, 'edit_id' => (int)$site['id']])) ) ?>">Modificar</a>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="toggle_site">
                        <input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= (int)$site['is_active'] === 1 ? 'Inactivar' : 'Activar' ?></button>
                      </form>
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
          <a class="btn btn-ghost" href="sites.php?<?= e(http_build_query($prevQuery)) ?>">&laquo; Anterior</a>
        <?php else: ?>
          <span class="muted">&laquo; Anterior</span>
        <?php endif; ?>
        <span class="muted">Página <?= (int)$page ?> de <?= (int)$totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="btn btn-ghost" href="sites.php?<?= e(http_build_query($nextQuery)) ?>">Siguiente &raquo;</a>
        <?php else: ?>
          <span class="muted">Siguiente &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
</body>
</html>
