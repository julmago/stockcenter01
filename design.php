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
    <div class="page-header theme-page-header">
      <h2 class="page-title">Plantillas de diseño</h2>
      <span class="muted">Elegí una plantilla para cambiar el look del sistema.</span>
    </div>

    <?php if ($message || $error): ?>
      <div class="theme-alerts">
        <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="themes-grid">
      <?php foreach ($themes as $key => $theme): ?>
        <div class="card theme-card">
          <div class="theme-card__header">
            <h3 class="card-title"><?= e($theme['name']) ?></h3>
            <span class="badge badge-success theme-card__badge <?= $current_theme === $key ? '' : 'theme-card__badge--hidden' ?>">Activo</span>
          </div>
          <p class="muted theme-card__description"><?= e($theme['description']) ?></p>
          <div class="theme-card__spacer"></div>
          <div class="form-actions theme-card__actions">
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

    <div class="card theme-preview-card">
      <p class="muted">Preview en vivo: probá el tema sin guardar. El botón “Aplicar” confirma tu preferencia.</p>
      <div class="theme-preview-card__actions">
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

    const basePath = '<?= e(BASE_PATH) ?>';
    const currentTheme = '<?= e($current_theme) ?>';
    const themes = <?= json_encode($themes) ?>;

    function setTheme(themeKey, isPreview) {
      themeLink.setAttribute('href', `${basePath}/assets/themes/${themeKey}.css`);
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
