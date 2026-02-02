<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
require_role(['superadmin'], 'Solo superadmin puede administrar roles.');

ensure_roles_defaults();

$roles_def = role_default_definitions();
$role_keys = array_keys($roles_def);
$perm_defaults = permission_default_definitions();
$perm_keys = array_keys($perm_defaults);

$sections = [
  'Menú' => [
    'menu_config_prestashop' => 'Config PrestaShop',
    'menu_import_csv' => 'Importar CSV',
    'menu_design' => 'Diseño',
    'menu_new_list' => 'Nuevo listado',
    'menu_new_product' => 'Nuevo producto',
    'tasks_settings' => 'Tareas · Configuración',
  ],
  'Listados' => [
    'list_can_sync' => 'Sincronizar',
    'list_can_delete_item' => 'Eliminar items',
    'list_can_close' => 'Cerrar listado',
    'list_can_open' => 'Abrir/reabrir listado',
    'list_can_scan' => 'Cargar por escaneo',
  ],
  'Productos' => [
    'product_can_edit' => 'Editar producto',
    'product_can_add_code' => 'Agregar códigos',
  ],
];

$message = '';
$error = '';

if (is_post() && post('action') === 'save_role') {
  $role_key = post('role_key');
  if (!in_array($role_key, $role_keys, true)) {
    $error = 'Rol inválido.';
  } elseif ($role_key === 'superadmin') {
    $error = 'El rol superadmin no se puede editar.';
  } else {
    $role_name = trim(post('role_name'));
    if ($role_name === '') {
      $error = 'El nombre visible es obligatorio.';
    } else {
      try {
        db()->beginTransaction();
        $st = db()->prepare("UPDATE roles SET role_name = ? WHERE role_key = ?");
        $st->execute([$role_name, $role_key]);

        foreach ($perm_keys as $perm_key) {
          $value = post('perm_' . $perm_key) === '1' ? 1 : 0;
          $st = db()->prepare("INSERT INTO role_permissions(role_key, perm_key, perm_value) VALUES(?, ?, ?)
            ON DUPLICATE KEY UPDATE perm_value = VALUES(perm_value)");
          $st->execute([$role_key, $perm_key, $value]);
        }
        db()->commit();
        $message = 'Cambios guardados.';
      } catch (Throwable $t) {
        if (db()->inTransaction()) {
          db()->rollBack();
        }
        $error = 'No se pudieron guardar los cambios.';
      }
    }
  }
}

$placeholders = implode(',', array_fill(0, count($role_keys), '?'));
$order = implode(',', array_map(fn($key) => "'" . $key . "'", $role_keys));
$st = db()->prepare("SELECT role_key, role_name, is_system FROM roles WHERE role_key IN ({$placeholders}) ORDER BY FIELD(role_key, {$order})");
$st->execute($role_keys);
$roles = $st->fetchAll();

$perm_map = [];
if ($roles) {
  $st = db()->prepare("SELECT role_key, perm_key, perm_value FROM role_permissions WHERE role_key IN ({$placeholders})");
  $st->execute($role_keys);
  $perm_rows = $st->fetchAll();
  foreach ($perm_rows as $row) {
    $perm_map[$row['role_key']][$row['perm_key']] = (bool)$row['perm_value'];
  }
}

foreach ($role_keys as $role_key) {
  foreach ($perm_keys as $perm_key) {
    if (!isset($perm_map[$role_key][$perm_key])) {
      $perm_map[$role_key][$perm_key] = !empty($perm_defaults[$perm_key][$role_key]);
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
      <h2 class="page-title">Roles y permisos</h2>
      <span class="muted">Solo superadmin puede editar.</span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <?php foreach ($roles as $role): ?>
      <?php
        $role_key = (string)$role['role_key'];
        $is_system = (bool)$role['is_system'];
        $is_locked = $role_key === 'superadmin' || $is_system;
      ?>
      <div class="card stack" style="margin-bottom:16px;">
        <div class="card-header">
          <h3 class="card-title"><?= e($role_key) ?></h3>
          <?php if ($is_locked): ?>
            <span class="badge badge-muted">Superadmin no se puede editar</span>
          <?php endif; ?>
        </div>

        <form method="post" class="stack">
          <input type="hidden" name="action" value="save_role">
          <input type="hidden" name="role_key" value="<?= e($role_key) ?>">

          <div class="form-row">
            <div><strong>ID:</strong> <?= e($role_key) ?></div>
            <div class="form-group">
              <label class="form-label">Nombre visible</label>
              <input class="form-control" type="text" name="role_name" value="<?= e($role['role_name']) ?>" <?= $is_locked ? 'disabled' : '' ?> required>
            </div>
          </div>

          <?php foreach ($sections as $title => $perm_list): ?>
            <div class="card" style="padding:16px;">
              <strong><?= e($title) ?></strong>
              <div class="form-row" style="flex-wrap:wrap;">
                <?php foreach ($perm_list as $perm_key => $label): ?>
                  <?php $checked = !empty($perm_map[$role_key][$perm_key]); ?>
                  <label class="form-check" style="min-width:220px;">
                    <input type="checkbox" name="perm_<?= e($perm_key) ?>" value="1" <?= $checked ? 'checked' : '' ?> <?= $is_locked ? 'disabled' : '' ?>>
                    <span><?= e($label) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if (!$is_locked): ?>
            <div class="form-actions">
              <button class="btn" type="submit">Guardar cambios</button>
            </div>
          <?php endif; ?>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</main>
</body>
</html>
