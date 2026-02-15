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
require_once __DIR__ . '/include/pricing.php';

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
$count_sql = '';
$select_sql = '';
$select_params = [];

try {
  require_login();
  ensure_brands_schema();
  ensure_sites_schema();

  $where = '';
  $params = [];
  if ($q !== '') {
    $like = '%' . $q . '%';
    $where = "WHERE (
      p.sku LIKE :like_term
      OR p.name LIKE :like_term
      OR EXISTS (
        SELECT 1
        FROM product_codes pc_search
        WHERE pc_search.product_id = p.id
          AND pc_search.code LIKE :like_term
      )
      OR EXISTS (
        SELECT 1
        FROM product_suppliers ps_search
        WHERE ps_search.product_id = p.id
          AND ps_search.is_active = 1
          AND ps_search.supplier_sku LIKE :like_term
      )
    )";
    $params = [
      ':like_term' => $like,
    ];
  }

  $showInListColumn = null;
  $siteShowInListSt = db()->query("SHOW COLUMNS FROM sites");
  if ($siteShowInListSt) {
    foreach ($siteShowInListSt->fetchAll() as $siteColumn) {
      $field = (string)($siteColumn['Field'] ?? '');
      if ($field === 'show_in_list') {
        $showInListColumn = 'show_in_list';
        break;
      }
      if ($field === 'is_visible') {
        $showInListColumn = 'is_visible';
      }
    }
  }

  $visibleSitesSql = "SELECT * FROM sites WHERE is_active = 1";
  if ($showInListColumn !== null) {
    $visibleSitesSql .= " AND {$showInListColumn} = 1";
  }
  $visibleSitesSql .= " ORDER BY id ASC";

  $visibleSitesSt = db()->query($visibleSitesSql);
  $visibleSites = $visibleSitesSt ? $visibleSitesSt->fetchAll() : [];

  $supplierMarginColumn = null;
  $supplierColumnsSt = db()->query("SHOW COLUMNS FROM suppliers");
  if ($supplierColumnsSt) {
    foreach ($supplierColumnsSt->fetchAll() as $supplierColumn) {
      $field = (string)($supplierColumn['Field'] ?? '');
      if ($field === 'base_margin_percent') {
        $supplierMarginColumn = $field;
        break;
      }
      if ($field === 'default_margin_percent') {
        $supplierMarginColumn = $field;
      } elseif ($supplierMarginColumn === null && stripos($field, 'margin') !== false) {
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
    . " p.sale_mode, p.sale_units_per_pack,"
    . " s.name AS supplier_name,"
    . " ps1.supplier_cost, ps1.cost_unitario, ps1.cost_type, ps1.units_per_pack,"
    . " COALESCE(s.import_default_units_per_pack, 0) AS supplier_default_units_per_pack,"
    . " {$supplierMarginExpr} AS supplier_default_margin_percent,"
    . ($numeric
      ? " CASE WHEN EXISTS ("
        . "   SELECT 1 FROM product_codes pc_exact"
        . "   WHERE pc_exact.product_id = p.id"
        . "     AND pc_exact.code = :code_exact"
        . " ) THEN 1 ELSE 0 END AS code_exact_match"
      : " 0 AS code_exact_match")
    . " FROM products p"
    . " LEFT JOIN brands b ON b.id = p.brand_id"
    . " LEFT JOIN ("
    . "   SELECT ps_pick.product_id, ps_pick.supplier_id, ps_pick.supplier_cost, ps_pick.cost_unitario, ps_pick.cost_type, ps_pick.units_per_pack"
    . "   FROM product_suppliers ps_pick"
    . "   WHERE ps_pick.is_active = 1"
    . "     AND NOT EXISTS ("
    . "       SELECT 1"
    . "       FROM product_suppliers ps_better"
    . "       WHERE ps_better.product_id = ps_pick.product_id"
    . "         AND ps_better.is_active = 1"
    . "         AND ("
    . "           COALESCE(ps_better.cost_unitario, CASE WHEN ps_better.cost_type = 'PACK' AND COALESCE(ps_better.units_per_pack, 0) > 0 THEN ps_better.supplier_cost / ps_better.units_per_pack ELSE ps_better.supplier_cost END, 999999999)"
    . "           < COALESCE(ps_pick.cost_unitario, CASE WHEN ps_pick.cost_type = 'PACK' AND COALESCE(ps_pick.units_per_pack, 0) > 0 THEN ps_pick.supplier_cost / ps_pick.units_per_pack ELSE ps_pick.supplier_cost END, 999999999)"
    . "           OR ("
    . "             COALESCE(ps_better.cost_unitario, CASE WHEN ps_better.cost_type = 'PACK' AND COALESCE(ps_better.units_per_pack, 0) > 0 THEN ps_better.supplier_cost / ps_better.units_per_pack ELSE ps_better.supplier_cost END, 999999999)"
    . "             = COALESCE(ps_pick.cost_unitario, CASE WHEN ps_pick.cost_type = 'PACK' AND COALESCE(ps_pick.units_per_pack, 0) > 0 THEN ps_pick.supplier_cost / ps_pick.units_per_pack ELSE ps_pick.supplier_cost END, 999999999)"
    . "             AND ps_better.id < ps_pick.id"
    . "           )"
    . "         )"
    . "     )"
    . " ) ps1 ON ps1.product_id = p.id"
    . " LEFT JOIN suppliers s ON s.id = ps1.supplier_id AND s.is_active = 1"
    . " $where"
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
  if ($count_sql !== '') {
    error_log('[product_list][count_sql] ' . $count_sql);
    error_log('[product_list][count_params] ' . json_encode($params, JSON_UNESCAPED_UNICODE));
  }
  if ($select_sql !== '') {
    error_log('[product_list][select_sql] ' . $select_sql);
    error_log('[product_list][select_params] ' . json_encode($select_params, JSON_UNESCAPED_UNICODE));
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
                  <td><?= $p['supplier_name'] ? e($p['supplier_name']) : '—' ?></td>
                  <?php foreach ($visibleSites as $site): ?>
                    <td>
                      <?php
                        $effectiveUnitCost = get_effective_unit_cost($p, ['import_default_units_per_pack' => $p['supplier_default_units_per_pack'] ?? 0]);
                        $costForMode = get_cost_for_product_mode($effectiveUnitCost, $p);
                        $priceReason = get_price_unavailable_reason($p, $p);

                        if ($p['supplier_name'] && $costForMode !== null) {
                          $finalPrice = get_final_site_price($costForMode, [
                            'base_percent' => $p['supplier_default_margin_percent'] ?? 0,
                            'discount_percent' => 0,
                          ], $site, 0.0);

                          if ($finalPrice === null) {
                            echo '<span title="' . e($priceReason ?? 'Precio incompleto') . '">—</span>';
                          } else {
                            echo e((string)(int)$finalPrice);
                          }

                          if (($p['sale_mode'] ?? 'UNIDAD') === 'PACK' && (int)($p['sale_units_per_pack'] ?? 0) > 0) {
                            echo '<br><span class="muted small">Pack x' . e((string)(int)$p['sale_units_per_pack']) . '</span>';
                          }
                        } else {
                          echo '<span title="' . e($priceReason ?? 'Precio incompleto') . '">—</span>';
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
