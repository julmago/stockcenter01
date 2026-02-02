<?php
require_once __DIR__ . '/tasks_lib.php';
require_login();

$pdo = db();
$current_user = current_user();
$current_user_id = (int)($current_user['id'] ?? 0);

task_handle_action($pdo, $current_user_id, 'tasks_all.php');

$categories = task_categories();
$statuses = task_statuses();
$priorities = task_priorities();
$related_types = task_related_types();
$users = task_users($pdo);
$priority_badges = [
  'low' => 'badge-muted',
  'medium' => 'badge-warning',
  'high' => 'badge-danger',
];
$status_badges = [
  'pending' => 'badge-muted',
  'in_progress' => 'badge-warning',
  'completed' => 'badge-success',
];

$category = get('category');
$status = get('status');
$assignee = (int)get('assignee');

$where = [];
$params = [];
if ($category !== '' && array_key_exists($category, $categories)) {
  $where[] = 't.category = ?';
  $params[] = $category;
}
if ($status !== '' && array_key_exists($status, $statuses)) {
  $where[] = 't.status = ?';
  $params[] = $status;
}
if ($assignee > 0) {
  $where[] = 't.assigned_user_id = ?';
  $params[] = $assignee;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$st = $pdo->prepare("
  SELECT t.*, 
         au.first_name AS assigned_first_name, au.last_name AS assigned_last_name,
         au.email AS assigned_email,
         cu.first_name AS created_first_name, cu.last_name AS created_last_name,
         cu.email AS created_email
  FROM tasks t
  JOIN users au ON au.id = t.assigned_user_id
  JOIN users cu ON cu.id = t.created_by_user_id
  {$where_sql}
  ORDER BY t.created_at DESC
  LIMIT 300
");
$st->execute($params);
$tasks = $st->fetchAll();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Todas las tareas</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
    <div class="page-header">
      <div>
        <h2 class="page-title">Tareas</h2>
        <span class="muted">Transparencia total del trabajo del equipo.</span>
      </div>
      <div class="inline-actions">
        <a class="btn" href="task_new.php">+ Crear tarea</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Vista general</h3>
          <span class="muted small">Todas las tareas (solo lectura, salvo tus propias tareas).</span>
        </div>
        <div class="inline-actions">
          <a class="btn btn-ghost" href="tasks_all.php">Todas las tareas</a>
          <a class="btn btn-ghost" href="tasks_my.php">Mis tareas</a>
        </div>
      </div>

      <form method="get" action="tasks_all.php" class="stack">
        <div class="filters-grid">
          <label class="form-field">
            <span class="form-label">Categoría</span>
            <select class="form-control" name="category">
              <option value="">Todas</option>
              <?php foreach ($categories as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="form-field">
            <span class="form-label">Estado</span>
            <select class="form-control" name="status">
              <option value="">Todos</option>
              <?php foreach ($statuses as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="form-field">
            <span class="form-label">Asignado a</span>
            <select class="form-control" name="assignee">
              <option value="">Todos</option>
              <?php foreach ($users as $user): ?>
                <?php $user_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
                <option value="<?= (int)$user['id'] ?>" <?= $assignee === (int)$user['id'] ? 'selected' : '' ?>>
                  <?= e($user_name !== '' ? $user_name : $user['email']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="filters-actions">
            <button class="btn" type="submit">Filtrar</button>
            <a class="btn btn-ghost" href="tasks_all.php">Limpiar</a>
          </div>
        </div>
      </form>

      <div class="table-wrap">
        <table class="table task-table">
          <thead>
            <tr>
              <th class="col-task">Tarea</th>
              <th class="col-short">Categoría</th>
              <th class="col-short">Prioridad</th>
              <th class="col-short">Estado</th>
              <th class="col-short">Asignado a</th>
              <th class="col-short">Vence</th>
              <th class="col-short">Relación</th>
              <th class="col-short">Creada por</th>
              <th class="col-actions">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$tasks): ?>
              <tr>
                <td colspan="9" class="muted">No hay tareas para mostrar.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($tasks as $task): ?>
              <?php
                $assigned_name = trim(($task['assigned_first_name'] ?? '') . ' ' . ($task['assigned_last_name'] ?? ''));
                $creator_name = trim(($task['created_first_name'] ?? '') . ' ' . ($task['created_last_name'] ?? ''));
                $is_owner = (int)$task['assigned_user_id'] === $current_user_id;
                $related_label = task_label($related_types, (string)$task['related_type']);
                if (!empty($task['related_id'])) {
                  $related_label .= ' #' . (int)$task['related_id'];
                }
                $priority_class = $priority_badges[$task['priority']] ?? 'badge-muted';
                $status_class = $status_badges[$task['status']] ?? 'badge-muted';
              ?>
              <tr>
                <td class="col-task">
                  <div class="task-title"><?= e($task['title']) ?></div>
                  <?php if (!empty($task['description'])): ?>
                    <div class="muted small task-desc"><?= e($task['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="col-short">
                  <span class="badge badge-muted"><?= e(task_label($categories, (string)$task['category'])) ?></span>
                </td>
                <td class="col-short">
                  <span class="badge <?= e($priority_class) ?>"><?= e(task_label($priorities, (string)$task['priority'])) ?></span>
                </td>
                <td class="col-short">
                  <span class="badge <?= e($status_class) ?>"><?= e(task_label($statuses, (string)$task['status'])) ?></span>
                </td>
                <td class="col-short"><?= e($assigned_name !== '' ? $assigned_name : ($task['assigned_email'] ?? '')) ?></td>
                <td class="col-short"><?= $task['due_date'] ? e($task['due_date']) : '<span class="muted">-</span>' ?></td>
                <td class="col-short">
                  <span class="badge badge-muted"><?= e($related_label) ?></span>
                </td>
                <td class="col-short"><?= e($creator_name !== '' ? $creator_name : ($task['created_email'] ?? '')) ?></td>
                <td class="col-actions">
                  <?php if ($is_owner): ?>
                    <details class="action-menu">
                      <summary class="btn btn-ghost btn-small">Acciones</summary>
                      <div class="action-menu__panel">
                        <form method="post" class="action-form">
                          <input type="hidden" name="action" value="update_status">
                          <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                          <select class="form-control" name="status">
                            <?php foreach ($statuses as $key => $label): ?>
                              <option value="<?= e($key) ?>" <?= $task['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button class="btn btn-ghost btn-small" type="submit">Guardar estado</button>
                        </form>
                        <form method="post" class="action-form">
                          <input type="hidden" name="action" value="reassign">
                          <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                          <select class="form-control" name="assigned_user_id">
                            <?php foreach ($users as $user): ?>
                              <?php $user_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
                              <option value="<?= (int)$user['id'] ?>" <?= (int)$task['assigned_user_id'] === (int)$user['id'] ? 'selected' : '' ?>>
                                <?= e($user_name !== '' ? $user_name : $user['email']) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <button class="btn btn-ghost btn-small" type="submit">Reasignar</button>
                        </form>
                      </div>
                    </details>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="task-cards">
        <?php if (!$tasks): ?>
          <div class="muted">No hay tareas para mostrar.</div>
        <?php endif; ?>
        <?php foreach ($tasks as $task): ?>
          <?php
            $assigned_name = trim(($task['assigned_first_name'] ?? '') . ' ' . ($task['assigned_last_name'] ?? ''));
            $creator_name = trim(($task['created_first_name'] ?? '') . ' ' . ($task['created_last_name'] ?? ''));
            $is_owner = (int)$task['assigned_user_id'] === $current_user_id;
            $related_label = task_label($related_types, (string)$task['related_type']);
            if (!empty($task['related_id'])) {
              $related_label .= ' #' . (int)$task['related_id'];
            }
            $priority_class = $priority_badges[$task['priority']] ?? 'badge-muted';
            $status_class = $status_badges[$task['status']] ?? 'badge-muted';
          ?>
          <article class="task-card">
            <div>
              <div class="task-title"><?= e($task['title']) ?></div>
              <?php if (!empty($task['description'])): ?>
                <div class="muted small task-desc"><?= e($task['description']) ?></div>
              <?php endif; ?>
            </div>
            <div class="task-card__meta">
              <div class="task-card__meta-row">
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Categoría</span>
                  <span class="badge badge-muted"><?= e(task_label($categories, (string)$task['category'])) ?></span>
                </div>
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Prioridad</span>
                  <span class="badge <?= e($priority_class) ?>"><?= e(task_label($priorities, (string)$task['priority'])) ?></span>
                </div>
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Estado</span>
                  <span class="badge <?= e($status_class) ?>"><?= e(task_label($statuses, (string)$task['status'])) ?></span>
                </div>
              </div>
              <div class="task-card__meta-row">
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Asignado</span>
                  <span><?= e($assigned_name !== '' ? $assigned_name : ($task['assigned_email'] ?? '')) ?></span>
                </div>
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Vence</span>
                  <span><?= $task['due_date'] ? e($task['due_date']) : '-' ?></span>
                </div>
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Relación</span>
                  <span class="badge badge-muted"><?= e($related_label) ?></span>
                </div>
              </div>
              <div class="task-card__meta-row">
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Creada por</span>
                  <span><?= e($creator_name !== '' ? $creator_name : ($task['created_email'] ?? '')) ?></span>
                </div>
              </div>
            </div>
            <div class="task-actions">
              <?php if ($is_owner): ?>
                <details class="action-menu">
                  <summary class="btn btn-ghost btn-small">Acciones</summary>
                  <div class="action-menu__panel">
                    <form method="post" class="action-form">
                      <input type="hidden" name="action" value="update_status">
                      <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                      <select class="form-control" name="status">
                        <?php foreach ($statuses as $key => $label): ?>
                          <option value="<?= e($key) ?>" <?= $task['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-ghost btn-small" type="submit">Guardar estado</button>
                    </form>
                    <form method="post" class="action-form">
                      <input type="hidden" name="action" value="reassign">
                      <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                      <select class="form-control" name="assigned_user_id">
                        <?php foreach ($users as $user): ?>
                          <?php $user_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
                          <option value="<?= (int)$user['id'] ?>" <?= (int)$task['assigned_user_id'] === (int)$user['id'] ? 'selected' : '' ?>>
                            <?= e($user_name !== '' ? $user_name : $user['email']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-ghost btn-small" type="submit">Reasignar</button>
                    </form>
                  </div>
                </details>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
</main>

</body>
</html>
