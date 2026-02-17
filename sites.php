<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
ensure_sites_schema();

$pdo = db();
$q = trim(get('q', ''));
$page = max(1, (int)get('page', '1'));
$limit = 25;
$offset = ($page - 1) * $limit;

$error = '';
$message = '';

function normalize_channel_type($value): string {
  $channel = strtoupper(trim((string)$value));
  if (!in_array($channel, ['NONE', 'PRESTASHOP', 'MERCADOLIBRE'], true)) {
    return 'NONE';
  }
  return $channel;
}

function ml_http_build_query(array $params): string {
  return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function ml_auth_base_url(): string {
  return 'https://auth.mercadolibre.com.ar/authorization';
}

function ml_token_url(): string {
  return 'https://api.mercadolibre.com/oauth/token';
}

function ml_post_form(string $url, array $data): array {
  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('No se pudo inicializar cURL.');
  }

  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, ml_http_build_query($data));
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) {
    throw new RuntimeException('Error cURL: ' . $err);
  }

  $json = json_decode((string)$resp, true);
  if (!is_array($json)) {
    $json = [];
  }

  return ['code' => $code, 'json' => $json, 'raw' => (string)$resp];
}


$actionGet = trim((string)get('action', ''));
if ($actionGet === 'ml_connect') {
  $siteId = (int)get('site_id', '0');
  if ($siteId <= 0) {
    header('Location: sites.php?error=1');
    exit;
  }

  $st = $pdo->prepare('SELECT s.id, sc.ml_client_id, sc.ml_client_secret, sc.ml_redirect_uri FROM sites s LEFT JOIN site_connections sc ON sc.site_id = s.id WHERE s.id = ? LIMIT 1');
  $st->execute([$siteId]);
  $siteCfg = $st->fetch();
  if (!$siteCfg) {
    header('Location: sites.php?error=1');
    exit;
  }

  $clientId = trim((string)($siteCfg['ml_client_id'] ?? ''));
  $clientSecret = trim((string)($siteCfg['ml_client_secret'] ?? ''));
  $redirectUri = trim((string)($siteCfg['ml_redirect_uri'] ?? ''));
  if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
    header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=missing_ml_config');
    exit;
  }

  $state = bin2hex(random_bytes(16));
  $_SESSION['ml_oauth_state'] = $state;
  $_SESSION['ml_oauth_site_id'] = $siteId;

  $authUrl = ml_auth_base_url() . '?' . ml_http_build_query([
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'state' => $state,
  ]);

  header('Location: ' . $authUrl);
  exit;
}

if ($actionGet === 'ml_oauth_callback') {
  $siteId = (int)($_SESSION['ml_oauth_site_id'] ?? 0);
  $expectedState = (string)($_SESSION['ml_oauth_state'] ?? '');
  $code = trim((string)get('code', ''));
  $state = trim((string)get('state', ''));

  unset($_SESSION['ml_oauth_site_id'], $_SESSION['ml_oauth_state']);

  if ($siteId <= 0) {
    header('Location: sites.php?oauth_error=session');
    exit;
  }
  if ($state === '' || !hash_equals($expectedState, $state)) {
    header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=state');
    exit;
  }
  if ($code === '') {
    header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=code');
    exit;
  }

  try {
    $st = $pdo->prepare('SELECT ml_client_id, ml_client_secret, ml_redirect_uri FROM site_connections WHERE site_id = ? LIMIT 1');
    $st->execute([$siteId]);
    $cfg = $st->fetch();
    $clientId = trim((string)($cfg['ml_client_id'] ?? ''));
    $clientSecret = trim((string)($cfg['ml_client_secret'] ?? ''));
    $redirectUri = trim((string)($cfg['ml_redirect_uri'] ?? ''));
    if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
      header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=missing_ml_config');
      exit;
    }

    $tokenResponse = ml_post_form(ml_token_url(), [
      'grant_type' => 'authorization_code',
      'client_id' => $clientId,
      'client_secret' => $clientSecret,
      'code' => $code,
      'redirect_uri' => $redirectUri,
    ]);

    $tokenData = $tokenResponse['json'];
    $accessToken = trim((string)($tokenData['access_token'] ?? ''));
    $refreshToken = trim((string)($tokenData['refresh_token'] ?? ''));
    $userId = trim((string)($tokenData['user_id'] ?? ''));

    if ($tokenResponse['code'] < 200 || $tokenResponse['code'] >= 300 || $accessToken === '' || $refreshToken === '') {
      header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=token');
      exit;
    }

    $st = $pdo->prepare('UPDATE site_connections SET ml_access_token = ?, ml_refresh_token = ?, ml_user_id = ?, updated_at = NOW() WHERE site_id = ?');
    $st->execute([
      $accessToken,
      $refreshToken,
      $userId !== '' ? $userId : null,
      $siteId,
    ]);

    header('Location: sites.php?edit_id=' . $siteId . '&oauth_connected=1');
    exit;
  } catch (Throwable $t) {
    header('Location: sites.php?edit_id=' . $siteId . '&oauth_error=exchange');
    exit;
  }
}

if (is_post()) {

  $action = post('action');

  if ($action === 'create_site') {
    $name = trim(post('name'));
    $channelType = normalize_channel_type(post('channel_type', 'NONE'));
    $margin = normalize_site_margin_percent_value(post('margin_percent'));
    $isActive = post('is_active') === '1' ? 1 : 0;
    $showInList = post('is_visible', '1') === '0' ? 0 : 1;
    $showInProduct = post('show_in_product', '1') === '0' ? 0 : 1;
    $connectionEnabled = post('connection_enabled', '0') === '1' ? 1 : 0;
    $psBaseUrl = trim(post('ps_base_url'));
    $psApiKey = trim(post('ps_api_key'));
    $psShopIdRaw = trim(post('ps_shop_id'));
    $psShopId = $psShopIdRaw === '' ? null : (int)$psShopIdRaw;
    $mlClientId = trim(post('ml_client_id'));
    $mlClientSecret = trim(post('ml_client_secret'));
    $mlRedirectUri = trim(post('ml_redirect_uri'));

    if ($channelType === 'NONE') {
      $connectionEnabled = 0;
    }

    if ($name === '') {
      $error = 'Ingresá el nombre del sitio.';
    } elseif (mb_strlen($name) > 80) {
      $error = 'El nombre del sitio no puede superar los 80 caracteres.';
    } elseif ($margin === null) {
      $error = 'Margen (%) inválido. Usá un valor entre -100 y 999.99.';
    } elseif ($channelType === 'MERCADOLIBRE' && $mlRedirectUri === '') {
      $error = 'Para MercadoLibre, la Redirect URI / Callback URL es obligatoria.';
    } else {
      try {
        $st = $pdo->prepare('SELECT id FROM sites WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
        $st->execute([$name]);
        if ($st->fetch()) {
          $error = 'Ese sitio ya existe.';
        } else {
          $st = $pdo->prepare('INSERT INTO sites(name, channel_type, margin_percent, is_active, is_visible, show_in_product, updated_at) VALUES(?, ?, ?, ?, ?, ?, NOW())');
          $st->execute([$name, $channelType, $margin, $isActive, $showInList, $showInProduct]);
          $siteId = (int)$pdo->lastInsertId();
          $st = $pdo->prepare("INSERT INTO site_connections (site_id, channel_type, enabled, ps_base_url, ps_api_key, ps_shop_id, ml_client_id, ml_client_secret, ml_redirect_uri, ml_access_token, ml_refresh_token, ml_user_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NOW())
            ON DUPLICATE KEY UPDATE
              channel_type = VALUES(channel_type),
              enabled = VALUES(enabled),
              ps_base_url = VALUES(ps_base_url),
              ps_api_key = VALUES(ps_api_key),
              ps_shop_id = VALUES(ps_shop_id),
              ml_client_id = VALUES(ml_client_id),
              ml_client_secret = VALUES(ml_client_secret),
              ml_redirect_uri = VALUES(ml_redirect_uri),
              updated_at = NOW(),
              ml_access_token = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_access_token END,
              ml_refresh_token = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_refresh_token END,
              ml_user_id = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_user_id END");
          $st->execute([
            $siteId,
            $channelType,
            $connectionEnabled,
            $psBaseUrl !== '' ? $psBaseUrl : null,
            $psApiKey !== '' ? $psApiKey : null,
            $psShopId,
            $mlClientId !== '' ? $mlClientId : null,
            $mlClientSecret !== '' ? $mlClientSecret : null,
            $mlRedirectUri !== '' ? $mlRedirectUri : null,
          ]);
          header('Location: sites.php?created=1');
          exit;
        }
      } catch (Throwable $t) {
        $error = 'No se pudo crear el sitio.';
      }
    }
  }

  if ($action === 'update_site') {
    $id = (int)post('id', '0');
    $name = trim(post('name'));
    $channelType = normalize_channel_type(post('channel_type', 'NONE'));
    $margin = normalize_site_margin_percent_value(post('margin_percent'));
    $isActive = post('is_active') === '1' ? 1 : 0;
    $showInList = post('is_visible', '1') === '0' ? 0 : 1;
    $showInProduct = post('show_in_product', '1') === '0' ? 0 : 1;
    $connectionEnabled = post('connection_enabled', '0') === '1' ? 1 : 0;
    $psBaseUrl = trim(post('ps_base_url'));
    $psApiKey = trim(post('ps_api_key'));
    $psShopIdRaw = trim(post('ps_shop_id'));
    $psShopId = $psShopIdRaw === '' ? null : (int)$psShopIdRaw;
    $mlClientId = trim(post('ml_client_id'));
    $mlClientSecret = trim(post('ml_client_secret'));
    $mlRedirectUri = trim(post('ml_redirect_uri'));

    if ($channelType === 'NONE') {
      $connectionEnabled = 0;
    }

    if ($id <= 0) {
      $error = 'Sitio inválido.';
    } elseif ($name === '') {
      $error = 'Ingresá el nombre del sitio.';
    } elseif (mb_strlen($name) > 80) {
      $error = 'El nombre del sitio no puede superar los 80 caracteres.';
    } elseif ($margin === null) {
      $error = 'Margen (%) inválido. Usá un valor entre -100 y 999.99.';
    } elseif ($channelType === 'MERCADOLIBRE' && $mlRedirectUri === '') {
      $error = 'Para MercadoLibre, la Redirect URI / Callback URL es obligatoria.';
    } else {
      try {
        $st = $pdo->prepare('SELECT id FROM sites WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
        $st->execute([$name, $id]);
        if ($st->fetch()) {
          $error = 'Ese sitio ya existe.';
        } else {
          $st = $pdo->prepare('UPDATE sites SET name = ?, channel_type = ?, margin_percent = ?, is_active = ?, is_visible = ?, show_in_product = ?, updated_at = NOW() WHERE id = ?');
          $st->execute([$name, $channelType, $margin, $isActive, $showInList, $showInProduct, $id]);
          $st = $pdo->prepare("INSERT INTO site_connections (site_id, channel_type, enabled, ps_base_url, ps_api_key, ps_shop_id, ml_client_id, ml_client_secret, ml_redirect_uri, ml_access_token, ml_refresh_token, ml_user_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NOW())
            ON DUPLICATE KEY UPDATE
              channel_type = VALUES(channel_type),
              enabled = VALUES(enabled),
              ps_base_url = VALUES(ps_base_url),
              ps_api_key = VALUES(ps_api_key),
              ps_shop_id = VALUES(ps_shop_id),
              ml_client_id = VALUES(ml_client_id),
              ml_client_secret = VALUES(ml_client_secret),
              ml_redirect_uri = VALUES(ml_redirect_uri),
              updated_at = NOW(),
              ml_access_token = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_access_token END,
              ml_refresh_token = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_refresh_token END,
              ml_user_id = CASE
                WHEN COALESCE(site_connections.ml_client_id, '') <> COALESCE(VALUES(ml_client_id), '')
                  OR COALESCE(site_connections.ml_client_secret, '') <> COALESCE(VALUES(ml_client_secret), '')
                  OR COALESCE(site_connections.ml_redirect_uri, '') <> COALESCE(VALUES(ml_redirect_uri), '')
                THEN NULL ELSE site_connections.ml_user_id END");
          $st->execute([
            $id,
            $channelType,
            $connectionEnabled,
            $psBaseUrl !== '' ? $psBaseUrl : null,
            $psApiKey !== '' ? $psApiKey : null,
            $psShopId,
            $mlClientId !== '' ? $mlClientId : null,
            $mlClientSecret !== '' ? $mlClientSecret : null,
            $mlRedirectUri !== '' ? $mlRedirectUri : null,
          ]);
          header('Location: sites.php?updated=1');
          exit;
        }
      } catch (Throwable $t) {
        $error = 'No se pudo modificar el sitio.';
      }
    }
  }

  if ($action === 'toggle_site') {
    $id = (int)post('id', '0');
    if ($id > 0) {
      try {
        $st = $pdo->prepare('UPDATE sites SET is_active = (1 - is_active), updated_at = NOW() WHERE id = ?');
        $st->execute([$id]);
        header('Location: sites.php?toggled=1');
        exit;
      } catch (Throwable $t) {
        $error = 'No se pudo cambiar el estado del sitio.';
      }
    }
  }
}

if (get('created') === '1') {
  $message = 'Sitio creado.';
}
if (get('updated') === '1') {
  $message = 'Sitio modificado.';
}
if (get('toggled') === '1') {
  $message = 'Estado del sitio actualizado.';
}
if (get('oauth_connected') === '1') {
  $message = 'MercadoLibre conectado correctamente.';
}
if (get('oauth_error') !== '') {
  $error = 'No se pudo completar la conexión con MercadoLibre. Verificá la configuración e intentá nuevamente.';
}

$where = '';
$params = [];
if ($q !== '') {
  $where = 'WHERE s.name LIKE :q';
  $params[':q'] = '%' . $q . '%';
}

$countSql = "SELECT COUNT(*) FROM sites s $where";
$countSt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
  $countSt->bindValue($key, $value, PDO::PARAM_STR);
}
$countSt->execute();
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$listSql = "SELECT s.id, s.name, s.margin_percent, s.is_active, s.is_visible, s.show_in_product
  FROM sites s
  $where
  ORDER BY s.name ASC
  LIMIT :limit OFFSET :offset";
$listSt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
  $listSt->bindValue($key, $value, PDO::PARAM_STR);
}
$listSt->bindValue(':limit', $limit, PDO::PARAM_INT);
$listSt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listSt->execute();
$sites = $listSt->fetchAll();

$editId = (int)get('edit_id', '0');
$editSite = null;
if ($editId > 0) {
  $st = $pdo->prepare('SELECT id, name, channel_type, margin_percent, is_active, is_visible, show_in_product FROM sites WHERE id = ? LIMIT 1');
  $st->execute([$editId]);
  $editSite = $st->fetch();
}

$editConnection = [
  'channel_type' => $editSite ? normalize_channel_type($editSite['channel_type'] ?? 'NONE') : 'NONE',
  'enabled' => 0,
  'ps_base_url' => '',
  'ps_api_key' => '',
  'ps_shop_id' => '',
  'ml_client_id' => '',
  'ml_client_secret' => '',
  'ml_redirect_uri' => '',
  'ml_access_token' => '',
  'ml_refresh_token' => '',
  'ml_user_id' => '',
];
if ($editSite) {
  $st = $pdo->prepare('SELECT site_id, channel_type, enabled, ps_base_url, ps_api_key, ps_shop_id, ml_client_id, ml_client_secret, ml_redirect_uri, ml_access_token, ml_refresh_token, ml_user_id FROM site_connections WHERE site_id = ? LIMIT 1');
  $st->execute([(int)$editSite['id']]);
  $row = $st->fetch();
  if ($row) {
    $editConnection = [
      'channel_type' => normalize_channel_type($row['channel_type'] ?? $editConnection['channel_type']),
      'enabled' => (int)($row['enabled'] ?? 0),
      'ps_base_url' => (string)($row['ps_base_url'] ?? ''),
      'ps_api_key' => (string)($row['ps_api_key'] ?? ''),
      'ps_shop_id' => isset($row['ps_shop_id']) ? (string)$row['ps_shop_id'] : '',
      'ml_client_id' => (string)($row['ml_client_id'] ?? ''),
      'ml_client_secret' => (string)($row['ml_client_secret'] ?? ''),
      'ml_redirect_uri' => (string)($row['ml_redirect_uri'] ?? ''),
      'ml_access_token' => (string)($row['ml_access_token'] ?? ''),
      'ml_refresh_token' => (string)($row['ml_refresh_token'] ?? ''),
      'ml_user_id' => (string)($row['ml_user_id'] ?? ''),
    ];
  }
}

$formConnection = $editConnection;
if (is_post() && $error !== '' && in_array(post('action'), ['create_site', 'update_site'], true)) {
  $formConnection = [
    'channel_type' => normalize_channel_type(post('channel_type', $editConnection['channel_type'])),
    'enabled' => post('connection_enabled', (string)$editConnection['enabled']) === '1' ? 1 : 0,
    'ps_base_url' => trim(post('ps_base_url', $editConnection['ps_base_url'])),
    'ps_api_key' => trim(post('ps_api_key', $editConnection['ps_api_key'])),
    'ps_shop_id' => trim(post('ps_shop_id', $editConnection['ps_shop_id'])),
    'ml_client_id' => trim(post('ml_client_id', $editConnection['ml_client_id'])),
    'ml_client_secret' => trim(post('ml_client_secret', $editConnection['ml_client_secret'])),
    'ml_redirect_uri' => trim(post('ml_redirect_uri', $editConnection['ml_redirect_uri'])),
    'ml_access_token' => $editConnection['ml_access_token'],
    'ml_refresh_token' => $editConnection['ml_refresh_token'],
    'ml_user_id' => $editConnection['ml_user_id'],
  ];
}

$showNewForm = get('new') === '1' || $editSite !== null;

$queryBase = [];
if ($q !== '') $queryBase['q'] = $q;
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <div>
        <h2 class="page-title">Sitios</h2>
        <span class="muted">Configurá márgenes por canal (extra %).</span>
      </div>
      <div class="inline-actions">
        <?php if ($showNewForm && !$editSite): ?>
          <a class="btn btn-ghost" href="sites.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Cancelar</a>
        <?php else: ?>
          <a class="btn" href="sites.php?<?= e(http_build_query(array_merge($queryBase, ['new' => 1]))) ?>">Nuevo sitio</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
      <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="get" action="sites.php" class="stack">
        <div class="input-icon">
          <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre">
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Buscar</button>
          <?php if ($q !== ''): ?><a class="btn btn-ghost" href="sites.php">Limpiar</a><?php endif; ?>
        </div>
      </form>
    </div>

    <?php if ($showNewForm): ?>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><?= $editSite ? 'Modificar sitio' : 'Nuevo sitio' ?></h3>
        </div>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="<?= $editSite ? 'update_site' : 'create_site' ?>">
          <?php if ($editSite): ?>
            <input type="hidden" name="id" value="<?= (int)$editSite['id'] ?>">
          <?php endif; ?>
          <div class="grid" style="grid-template-columns: minmax(240px, 1.2fr) minmax(320px, 1fr) minmax(320px, 1fr); gap: var(--space-4); align-items: end;">
            <label class="form-field">
              <span class="form-label">Nombre del sitio</span>
              <input class="form-control" type="text" name="name" maxlength="80" required value="<?= e($editSite ? (string)$editSite['name'] : '') ?>">
            </label>
            <div class="grid" style="grid-template-columns: repeat(2, minmax(140px, 1fr)); gap: var(--space-3);">
              <label class="form-field">
                <span class="form-label">Margen (%)</span>
                <input class="form-control" type="number" name="margin_percent" min="-100" max="999.99" step="0.01" required value="<?= e($editSite ? number_format((float)$editSite['margin_percent'], 2, '.', '') : '0') ?>">
              </label>
              <label class="form-field">
                <span class="form-label">Estado</span>
                <select class="form-control" name="is_active">
                  <?php $activeValue = $editSite ? (int)$editSite['is_active'] : 1; ?>
                  <option value="1" <?= $activeValue === 1 ? 'selected' : '' ?>>Activo</option>
                  <option value="0" <?= $activeValue === 0 ? 'selected' : '' ?>>Inactivo</option>
                </select>
              </label>
            </div>
            <div class="grid" style="grid-template-columns: repeat(2, minmax(140px, 1fr)); gap: var(--space-3);">
              <label class="form-field">
                <span class="form-label">Mostrar en lista</span>
                <select class="form-control" name="is_visible">
                  <?php $visibleValue = $editSite ? (int)$editSite['is_visible'] : 1; ?>
                  <option value="1" <?= $visibleValue === 1 ? 'selected' : '' ?>>Activo</option>
                  <option value="0" <?= $visibleValue === 0 ? 'selected' : '' ?>>Inactivo</option>
                </select>
              </label>
              <label class="form-field">
                <span class="form-label">Mostrar en producto</span>
                <select class="form-control" name="show_in_product">
                  <?php $showProductValue = $editSite ? (int)$editSite['show_in_product'] : 1; ?>
                  <option value="1" <?= $showProductValue === 1 ? 'selected' : '' ?>>Activo</option>
                  <option value="0" <?= $showProductValue === 0 ? 'selected' : '' ?>>Inactivo</option>
                </select>
              </label>
            </div>
          </div>
          <label class="form-field" style="max-width: 360px;">
            <span class="form-label">Tipo de conexión</span>
            <select class="form-control" name="channel_type" id="channel_type">
              <?php $channelTypeValue = $formConnection['channel_type']; ?>
              <option value="NONE" <?= $channelTypeValue === 'NONE' ? 'selected' : '' ?>>Sin conexión</option>
              <option value="PRESTASHOP" <?= $channelTypeValue === 'PRESTASHOP' ? 'selected' : '' ?>>PrestaShop</option>
              <option value="MERCADOLIBRE" <?= $channelTypeValue === 'MERCADOLIBRE' ? 'selected' : '' ?>>MercadoLibre</option>
            </select>
          </label>

          <div id="connFields" class="stack">
            <label class="form-field" style="max-width: 220px;">
              <span class="form-label">Habilitado</span>
              <select class="form-control" name="connection_enabled">
                <option value="1" <?= (int)$formConnection['enabled'] === 1 ? 'selected' : '' ?>>Sí</option>
                <option value="0" <?= (int)$formConnection['enabled'] === 0 ? 'selected' : '' ?>>No</option>
              </select>
            </label>

            <div id="psFields" class="grid" style="grid-template-columns: repeat(3, minmax(220px, 1fr)); gap: var(--space-3);">
              <label class="form-field">
                <span class="form-label">URL base</span>
                <input class="form-control" type="text" name="ps_base_url" maxlength="255" value="<?= e($formConnection['ps_base_url']) ?>">
              </label>
              <label class="form-field">
                <span class="form-label">API Key / Token</span>
                <input class="form-control" type="text" name="ps_api_key" maxlength="255" value="<?= e($formConnection['ps_api_key']) ?>">
              </label>
              <label class="form-field">
                <span class="form-label">Shop ID (opcional)</span>
                <input class="form-control" type="number" name="ps_shop_id" min="0" step="1" value="<?= e($formConnection['ps_shop_id']) ?>">
              </label>
            </div>

            <div id="mlFields" class="stack" style="gap: var(--space-3);">
              <div class="grid" style="grid-template-columns: repeat(3, minmax(220px, 1fr)); gap: var(--space-3);">
                <label class="form-field">
                  <span class="form-label">Client ID</span>
                  <input class="form-control" type="text" name="ml_client_id" maxlength="100" value="<?= e($formConnection['ml_client_id']) ?>">
                </label>
                <label class="form-field">
                  <span class="form-label">Client Secret</span>
                  <input class="form-control" type="text" name="ml_client_secret" maxlength="255" value="<?= e($formConnection['ml_client_secret']) ?>">
                </label>
                <label class="form-field">
                  <span class="form-label">Redirect URI / Callback URL</span>
                  <input class="form-control" type="url" name="ml_redirect_uri" maxlength="255" value="<?= e($formConnection['ml_redirect_uri']) ?>" placeholder="https://tu-dominio.com/sites.php?action=ml_oauth_callback">
                </label>
              </div>
              <div class="grid" style="grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr) auto; gap: var(--space-3); align-items: end;">
                <?php $isMlConnected = trim((string)$formConnection['ml_refresh_token']) !== ''; ?>
                <label class="form-field">
                  <span class="form-label">Estado de conexión</span>
                  <input class="form-control" type="text" readonly value="<?= $isMlConnected ? 'Conectado' : 'No conectado' ?>">
                </label>
                <label class="form-field">
                  <span class="form-label">Usuario ML (opcional)</span>
                  <input class="form-control" type="text" readonly value="<?= e($formConnection['ml_user_id']) ?>" placeholder="-">
                </label>
                <?php if ($editSite): ?>
                  <a class="btn" href="sites.php?<?= e(http_build_query(array_merge($queryBase, ['edit_id' => (int)$editSite['id'], 'action' => 'ml_connect', 'site_id' => (int)$editSite['id']])) ) ?>">Conectar / Obtener token</a>
                <?php else: ?>
                  <button class="btn" type="button" disabled title="Guardá el sitio para conectar MercadoLibre">Conectar / Obtener token</button>
                <?php endif; ?>
              </div>
              <small class="muted">El refresh token se guarda automáticamente al conectar y se usa para renovar sesión. No se carga manualmente.</small>
            </div>
          </div>

          <div class="inline-actions">
            <a class="btn btn-ghost" href="sites.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>">Cancelar</a>
            <button class="btn" type="submit"><?= $editSite ? 'Guardar' : 'Agregar' ?></button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Margen (%)</th>
              <th>Estado</th>
              <th>Mostrar en lista</th>
              <th>Mostrar en producto</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$sites): ?>
              <tr><td colspan="6">Sin sitios.</td></tr>
            <?php else: ?>
              <?php foreach ($sites as $site): ?>
                <tr>
                  <td><?= e($site['name']) ?></td>
                  <td><?= e(number_format((float)$site['margin_percent'], 2, '.', '')) ?></td>
                  <td><?= (int)$site['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                  <td><?= (int)$site['is_visible'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                  <td><?= (int)$site['show_in_product'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                  <td>
                    <div class="inline-actions">
                      <a class="btn btn-ghost btn-sm" href="sites.php?<?= e(http_build_query(array_merge($queryBase, ['page' => $page, 'edit_id' => (int)$site['id']])) ) ?>">Modificar</a>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="toggle_site">
                        <input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= (int)$site['is_active'] === 1 ? 'Inactivar' : 'Activar' ?></button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="inline-actions">
        <?php
          $prevQuery = $queryBase;
          $prevQuery['page'] = $prevPage;
          $nextQuery = $queryBase;
          $nextQuery['page'] = $nextPage;
        ?>
        <?php if ($page > 1): ?>
          <a class="btn btn-ghost" href="sites.php?<?= e(http_build_query($prevQuery)) ?>">&laquo; Anterior</a>
        <?php else: ?>
          <span class="muted">&laquo; Anterior</span>
        <?php endif; ?>
        <span class="muted">Página <?= (int)$page ?> de <?= (int)$totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="btn btn-ghost" href="sites.php?<?= e(http_build_query($nextQuery)) ?>">Siguiente &raquo;</a>
        <?php else: ?>
          <span class="muted">Siguiente &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<?php if ($showNewForm): ?>
  <script>
    (function () {
      var channelType = document.getElementById('channel_type');
      var connFields = document.getElementById('connFields');
      var prestashopFields = document.getElementById('psFields');
      var mercadolibreFields = document.getElementById('mlFields');

      function toggleConnectionFields() {
        if (!channelType || !connFields || !prestashopFields || !mercadolibreFields) {
          return;
        }
        var value = channelType.value;
        if (value === 'NONE') {
          connFields.style.display = 'none';
          prestashopFields.style.display = 'none';
          mercadolibreFields.style.display = 'none';
          return;
        }
        connFields.style.display = '';
        prestashopFields.style.display = value === 'PRESTASHOP' ? '' : 'none';
        mercadolibreFields.style.display = value === 'MERCADOLIBRE' ? '' : 'none';
      }

      if (channelType) {
        channelType.addEventListener('change', toggleConnectionFields);
      }
      toggleConnectionFields();
    })();
  </script>
<?php endif; ?>
</body>
</html>
