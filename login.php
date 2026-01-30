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
      $st = db()->prepare("SELECT id, role, first_name, last_name, email, password_plain, is_active FROM users WHERE email = ? LIMIT 1");
      $st->execute([$email]);
      $u = $st->fetch();
      if (!$u || (int)$u['is_active'] !== 1) {
        $error = 'Usuario no encontrado o inactivo.';
      } elseif ($u['password_plain'] !== $pass) {
        $error = 'Contraseña incorrecta.';
      } else {
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
      $error = 'Ocurrió un error interno. Intentá nuevamente más tarde.';
    }
  }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Login</title></head>
<body>
  <h2>Login</h2>
  <?php if ($error): ?>
    <p style="color:red;"><?= e($error) ?></p>
  <?php endif; ?>
  <form method="post">
    <div>
      <label>Mail</label><br>
      <input type="email" name="email" value="<?= e(post('email')) ?>" required>
    </div>
    <div style="margin-top:8px;">
      <label>Contraseña</label><br>
      <input type="text" name="password" required>
    </div>
    <div style="margin-top:12px;">
      <button type="submit">Ingresar</button>
    </div>
  </form>
  <p style="margin-top:14px;">
    <small>Los usuarios se crean por base de datos (tabla <code>users</code>).</small>
  </p>
</body>
</html>
