<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

require_login();
require_permission(hasPerm('cashbox_manage_boxes'), 'Sin permiso para administrar cajas.');

$user = current_user();
$role = $user['role'] ?? '';
$is_superadmin = $role === 'superadmin';

$message = '';
$error = '';

$action = is_post() ? (string)post('action') : '';
if ($action !== '') {
  if (!csrf_is_valid((string)post('csrf_token'))) {
    abort(403, 'Token inválido.');
  }

  $cashbox_id = filter_var(post('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($cashbox_id === false) {
    $error = 'ID de caja inválido.';
  } else {
    try {
      if ($action === 'activate') {
        $st = db()->prepare('UPDATE cashboxes SET is_active = 1 WHERE id = ?');
        $st->execute([$cashbox_id]);
        $message = 'Caja activada.';
      } elseif ($action === 'pause') {
        $st = db()->prepare('UPDATE cashboxes SET is_active = 0 WHERE id = ?');
        $st->execute([$cashbox_id]);
        $message = 'Caja pausada.';
      } elseif ($action === 'delete') {
        if (!$is_superadmin) {
          http_response_code(403);
          $error = 'No autorizado para eliminar cajas.';
        } else {
          $st = db()->prepare('DELETE FROM cashboxes WHERE id = ?');
          $st->execute([$cashbox_id]);
          $message = 'Caja eliminada.';
        }
      }
    } catch (Throwable $t) {
      $error = 'No se pudo actualizar la caja.';
    }
  }
}

try {
  $list_st = db()->prepare('SELECT id, name, is_active, created_by_user_id, created_at FROM cashboxes ORDER BY id DESC');
  $list_st->execute();
  $cashboxes = $list_st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {
  $cashboxes = [];
  $error = $error ?: 'No se pudo cargar el listado de cajas.';
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
      <h2 class="page-title">Administrar cajas</h2>
      <span class="muted">Gestión de cajas existentes.</span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Cajas existentes</h3>
      </div>
      <?php if ($cashboxes): ?>
        <table class="cash-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nombre</th>
              <th>Estado</th>
              <th>Creada por (ID)</th>
              <th>Creada</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cashboxes as $row): ?>
              <?php
              $cashbox_id = (int)($row['id'] ?? 0);
              $cashbox_name = (string)($row['name'] ?? '');
              $has_is_active = is_array($row) && array_key_exists('is_active', $row);
              $is_active = $has_is_active ? $row['is_active'] : null;
              $status_label = $is_active === null ? '—' : (string)$is_active;
              $created_by_user_id = (string)($row['created_by_user_id'] ?? '');
              $created_at = (string)($row['created_at'] ?? '');
              $show_activate = $has_is_active && (int)$is_active === 0;
              $show_pause = $has_is_active && (int)$is_active === 1;
              ?>
              <tr>
                <td><?= e((string)$cashbox_id) ?></td>
                <td><?= e($cashbox_name) ?></td>
                <td><?= e($status_label) ?></td>
                <td><?= e($created_by_user_id) ?></td>
                <td><?= e($created_at) ?></td>
                <td>
                  <?php if ($show_activate): ?>
                    <form method="post" style="display: inline-flex; gap: 0.5rem; flex-wrap: wrap;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="activate">
                      <input type="hidden" name="id" value="<?= $cashbox_id ?>">
                      <button class="btn btn-ghost" type="submit">Activar</button>
                    </form>
                  <?php elseif ($show_pause): ?>
                    <form method="post" style="display: inline-flex; gap: 0.5rem; flex-wrap: wrap;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="pause">
                      <input type="hidden" name="id" value="<?= $cashbox_id ?>">
                      <button class="btn btn-ghost" type="submit">Pausar</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($is_superadmin): ?>
                    <form method="post" style="display: inline-flex; gap: 0.5rem; flex-wrap: wrap;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $cashbox_id ?>">
                      <button class="btn btn-ghost" type="submit" onclick="return confirm('¿Eliminar esta caja?')">Eliminar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="alert alert-info">No hay cajas creadas</div>
      <?php endif; ?>
    </div>
  </div>
</main>

</body>
</html>
