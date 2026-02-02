<?php
require_once __DIR__ . '/tasks_lib.php';
require_login();

$pdo = db();
$current_user = current_user();
$current_user_id = (int)($current_user['id'] ?? 0);

$errors = [];
$title = '';
$description = '';
$category = '';
$priority = 'medium';
$assigned_user_id = '';
$due_date = '';
$related_type = '';

$categories = task_categories($category);
$category_map = task_categories(null, true);
$statuses = task_statuses();
$priorities = task_priorities();
$related_types = task_related_types($related_type);
$related_types_map = task_related_types(null, true);
$users = task_users($pdo);

if (is_post()) {
  $title = post('title');
  $description = post('description');
  $category = post('category', $category);
  $priority = post('priority', $priority);
  $assigned_user_id = post('assigned_user_id');
  $due_date = post('due_date');
  $related_type = post('related_type', $related_type);
  $categories = task_categories($category);
  $related_types = task_related_types($related_type);

  if ($title === '') {
    $errors[] = 'El título es obligatorio.';
  }
  if ($category === '') {
    $errors[] = 'Seleccioná una categoría.';
  } elseif (!array_key_exists($category, $category_map)) {
    $errors[] = 'La categoría es inválida.';
  }
  if (!array_key_exists($priority, $priorities)) {
    $errors[] = 'La prioridad es inválida.';
  }
  if ($related_type === '') {
    $errors[] = 'Seleccioná un tipo relacionado.';
  } elseif (!array_key_exists($related_type, $related_types_map)) {
    $errors[] = 'El tipo relacionado es inválido.';
  }

  $assigned_id = (int)$assigned_user_id;
  if ($assigned_id <= 0) {
    $errors[] = 'Seleccioná un usuario asignado.';
  } else {
    $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
    $check->execute([$assigned_id]);
    if (!$check->fetch()) {
      $errors[] = 'El usuario asignado es inválido.';
    }
  }

  if (!$errors) {
    $due_date_value = $due_date !== '' ? $due_date : null;
    $st = $pdo->prepare("INSERT INTO tasks (title, description, category, priority, status, assigned_user_id, created_by_user_id, due_date, related_type)
      VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?)");
    $st->execute([
      $title,
      $description !== '' ? $description : null,
      $category,
      $priority,
      $assigned_id,
      $current_user_id,
      $due_date_value,
      $related_type,
    ]);
    redirect('tasks_all.php');
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Crear tarea</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <div>
        <h2 class="page-title">Crear tarea</h2>
        <span class="muted">Asigná tareas de forma simple y transparente.</span>
      </div>
      <div class="inline-actions">
        <a class="btn btn-ghost" href="tasks_all.php">Volver a tareas</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Nueva tarea</h3>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-warning">
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" class="stack">
        <label class="form-field">
          <span class="form-label">Título *</span>
          <input class="form-control" type="text" name="title" value="<?= e($title) ?>" required>
        </label>

        <label class="form-field">
          <span class="form-label">Descripción</span>
          <textarea class="form-control" name="description" rows="4"><?= e($description) ?></textarea>
        </label>

        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
          <label class="form-field">
            <span class="form-label">Categoría</span>
            <select class="form-control" name="category" required>
              <option value="" selected disabled>Seleccionar categoría</option>
              <?php foreach ($categories as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-field">
            <span class="form-label">Asignar a</span>
            <select class="form-control" name="assigned_user_id" required>
              <option value="">Seleccionar usuario</option>
              <?php foreach ($users as $user): ?>
                <?php $user_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
                <option value="<?= (int)$user['id'] ?>" <?= (string)$assigned_user_id === (string)$user['id'] ? 'selected' : '' ?>>
                  <?= e($user_name !== '' ? $user_name : $user['email']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-field">
            <span class="form-label">Prioridad</span>
            <select class="form-control" name="priority">
              <?php foreach ($priorities as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $priority === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="form-field">
            <span class="form-label">Fecha límite</span>
            <input class="form-control" type="date" name="due_date" value="<?= e($due_date) ?>">
          </label>
        </div>

        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
          <label class="form-field">
            <span class="form-label">Relacionado con</span>
            <select class="form-control" name="related_type" required>
              <option value="" selected disabled>Seleccionar relación</option>
              <?php foreach ($related_types as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $related_type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="inline-actions">
          <button class="btn" type="submit">Crear tarea</button>
          <a class="btn btn-ghost" href="tasks_all.php">Cancelar</a>
        </div>
      </form>
    </div>
  </div>
</main>

</body>
</html>
