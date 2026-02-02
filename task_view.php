<?php
require_once __DIR__ . '/tasks_lib.php';
require_login();

$pdo = db();
$current_user_id = (int)(current_user()['id'] ?? 0);

$task_id = (int)get('id', '0');
if ($task_id <= 0) {
  abort(400, 'Falta id.');
}

$return_to = get('from', 'tasks_all.php');
$allowed_returns = ['tasks_all.php', 'tasks_my.php'];
if (!in_array($return_to, $allowed_returns, true)) {
  $return_to = 'tasks_all.php';
}
$return_label = $return_to === 'tasks_my.php' ? 'Mis tareas' : 'Todas las tareas';

$st = $pdo->prepare("
  SELECT t.*,
         au.first_name AS assigned_first_name, au.last_name AS assigned_last_name,
         au.email AS assigned_email,
         cu.first_name AS created_first_name, cu.last_name AS created_last_name,
         cu.email AS created_email
  FROM tasks t
  JOIN users au ON au.id = t.assigned_user_id
  JOIN users cu ON cu.id = t.created_by_user_id
  WHERE t.id = ?
  LIMIT 1
");
$st->execute([$task_id]);
$task = $st->fetch();
if (!$task) {
  abort(404, 'Tarea no encontrada.');
}

$categories = task_categories((string)$task['category']);
$category_map = task_categories(null, true);
$statuses = task_statuses();
$priorities = task_priorities();
$related_types = task_related_types((string)$task['related_type']);
$related_types_map = task_related_types(null, true);
$users = task_users($pdo);

$creator_name = trim(($task['created_first_name'] ?? '') . ' ' . ($task['created_last_name'] ?? ''));

$error = '';
$can_edit = (int)$task['assigned_user_id'] === $current_user_id;
$saved = get('saved') === '1';
if (is_post() && post('action') === 'update') {
  if (!$can_edit) {
    abort(403, 'No tenés permisos para editar esta tarea.');
  }
  $title = trim((string)post('title'));
  $description = trim((string)post('description'));
  $category = post('category');
  $priority = post('priority');
  $status = post('status');
  $assigned_user_id = (int)post('assigned_user_id', '0');
  $due_date = trim((string)post('due_date'));
  $related_type = post('related_type');

  if ($title === '') {
    $error = 'El título es obligatorio.';
  } elseif (!array_key_exists($category, $category_map)) {
    $error = 'La categoría es obligatoria.';
  } elseif (!array_key_exists($priority, $priorities)) {
    $error = 'La prioridad es obligatoria.';
  } elseif (!array_key_exists($status, $statuses)) {
    $error = 'El estado es obligatorio.';
  } elseif ($assigned_user_id <= 0) {
    $error = 'El usuario asignado es obligatorio.';
  } elseif (!array_key_exists($related_type, $related_types_map)) {
    $error = 'El tipo relacionado es inválido.';
  } else {
    $description = $description === '' ? null : $description;
    $due_date = $due_date === '' ? null : $due_date;
    if ($error === '') {
      $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
      $check->execute([$assigned_user_id]);
      if (!$check->fetch()) {
        $error = 'El usuario asignado es inválido.';
      }
    }
    if ($error === '') {
      $st = $pdo->prepare("
        UPDATE tasks
        SET title = ?,
            description = ?,
            category = ?,
            priority = ?,
            status = ?,
            assigned_user_id = ?,
            due_date = ?,
            related_type = ?,
            updated_at = NOW()
        WHERE id = ?
      ");
      $st->execute([
        $title,
        $description,
        $category,
        $priority,
        $status,
        $assigned_user_id,
        $due_date,
        $related_type,
        $task_id,
      ]);
      $redirect_params = [
        'id' => $task_id,
        'saved' => 1,
        'from' => $return_to,
      ];
      redirect('task_view.php?' . http_build_query($redirect_params));
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Tarea #<?= (int)$task['id'] ?></title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
    <div class="page-header">
      <div>
        <h2 class="page-title">Detalle de tarea</h2>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="<?= e($return_to) ?>">Volver a <?= e($return_label) ?></a>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>
  <?php if ($saved): ?>
    <div class="alert alert-success">Cambios guardados.</div>
  <?php endif; ?>

  <?php if (!$can_edit): ?>
    <div class="alert alert-warning">
      Solo lectura: la edición está disponible para la persona asignada.
    </div>
  <?php endif; ?>

  <form method="post" class="stack">
    <input type="hidden" name="action" value="update">
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Título y descripción</h3>
          <span class="muted small">Editá la información principal de la tarea.</span>
        </div>
      </div>
      <div class="stack">
        <div class="form-group">
          <label class="form-label" for="task-title">Título</label>
          <input class="form-control" id="task-title" type="text" name="title" value="<?= e($task['title']) ?>" required <?= $can_edit ? '' : 'disabled' ?>>
        </div>
        <div class="form-group">
          <label class="form-label" for="task-description">Descripción</label>
          <textarea class="form-control" id="task-description" name="description" rows="6" <?= $can_edit ? '' : 'disabled' ?>><?= e((string)($task['description'] ?? '')) ?></textarea>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Detalle completo</h3>
          <span class="muted small">Información de estado, relación y auditoría.</span>
        </div>
      </div>
      <div class="stack">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--space-4);">
          <div class="stack">
            <div class="form-group">
              <label class="form-label" for="task-category">Categoría</label>
              <select class="form-control" id="task-category" name="category" required <?= $can_edit ? '' : 'disabled' ?>>
                <?php foreach ($categories as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $task['category'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="task-priority">Prioridad</label>
              <select class="form-control" id="task-priority" name="priority" required <?= $can_edit ? '' : 'disabled' ?>>
                <?php foreach ($priorities as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $task['priority'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="task-status">Estado</label>
              <select class="form-control" id="task-status" name="status" required <?= $can_edit ? '' : 'disabled' ?>>
                <?php foreach ($statuses as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $task['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="stack">
            <div class="form-group">
              <label class="form-label" for="task-assigned">Asignado a</label>
              <select class="form-control" id="task-assigned" name="assigned_user_id" required <?= $can_edit ? '' : 'disabled' ?>>
                <?php foreach ($users as $user): ?>
                  <?php
                  $user_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                  $user_label = $user_name !== '' ? $user_name : ($user['email'] ?? '');
                  ?>
                  <option value="<?= (int)$user['id'] ?>" <?= (int)$task['assigned_user_id'] === (int)$user['id'] ? 'selected' : '' ?>>
                    <?= e($user_label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="task-due-date">Fecha límite</label>
              <input class="form-control" id="task-due-date" type="date" name="due_date" value="<?= e($task['due_date'] ? substr((string)$task['due_date'], 0, 10) : '') ?>" <?= $can_edit ? '' : 'disabled' ?>>
            </div>
            <div class="form-group">
              <label class="form-label" for="task-related-type">Relacionado con</label>
              <select class="form-control" id="task-related-type" name="related_type" required <?= $can_edit ? '' : 'disabled' ?>>
                <?php foreach ($related_types as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $task['related_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--space-3);">
          <div class="task-detail-item">
            <div class="task-detail-label">Creado por</div>
            <div class="task-detail-value"><?= e($creator_name !== '' ? $creator_name : ($task['created_email'] ?? '')) ?></div>
          </div>
          <div class="task-detail-item">
            <div class="task-detail-label">Creada</div>
            <div class="task-detail-value"><?= e($task['created_at']) ?></div>
          </div>
          <div class="task-detail-item">
            <div class="task-detail-label">Actualizada</div>
            <div class="task-detail-value"><?= e($task['updated_at']) ?></div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($can_edit): ?>
      <div class="form-actions">
        <button class="btn" type="submit">Guardar cambios</button>
        <a class="btn btn-ghost" href="<?= e($return_to) ?>">Cancelar</a>
      </div>
    <?php endif; ?>
  </form>
</main>

</body>
</html>
