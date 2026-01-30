<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/settings.php';
require_login();

$error = '';
$message = '';

if (is_post()) {
  $url = trim(post('prestashop_url'));
  $key = trim(post('prestashop_api_key'));
  $mode = trim(post('prestashop_mode','replace'));
  if (!in_array($mode, ['replace','add'], true)) $mode = 'replace';

  // Normalización simple
  $url = rtrim($url, "/");

  setting_set('prestashop_url', $url);
  setting_set('prestashop_api_key', $key);
  setting_set('prestashop_mode', $mode);

  $message = 'Configuración guardada.';
}

$prestashop_url = setting_get('prestashop_url','');
$prestashop_api_key = setting_get('prestashop_api_key','');
$prestashop_mode = setting_get('prestashop_mode','replace');
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Config PrestaShop</title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>

<div style="padding:10px;">
  <h2>Config PrestaShop</h2>

  <?php if ($message): ?><p style="color:green;"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p style="color:red;"><?= e($error) ?></p><?php endif; ?>

  <form method="post">
    <div>
      <label>URL base (sin / final)</label><br>
      <input type="text" name="prestashop_url" value="<?= e($prestashop_url) ?>" placeholder="https://mitienda.com" style="width:520px;">
    </div>

    <div style="margin-top:8px;">
      <label>API Key (Webservice)</label><br>
      <input type="text" name="prestashop_api_key" value="<?= e($prestashop_api_key) ?>" style="width:520px;">
      <div><small>Se usa por Basic Auth (API Key como usuario, contraseña vacía).</small></div>
    </div>

    <div style="margin-top:10px;">
      <label>Modo de sincronización</label><br>
      <select name="prestashop_mode">
        <option value="replace" <?= $prestashop_mode==='replace'?'selected':'' ?>>Reemplazar (= qty del listado)</option>
        <option value="add" <?= $prestashop_mode==='add'?'selected':'' ?>>Sumar (+ qty del listado)</option>
      </select>
    </div>

    <div style="margin-top:12px;">
      <button type="submit">Guardar</button>
      <a href="dashboard.php">Volver</a>
    </div>
  </form>

  <hr>
  <h3>Permisos que necesita la API Key</h3>
  <ul>
    <li><strong>GET</strong> sobre <code>products</code>, <code>combinations</code>, <code>stock_availables</code></li>
    <li><strong>PUT</strong> sobre <code>stock_availables</code></li>
  </ul>
</div>

</body>
</html>
