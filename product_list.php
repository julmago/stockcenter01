<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_error.log');
error_reporting(E_ALL);

$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
  mkdir($logsDir, 0775, true);
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

$visibleSites = [];
$products = [];
$q = trim(get('q', ''));
$page = max(1, (int) get('page', 1));
$limit = 50;
$total = 0;
$total_pages = 1;
$offset = 0;
$numeric = ($q !== '' && ctype_digit($q));
$query_base = [];
$prev_page = 1;
$next_page = 1;

try {
  require_login();
  ensure_brands_schema();
  ensure_sites_schema();

  $where = '';
  $params = [];
  if ($q !== '') {
    $like = '%' . $q . '%';
    $where = "WHERE (p.sku LIKE :like_sku OR p.name LIKE :like_name OR pc.code LIKE :like_code)";
    $params = [
      ':like_sku' => $like,
      ':like_name' => $like,
      ':like_code' => $like,
    ];
  }

  $siteHasIsVisible = false;
  $siteVisibleSt = db()->query("SHOW COLUMNS FROM sites LIKE 'is_visible'");
  if ($siteVisibleSt && $siteVisibleSt->fetch()) {
    $siteHasIsVisible = true;
  }

  $visibleSitesSql = "SELECT * FROM sites WHERE is_active = 1";
  if ($siteHasIsVisible) {
    $visibleSitesSql .= " AND is_visible = 1";
  }
  $visibleSitesSql .= " ORDER BY id ASC";

  $visibleSitesSt = db()->query($visibleSitesSql);
  $visibleSites = $visibleSitesSt ? $visibleSitesSt->fetchAll() : [];

  $supplierMarginColumn = null;
  $supplierColumnsSt = db()->query("SHOW COLUMNS FROM suppliers");
  if ($supplierColumnsSt) {
    foreach ($supplierColumnsSt->fetchAll() as $supplierColumn) {
      $field = (string)($supplierColumn['Field'] ?? '');
      if ($field === 'default_margin_percent') {
        $supplierMarginColumn = $field;
        break;
      }
      if ($supplierMarginColumn === null && stripos($field, 'margin') !== false) {
        $supplierMarginColumn = $field;
      }
    }
  }

  $supplierMarginExpr = '0';
  $groupBySupplierMargin = '';
  if ($supplierMarginColumn !== null) {
    $safeSupplierMarginColumn = str_replace('`', '``', $supplierMarginColumn);
    $supplierMarginExpr = "COALESCE(s.`{$safeSupplierMarginColumn}`, 0)";
    $groupBySupplierMargin = ", s.`{$safeSupplierMarginColumn}`";
  }

  $count_sql = "SELECT COUNT(DISTINCT p.id) AS total
    FROM products p
    LEFT JOIN brands b ON b.id = p.brand_id
    LEFT JOIN product_codes pc ON pc.product_id = p.id
    $where";
  $count_st = db()->prepare($count_sql);
  foreach ($params as $key => $value) {
    $count_st->bindValue($key, $value, PDO::PARAM_STR);
  }
  $count_st->execute();
  $total = (int) $count_st->fetchColumn();
  $total_pages = max(1, (int) ceil($total / $limit));
  $page = min($page, $total_pages);
  $offset = ($page - 1) * $limit;

  $select_sql = "SELECT p.id, p.sku, p.name, COALESCE(b.name, p.brand) AS brand,"
    . " s.name AS supplier_name,"
    . " ps1.supplier_cost,"
    . " {$supplierMarginExpr} AS supplier_default_margin_percent,"
    . ($numeric ? " MAX(pc.code = :code_exact) AS code_exact_match" : " 0 AS code_exact_match")
    . " FROM products p"
    . " LEFT JOIN brands b ON b.id = p.brand_id"
    . " LEFT JOIN ("
    . "   SELECT x.product_id, x.supplier_id"
    . "   FROM product_suppliers x"
    . "   JOIN ("
    . "     SELECT product_id, MIN(id) AS min_id"
    . "     FROM product_suppliers"
    . "     WHERE is_active = 1"
    . "     GROUP BY product_id"
    . "   ) y ON y.product_id = x.product_id AND y.min_id = x.id"
    . " ) ps1 ON ps1.product_id = p.id"
    . " LEFT JOIN suppliers s ON s.id = ps1.supplier_id"
    . " LEFT JOIN product_codes pc ON pc.product_id = p.id"
    . " $where"
    . " GROUP BY p.id, p.sku, p.name, COALESCE(b.name, p.brand), s.name, ps1.supplier_cost{$groupBySupplierMargin}"
    . " ORDER BY code_exact_match DESC, p.name ASC, p.id ASC"
    . " LIMIT :limit OFFSET :offset";
  $select_params = $params;
  if ($numeric) {
    $select_params[':code_exact'] = $q;
  }
  $st = db()->prepare($select_sql);
  foreach ($select_params as $key => $value) {
    $st->bindValue($key, $value, PDO::PARAM_STR);
  }
  $st->bindValue(':limit', $limit, PDO::PARAM_INT);
  $st->bindValue(':offset', $offset, PDO::PARAM_INT);
  $st->execute();
  $products = $st->fetchAll();

  if ($q !== '') {
    $query_base['q'] = $q;
  }
  $prev_page = max(1, $page - 1);
  $next_page = min($total_pages, $page + 1);
} catch (Throwable $e) {
  error_log('[product_list] ' . $e->getMessage());
  if ($e instanceof PDOException && isset($e->errorInfo[2])) {
    error_log('[product_list][sql] ' . $e->errorInfo[2]);
  }

  $visibleSites = [];
  $products = [];
  $total = 0;
  $total_pages = 1;
  $page = 1;
  $offset = 0;
  $query_base = ($q !== '') ? ['q' => $q] : [];
  $prev_page = 1;
  $next_page = 1;
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
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <div>
        <h2 class="page-title">Listado de productos</h2>
        <span class="muted">Explorá el catálogo y accedé al detalle.</span>
      </div>
      <div class="inline-actions">
        <?php if (can_create_product()): ?>
          <a class="btn" href="product_new.php">+ Nuevo producto</a>
        <?php endif; ?>
        <?php if (can_import_csv()): ?>
          <a class="btn btn-ghost" href="product_import.php">Importar CSV</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="suppliers.php">Proveedores</a>
      </div>
    </div>

    <div class="card">
      <form method="get" action="product_list.php" id="product-search-form" class="stack">
        <div class="input-icon">
          <input class="form-control" type="text" name="q" id="product-search-input" value="<?= e($q) ?>" placeholder="Buscar por SKU, nombre o código" autofocus>
        </div>
        <div class="inline-actions">
          <button class="btn" type="submit">Buscar</button>
          <?php if ($q !== ''): ?><a class="btn btn-ghost" href="product_list.php">Limpiar</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr><th>sku</th><th>nombre</th><th>marca</th><th>proveedor</th><?php foreach ($visibleSites as $site): ?><th><?= e($site['name']) ?></th><?php endforeach; ?></tr>
          </thead>
          <tbody>
            <?php if (!$products): ?>
              <tr><td colspan="<?= 4 + count($visibleSites) ?>">Sin productos.</td></tr>
            <?php else: ?>
              <?php foreach ($products as $p): ?>
                <tr style="cursor:pointer;" onclick="window.location='product_view.php?id=<?= (int)$p['id'] ?>'">
                  <td><?= e($p['sku']) ?></td>
                  <td><?= e($p['name']) ?></td>
                  <td><?= e($p['brand']) ?></td>
                  <td><?= $p['supplier_name'] ? e($p['supplier_name']) : '-' ?></td>
                  <?php foreach ($visibleSites as $site): ?>
                    <td>
                      <?php
                        if ($p['supplier_name'] && $p['supplier_cost'] !== null) {
                          $finalPrice = round(
                            (float)$p['supplier_cost']
                            * (1 + ((float)$p['supplier_default_margin_percent'] / 100))
                            * (1 + ((float)$site['margin_percent'] / 100)),
                            0
                          );
                          echo e((string)(int)$finalPrice);
                        } else {
                          echo '-';
                        }
                      ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="inline-actions">
        <?php
          $prev_query = $query_base;
          $prev_query['page'] = $prev_page;
          $next_query = $query_base;
          $next_query['page'] = $next_page;
          $prev_link = 'product_list.php?' . http_build_query($prev_query);
          $next_link = 'product_list.php?' . http_build_query($next_query);
        ?>
        <?php if ($page > 1): ?>
          <a class="btn btn-ghost" href="<?= e($prev_link) ?>">&laquo; Anterior</a>
        <?php else: ?>
          <span class="muted">&laquo; Anterior</span>
        <?php endif; ?>
        <span class="muted">Página <?= (int) $page ?> de <?= (int) $total_pages ?></span>
        <?php if ($page < $total_pages): ?>
          <a class="btn btn-ghost" href="<?= e($next_link) ?>">Siguiente &raquo;</a>
        <?php else: ?>
          <span class="muted">Siguiente &raquo;</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<script>
  const searchInput = document.getElementById('product-search-input');
  if (searchInput) {
    searchInput.focus();
    searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        setTimeout(() => searchInput.focus(), 0);
      }
    });
  }
</script>

</body>
</html>
