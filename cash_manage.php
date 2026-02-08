<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();
require_permission(hasPerm('cashbox_manage_boxes'), 'Sin permiso para administrar cajas.');

$message = '';
$error = '';
$default_denoms = [100, 500, 1000, 2000, 10000, 20000];

if (is_post() && post('action') === 'create_cashbox') {
  $name = trim((string)post('name'));
  if ($name === '') {
    $error = 'El nombre de la caja es obligatorio.';
  } elseif (mb_strlen($name) > 120) {
    $error = 'El nombre debe tener hasta 120 caracteres.';
  } else {
    try {
      db()->beginTransaction();
      $st = db()->prepare("INSERT INTO cashboxes (name, is_active, created_by_user_id) VALUES (?, 1, ?)");
      $st->execute([$name, (int)current_user()['id']]);
      $cashbox_id = (int)db()->lastInsertId();

      $denom_st = db()->prepare("INSERT INTO cash_denominations (cashbox_id, value, is_active, sort_order) VALUES (?, ?, 1, ?)");
      $sort = 10;
      foreach ($default_denoms as $value) {
        $denom_st->execute([$cashbox_id, $value, $sort]);
        $sort += 10;
      }
      db()->commit();
      $message = 'Caja creada correctamente.';
    } catch (Throwable $t) {
      if (db()->inTransaction()) {
        db()->rollBack();
      }
      $error = 'No se pudo crear la caja.';
    }
  }
}

if (is_post() && post('action') === 'toggle_cashbox') {
  $cashbox_id = (int)post('cashbox_id');
  $is_active = post('is_active') === '1' ? 1 : 0;
  $st = db()->prepare("UPDATE cashboxes SET is_active = ? WHERE id = ?");
  $st->execute([$is_active, $cashbox_id]);
  $message = 'Estado actualizado.';
}

if (is_post() && post('action') === 'delete_cashbox') {
  $cashbox_id = (int)post('cashbox_id');
  $st = db()->prepare("SELECT COUNT(*) FROM cash_movements WHERE cashbox_id = ?");
  $st->execute([$cashbox_id]);
  $movements = (int)$st->fetchColumn();
  if ($movements > 0) {
    $error = 'No se puede eliminar la caja porque tiene movimientos.';
  } else {
    $st = db()->prepare("DELETE FROM cashboxes WHERE id = ?");
    $st->execute([$cashbox_id]);
    $message = 'Caja eliminada.';
  }
}

$list_st = db()->query("SELECT c.*, (
  SELECT COUNT(*) FROM cash_movements m WHERE m.cashbox_id = c.id
) AS movement_count
FROM cashboxes c
ORDER BY c.created_at DESC");
$cashboxes = $list_st->fetchAll();
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
      <span class="muted">Creá, activá o eliminá cajas.</span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Nueva caja</h3>
      </div>
      <form method="post" class="stack">
        <input type="hidden" name="action" value="create_cashbox">
        <div class="form-row">
          <div class="form-group" style="min-width: 260px;">
            <label class="form-label">Nombre</label>
            <input class="form-control" type="text" name="name" maxlength="120" required>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">Crear caja</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Cajas existentes</h3>
      </div>
      <?php if ($cashboxes): ?>
        <table class="cash-table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Estado</th>
              <th>Movimientos</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cashboxes as $cashbox): ?>
              <?php $is_active = (int)$cashbox['is_active'] === 1; ?>
              <tr>
                <td><?= e($cashbox['name']) ?></td>
                <td><?= $is_active ? 'Activa' : 'Inactiva' ?></td>
                <td><?= (int)$cashbox['movement_count'] ?></td>
                <td>
                  <form method="post" style="display: inline-flex; gap: 0.5rem; flex-wrap: wrap;">
                    <input type="hidden" name="cashbox_id" value="<?= (int)$cashbox['id'] ?>">
                    <input type="hidden" name="action" value="toggle_cashbox">
                    <input type="hidden" name="is_active" value="<?= $is_active ? '0' : '1' ?>">
                    <button class="btn btn-ghost" type="submit"><?= $is_active ? 'Desactivar' : 'Activar' ?></button>
                  </form>
                  <form method="post" style="display: inline-flex; gap: 0.5rem; flex-wrap: wrap;">
                    <input type="hidden" name="cashbox_id" value="<?= (int)$cashbox['id'] ?>">
                    <input type="hidden" name="action" value="delete_cashbox">
                    <button class="btn btn-ghost" type="submit" <?= (int)$cashbox['movement_count'] > 0 ? 'disabled' : '' ?>>Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="alert alert-info">No hay cajas registradas.</div>
      <?php endif; ?>
    </div>
  </div>
</main>

</body>
</html>
