<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/prestashop.php';
require_login();

$error = '';
$message = '';
$test_error = '';
$test_result = null;
$test_sku = '';

if (is_post() && post('action') === 'save') {
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

if (is_post() && post('action') === 'test') {
  $test_sku = trim(post('prestashop_test_sku'));
  if ($test_sku === '') {
    $test_error = 'Ingresá un SKU para probar.';
  } else {
    try {
      $match = ps_find_by_reference($test_sku);
      if ($match) {
        $test_result = [
          'found' => true,
          'type' => $match['type'],
          'id_product' => $match['id_product'],
          'id_product_attribute' => $match['id_product_attribute'],
        ];
      } else {
        $test_result = ['found' => false];
      }
    } catch (Throwable $e) {
      $test_error = $e->getMessage();
    }
  }
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
    <input type="hidden" name="action" value="save">
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
  <h3>Probar conexión / Probar SKU</h3>
  <p><small>Se usa la misma búsqueda que la sincronización. Revisá los logs del servidor para ver URL, status, content-type y el inicio de la respuesta.</small></p>
  <?php if ($test_error): ?><p style="color:red;"><?= e($test_error) ?></p><?php endif; ?>
  <?php if (is_array($test_result)): ?>
    <?php if ($test_result['found']): ?>
      <p style="color:green;">
        Encontrado (<?= e($test_result['type']) ?>):
        product_id=<?= (int)$test_result['id_product'] ?>,
        combo_id=<?= (int)$test_result['id_product_attribute'] ?>
      </p>
    <?php else: ?>
      <p style="color:red;">No encontrado.</p>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post" style="margin-top:8px;">
    <input type="hidden" name="action" value="test">
    <label>SKU</label><br>
    <input type="text" name="prestashop_test_sku" value="<?= e($test_sku) ?>" placeholder="MS-06" style="width:220px;">
    <button type="submit" style="margin-left:6px;">Probar</button>
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
