<?php
require_once __DIR__ . '/tasks_lib.php';
require_login();

$pdo = db();
$current_user = current_user();
$current_user_id = (int)($current_user['id'] ?? 0);

task_handle_action($pdo, $current_user_id, 'tasks_my.php');

$categories = task_categories();
$statuses = task_statuses();
$priorities = task_priorities();
$related_types = task_related_types();
$users = task_users($pdo);

$category = get('category');
$status = get('status');

$where = ['t.assigned_user_id = ?'];
$params = [$current_user_id];
if ($category !== '' && array_key_exists($category, $categories)) {
  $where[] = 't.category = ?';
  $params[] = $category;
}
if ($status !== '' && array_key_exists($status, $statuses)) {
  $where[] = 't.status = ?';
  $params[] = $status;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);
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
  <title>Mis tareas</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <div>
        <h2 class="page-title">Mis tareas</h2>
        <span class="muted">Gestioná el estado y las asignaciones de tus tareas.</span>
      </div>
      <div class="inline-actions">
        <a class="btn" href="task_new.php">+ Crear tarea</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Tareas asignadas a mí</h3>
        </div>
        <div class="inline-actions">
          <a class="btn btn-ghost" href="tasks_all.php">Todas las tareas</a>
          <a class="btn btn-ghost" href="tasks_my.php">Mis tareas</a>
        </div>
      </div>

      <form method="get" action="tasks_my.php" class="stack">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
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
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Filtrar</button>
          <a class="btn btn-ghost" href="tasks_my.php">Limpiar</a>
        </div>
      </form>

      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Tarea</th>
              <th>Categoría</th>
              <th>Prioridad</th>
              <th>Estado</th>
              <th>Asignado a</th>
              <th>Fecha límite</th>
              <th>Relacionado</th>
              <th>Creado por</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$tasks): ?>
              <tr>
                <td colspan="9" class="muted">No tenés tareas asignadas.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($tasks as $task): ?>
              <?php
                $assigned_name = trim(($task['assigned_first_name'] ?? '') . ' ' . ($task['assigned_last_name'] ?? ''));
                $creator_name = trim(($task['created_first_name'] ?? '') . ' ' . ($task['created_last_name'] ?? ''));
                $related_label = task_label($related_types, (string)$task['related_type']);
                if (!empty($task['related_id'])) {
                  $related_label .= ' #' . (int)$task['related_id'];
                }
              ?>
              <tr>
                <td>
                  <strong><?= e($task['title']) ?></strong>
                  <?php if (!empty($task['description'])): ?>
                    <div class="muted small"><?= e($task['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= e(task_label($categories, (string)$task['category'])) ?></td>
                <td><?= e(task_label($priorities, (string)$task['priority'])) ?></td>
                <td><?= e(task_label($statuses, (string)$task['status'])) ?></td>
                <td><?= e($assigned_name !== '' ? $assigned_name : ($task['assigned_email'] ?? '')) ?></td>
                <td><?= $task['due_date'] ? e($task['due_date']) : '<span class="muted">-</span>' ?></td>
                <td><?= e($related_label) ?></td>
                <td><?= e($creator_name !== '' ? $creator_name : ($task['created_email'] ?? '')) ?></td>
                <td>
                  <form method="post" class="stack" style="gap:8px;">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                    <select class="form-control" name="status">
                      <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $task['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-ghost" type="submit">Cambiar estado</button>
                  </form>
                  <form method="post" class="stack" style="gap:8px; margin-top:8px;">
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
                    <button class="btn btn-ghost" type="submit">Reasignar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

</body>
</html>
