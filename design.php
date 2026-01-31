<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$themes = theme_catalog();
$current_theme = current_theme();
$message = '';
$error = '';

if (is_post() && post('action') === 'apply') {
  $selected = post('theme');
  if (!isset($themes[$selected])) {
    $error = 'Tema inválido.';
  } else {
    $u = current_user();
    $st = db()->prepare("UPDATE users SET theme = ? WHERE id = ?");
    $st->execute([$selected, (int)$u['id']]);
    $_SESSION['user']['theme'] = $selected;
    $current_theme = $selected;
    $message = 'Tema actualizado.';
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Diseño</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/_header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Plantillas de diseño</h2>
      <span class="muted">Elegí una plantilla para cambiar el look del sistema.</span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <div class="grid grid-3">
      <?php foreach ($themes as $key => $theme): ?>
        <div class="card">
          <div class="card-header">
            <h3 class="card-title"><?= e($theme['name']) ?></h3>
            <?php if ($current_theme === $key): ?>
              <span class="badge badge-success">Activo</span>
            <?php endif; ?>
          </div>
          <p class="muted"><?= e($theme['description']) ?></p>
          <div class="theme-preview" data-preview="<?= e($key) ?>">
            Vista previa: <?= e($theme['name']) ?>
          </div>
          <div class="form-actions">
            <button class="btn btn-secondary" type="button" data-preview-btn="<?= e($key) ?>">Preview</button>
            <form method="post" style="margin:0;">
              <input type="hidden" name="action" value="apply">
              <input type="hidden" name="theme" value="<?= e($key) ?>">
              <button class="btn" type="submit">Aplicar</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card" style="margin-top: var(--space-4);">
      <div class="card-header">
        <h3 class="card-title">Preview en vivo</h3>
      </div>
      <p class="muted">Podés previsualizar sin guardar. El botón “Aplicar” guarda tu preferencia.</p>
      <div class="inline-actions">
        <span class="badge badge-muted" id="theme-preview-status">Tema activo: <?= e($themes[$current_theme]['name']) ?></span>
        <button class="btn btn-ghost" type="button" id="theme-preview-reset">Restaurar tema activo</button>
      </div>
    </div>
  </div>
</main>

<script>
  (function() {
    const themeLink = document.getElementById('theme-stylesheet');
    const previewButtons = document.querySelectorAll('[data-preview-btn]');
    const previewStatus = document.getElementById('theme-preview-status');
    const resetButton = document.getElementById('theme-preview-reset');
    if (!themeLink || !previewButtons.length) return;

    const currentTheme = '<?= e($current_theme) ?>';
    const themes = <?= json_encode($themes) ?>;

    function setTheme(themeKey, isPreview) {
      themeLink.setAttribute('href', `/assets/themes/${themeKey}.css`);
      if (previewStatus && themes[themeKey]) {
        previewStatus.textContent = `${isPreview ? 'Preview' : 'Tema activo'}: ${themes[themeKey].name}`;
      }
    }

    previewButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const themeKey = btn.getAttribute('data-preview-btn');
        if (themes[themeKey]) {
          setTheme(themeKey, true);
        }
      });
    });

    if (resetButton) {
      resetButton.addEventListener('click', () => setTheme(currentTheme, false));
    }
  })();
</script>
</body>
</html>
