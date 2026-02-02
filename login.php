<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!empty($_SESSION['user'])) {
  redirect('dashboard.php');
}

$error = '';
if (is_post()) {
  $email = post('email');
  $pass  = post('password');
  if ($email === '' || $pass === '') {
    $error = 'Completá email y contraseña.';
  } else {
    try {
      error_log(sprintf('[%s] Login attempt for %s', date('c'), $email));
      $st = db()->prepare(
        "SELECT id, role, first_name, last_name, email, password_plain, is_active, theme
         FROM users
         WHERE email = ? AND is_active = 1
         LIMIT 1"
      );
      $st->execute([$email]);
      $u = $st->fetch();
      if ($u === false) {
        error_log(sprintf('[%s] Login user not found or inactive for %s', date('c'), $email));
        $error = 'Usuario no encontrado o inactivo.';
      } elseif ($u['password_plain'] !== $pass) {
        error_log(sprintf('[%s] Login password mismatch for %s', date('c'), $email));
        $error = 'Contraseña incorrecta.';
      } else {
        error_log(sprintf('[%s] Login success for %s', date('c'), $email));
        session_regenerate_id(true);
        unset($u['password_plain']);
        $_SESSION['user'] = $u;
        redirect('dashboard.php');
      }
    } catch (Throwable $e) {
      error_log(sprintf(
        '[%s] Login error for %s: %s in %s:%d',
        date('c'),
        $email,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
      ));
      if (!empty($debug)) {
        $error = sprintf(
          'Error interno: %s (%s:%d)',
          $e->getMessage(),
          $e->getFile(),
          $e->getLine()
        );
      } else {
        $error = 'Ocurrió un error interno. Intentá nuevamente más tarde.';
      }
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
  <main class="page">
    <div class="container">
      <div class="card login-card">
        <div class="card-header">
          <h2 class="card-title">Ingreso</h2>
          <span class="muted small">Entrada de Stock</span>
        </div>
        <?php if ($error): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="stack">
          <div class="form-group">
            <label class="form-label">Mail</label>
            <input class="form-control" type="email" name="email" value="<?= e(post('email')) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Contraseña</label>
            <input class="form-control" type="text" name="password" required>
          </div>
          <div class="form-actions">
            <button class="btn" type="submit">Ingresar</button>
          </div>
        </form>
        <p class="muted small">
          Los usuarios se crean por base de datos (tabla <span class="code">users</span>).
        </p>
      </div>
    </div>
  </main>
</body>
</html>
