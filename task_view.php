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

$categories = task_categories();
$statuses = task_statuses();
$priorities = task_priorities();
$related_types = task_related_types();

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

$assigned_name = trim(($task['assigned_first_name'] ?? '') . ' ' . ($task['assigned_last_name'] ?? ''));
$creator_name = trim(($task['created_first_name'] ?? '') . ' ' . ($task['created_last_name'] ?? ''));
$related_label = task_label($related_types, (string)$task['related_type']);
if (!empty($task['related_id'])) {
  $related_label .= ' #' . (int)$task['related_id'];
}
$priority_label = task_label($priorities, (string)$task['priority']);
$status_label = task_label($statuses, (string)$task['status']);
$category_label = task_label($categories, (string)$task['category']);

$error = '';
$can_edit = (int)$task['assigned_user_id'] === $current_user_id;

if (is_post() && post('action') === 'update') {
  if (!$can_edit) {
    abort(403, 'No tenés permisos para editar esta tarea.');
  }
  $title = post('title');
  $description = post('description');
  if ($title === '') {
    $error = 'El título es obligatorio.';
  } else {
    $description = $description === '' ? null : $description;
    $st = $pdo->prepare("UPDATE tasks SET title = ?, description = ?, updated_at = NOW() WHERE id = ?");
    $st->execute([$title, $description, $task_id]);
    $separator = strpos($return_to, '?') !== false ? '&' : '?';
    redirect($return_to . $separator . 'message=updated');
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
      <span class="muted">ID #<?= (int)$task['id'] ?></span>
    </div>
    <div class="inline-actions">
      <a class="btn btn-ghost" href="<?= e($return_to) ?>">Volver a <?= e($return_label) ?></a>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Título y descripción</h3>
        <span class="muted small">Editá la información principal de la tarea.</span>
      </div>
    </div>
    <?php if ($can_edit): ?>
      <form method="post" class="stack">
        <input type="hidden" name="action" value="update">
        <div class="form-group">
          <label class="form-label" for="task-title">Título</label>
          <input class="form-control" id="task-title" type="text" name="title" value="<?= e($task['title']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="task-description">Descripción</label>
          <textarea class="form-control" id="task-description" name="description" rows="6"><?= e((string)($task['description'] ?? '')) ?></textarea>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit">Guardar cambios</button>
          <a class="btn btn-ghost" href="<?= e($return_to) ?>">Cancelar</a>
        </div>
      </form>
    <?php else: ?>
      <div class="stack">
        <div>
          <div class="task-detail-label">Título</div>
          <div class="task-detail-value"><?= e($task['title']) ?></div>
        </div>
        <div>
          <div class="task-detail-label">Descripción</div>
          <div class="task-detail-value">
            <?= $task['description'] ? e($task['description']) : '<span class="muted">Sin descripción.</span>' ?>
          </div>
        </div>
        <div class="alert alert-warning">
          Solo lectura: la edición está disponible para la persona asignada.
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header">
      <div>
        <h3 class="card-title">Detalle completo</h3>
        <span class="muted small">Información de estado, relación y auditoría.</span>
      </div>
    </div>
    <div class="task-detail-grid">
      <div class="task-detail-item">
        <div class="task-detail-label">Categoría</div>
        <div class="task-detail-value"><?= e($category_label) ?></div>
      </div>
      <div class="task-detail-item">
        <div class="task-detail-label">Prioridad</div>
        <div class="task-detail-value"><?= e($priority_label) ?></div>
      </div>
      <div class="task-detail-item">
        <div class="task-detail-label">Estado</div>
        <div class="task-detail-value"><?= e($status_label) ?></div>
      </div>
      <div class="task-detail-item">
        <div class="task-detail-label">Asignado a</div>
        <div class="task-detail-value"><?= e($assigned_name !== '' ? $assigned_name : ($task['assigned_email'] ?? '')) ?></div>
      </div>
      <div class="task-detail-item">
        <div class="task-detail-label">Creado por</div>
        <div class="task-detail-value"><?= e($creator_name !== '' ? $creator_name : ($task['created_email'] ?? '')) ?></div>
      </div>
      <div class="task-detail-item">
        <div class="task-detail-label">Fecha límite</div>
        <div class="task-detail-value"><?= $task['due_date'] ? e($task['due_date']) : '<span class="muted">-</span>' ?></div>
      </div>
      <div class="task-detail-item">
        <div class="task-detail-label">Relacionado con</div>
        <div class="task-detail-value"><?= e($related_label) ?></div>
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
</main>

</body>
</html>
