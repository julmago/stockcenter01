<?php
require_once __DIR__ . '/tasks_lib.php';
require_login();

$pdo = db();
$category_options = task_categories();
$category_labels = task_categories(null, true);
$statuses = task_statuses();
$priorities = task_priorities();
$related_types = task_related_types(null, true);
$users = task_users($pdo);
$current_user_id = (int)(current_user()['id'] ?? 0);
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
$mine = get('mine') === '1';
$mine_filter = get('mine_filter');
$message = get('message');
$success_message = $message === 'updated' ? 'Tarea actualizada.' : '';

$allowed_mine_filters = ['all_active', 'pending', 'in_progress', 'all'];
if (!$mine) {
  $mine_filter = '';
} elseif (!in_array($mine_filter, $allowed_mine_filters, true)) {
  $mine_filter = 'all_active';
}

$where = [];
$params = [];
if ($category !== '' && array_key_exists($category, $category_options)) {
  $where[] = 't.category = ?';
  $params[] = $category;
}
if ($status !== '' && array_key_exists($status, $statuses)) {
  $where[] = 't.status = ?';
  $params[] = $status;
}
if ($mine) {
  $where[] = 'EXISTS (SELECT 1 FROM task_assignees ta_mine WHERE ta_mine.task_id = t.id AND ta_mine.user_id = ?)';
  $params[] = $current_user_id;
  if ($mine_filter === 'all_active') {
    $where[] = "t.status <> 'completed'";
  } elseif ($mine_filter === 'pending') {
    $where[] = "t.status = 'pending'";
  } elseif ($mine_filter === 'in_progress') {
    $where[] = "t.status = 'in_progress'";
  }
}
if (!$mine && $assignee > 0) {
  $where[] = 'EXISTS (SELECT 1 FROM task_assignees ta2 WHERE ta2.task_id = t.id AND ta2.user_id = ?)';
  $params[] = $assignee;
}

$mine_subtitles = [
  'all_active' => 'Tus tareas activas (sin completadas).',
  'pending' => 'Tus tareas pendientes.',
  'in_progress' => 'Tus tareas en progreso.',
  'all' => 'Todas tus tareas (incluye completadas).',
];
$page_title = $mine ? 'Mis tareas' : 'Todas las tareas';
$header_title = $mine ? 'Mis tareas' : 'Tareas';
$header_subtitle = $mine ? ($mine_subtitles[$mine_filter] ?? $mine_subtitles['all_active']) : 'Transparencia total del trabajo del equipo.';
$card_title = $mine ? 'Mis tareas' : 'Vista general';
$card_subtitle = $mine ? ($mine_subtitles[$mine_filter] ?? $mine_subtitles['all_active']) : 'Todas las tareas (solo lectura, salvo tus propias tareas).';

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$st = $pdo->prepare("
  SELECT t.*,
         GROUP_CONCAT(
           DISTINCT COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email)
           ORDER BY u.first_name, u.last_name
           SEPARATOR '||'
         ) AS assignee_names,
         COUNT(DISTINCT ta.user_id) AS assignee_count,
         cu.first_name AS created_first_name, cu.last_name AS created_last_name,
         cu.email AS created_email
  FROM tasks t
  LEFT JOIN task_assignees ta ON ta.task_id = t.id
  LEFT JOIN users u ON u.id = ta.user_id
  JOIN users cu ON cu.id = t.created_by_user_id
  {$where_sql}
  GROUP BY t.id
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
  <title><?= e($page_title) ?></title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
    <div class="page-header">
      <div>
        <h2 class="page-title"><?= e($header_title) ?></h2>
        <span class="muted"><?= e($header_subtitle) ?></span>
      </div>
      <div class="inline-actions">
        <a class="btn" href="task_new.php">+ Crear tarea</a>
      </div>
    </div>

    <?php if ($success_message): ?>
      <div class="alert alert-success"><?= e($success_message) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title"><?= e($card_title) ?></h3>
          <span class="muted small"><?= e($card_subtitle) ?></span>
        </div>
        <div class="inline-actions">
          <?php
          $base_params = array_filter([
            'category' => $category !== '' ? $category : null,
            'status' => $status !== '' ? $status : null,
            'assignee' => (!$mine && $assignee > 0) ? $assignee : null,
          ], static fn($value) => $value !== null);
          $mine_views = [
            'all_active' => 'Mis tareas',
            'pending' => 'Mis tareas pendientes',
            'in_progress' => 'Mis tareas en progreso',
            'all' => 'Todas mis tareas',
          ];
          ?>
          <?php $all_tasks_link = 'tasks_all.php' . ($base_params ? '?' . http_build_query($base_params) : ''); ?>
          <a class="btn <?= $mine ? 'btn-ghost' : '' ?>" href="<?= e($all_tasks_link) ?>">Todas las tareas</a>
          <?php foreach ($mine_views as $key => $label): ?>
            <?php
            $mine_link = 'tasks_all.php?' . http_build_query(array_merge($base_params, [
              'mine' => 1,
              'mine_filter' => $key,
            ]));
            ?>
            <a class="btn <?= $mine && $mine_filter === $key ? '' : 'btn-ghost' ?>" href="<?= e($mine_link) ?>">
              <?= e($label) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <form method="get" action="tasks_all.php" class="stack">
        <?php if ($mine): ?>
          <input type="hidden" name="mine" value="1">
          <input type="hidden" name="mine_filter" value="<?= e($mine_filter) ?>">
        <?php endif; ?>
        <div class="filters-grid">
          <label class="form-field">
            <span class="form-label">Categoría</span>
            <select class="form-control" name="category">
              <option value="">Todas</option>
              <?php foreach ($category_options as $key => $label): ?>
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
          <?php if (!$mine): ?>
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
          <?php endif; ?>
          <div class="filters-actions">
            <button class="btn" type="submit">Filtrar</button>
            <a class="btn btn-ghost" href="<?= e($mine ? ('tasks_all.php?' . http_build_query(['mine' => 1, 'mine_filter' => $mine_filter])) : 'tasks_all.php') ?>">Limpiar</a>
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
              <th class="col-short">Vence</th>
              <th class="col-short">Relación</th>
              <th class="col-short">Asignados</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$tasks): ?>
              <tr>
                <td colspan="7" class="muted">No hay tareas para mostrar.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($tasks as $task): ?>
              <?php
                $related_label = task_label($related_types, (string)$task['related_type']);
                $priority_class = $priority_badges[$task['priority']] ?? 'badge-muted';
                $status_class = $status_badges[$task['status']] ?? 'badge-muted';
                $task_url = 'task_view.php?id=' . (int)$task['id'] . '&from=tasks_all.php';
                $assignee_names = $task['assignee_names'] ? explode('||', (string)$task['assignee_names']) : [];
                $assignee_count = (int)$task['assignee_count'];
                if ($assignee_count <= 0) {
                  $assigned_to = 'Sin asignar';
                } else {
                  $visible_names = array_slice($assignee_names, 0, 2);
                  $assigned_to = implode(', ', $visible_names);
                  $extra = $assignee_count - count($visible_names);
                  if ($extra > 0) {
                    $assigned_to .= ' +' . $extra;
                  }
                }
              ?>
              <tr>
                <td class="col-task">
                  <div class="task-title">
                    <a class="task-title-link" href="<?= e($task_url) ?>"><?= e($task['title']) ?></a>
                  </div>
                  <?php if (!empty($task['description'])): ?>
                    <div class="muted small task-desc"><?= e($task['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="col-short">
                  <span class="badge badge-muted"><?= e(task_label($category_labels, (string)$task['category'])) ?></span>
                </td>
                <td class="col-short">
                  <span class="badge <?= e($priority_class) ?>"><?= e(task_label($priorities, (string)$task['priority'])) ?></span>
                </td>
                <td class="col-short">
                  <span class="badge <?= e($status_class) ?>"><?= e(task_label($statuses, (string)$task['status'])) ?></span>
                </td>
                <td class="col-short"><?= $task['due_date'] ? e($task['due_date']) : '<span class="muted">-</span>' ?></td>
                <td class="col-short">
                  <span class="badge badge-muted"><?= e($related_label) ?></span>
                </td>
                <td class="col-short"><?= e($assigned_to) ?></td>
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
            $related_label = task_label($related_types, (string)$task['related_type']);
            $priority_class = $priority_badges[$task['priority']] ?? 'badge-muted';
            $status_class = $status_badges[$task['status']] ?? 'badge-muted';
            $task_url = 'task_view.php?id=' . (int)$task['id'] . '&from=tasks_all.php';
            $assignee_names = $task['assignee_names'] ? explode('||', (string)$task['assignee_names']) : [];
            $assignee_count = (int)$task['assignee_count'];
            if ($assignee_count <= 0) {
              $assigned_to = 'Sin asignar';
            } else {
              $visible_names = array_slice($assignee_names, 0, 2);
              $assigned_to = implode(', ', $visible_names);
              $extra = $assignee_count - count($visible_names);
              if ($extra > 0) {
                $assigned_to .= ' +' . $extra;
              }
            }
          ?>
          <article class="task-card">
            <div>
              <div class="task-title">
                <a class="task-title-link" href="<?= e($task_url) ?>"><?= e($task['title']) ?></a>
              </div>
              <?php if (!empty($task['description'])): ?>
                <div class="muted small task-desc"><?= e($task['description']) ?></div>
              <?php endif; ?>
            </div>
            <div class="task-card__meta">
              <div class="task-card__meta-row">
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Categoría</span>
                  <span class="badge badge-muted"><?= e(task_label($category_labels, (string)$task['category'])) ?></span>
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
                  <span class="task-card__meta-label">Vence</span>
                  <span><?= $task['due_date'] ? e($task['due_date']) : '-' ?></span>
                </div>
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Relación</span>
                  <span class="badge badge-muted"><?= e($related_label) ?></span>
                </div>
                <div class="task-card__meta-item">
                  <span class="task-card__meta-label">Asignados</span>
                  <span><?= e($assigned_to) ?></span>
                </div>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
</main>

</body>
</html>
