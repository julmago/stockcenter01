<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();

$q = trim(get('q', ''));
$page = max(1, (int) get('page', 1));
$limit = 50;
$offset = ($page - 1) * $limit;
$numeric = ($q !== '' && ctype_digit($q));

$where = '';
$params = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $where = "WHERE (p.sku LIKE ? OR p.name LIKE ? OR pc.code LIKE ?)";
  $params = [$like, $like, $like];
}

$count_sql = "SELECT COUNT(DISTINCT p.id) AS total
  FROM products p
  LEFT JOIN product_codes pc ON pc.product_id = p.id
  $where";
$count_st = db()->prepare($count_sql);
$count_st->execute($params);
$total = (int) $count_st->fetchColumn();
$total_pages = max(1, (int) ceil($total / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;

$select_sql = "SELECT p.id, p.sku, p.name, p.brand,"
  . ($numeric ? " MAX(pc.code = ?) AS code_exact_match" : " 0 AS code_exact_match")
  . " FROM products p"
  . " LEFT JOIN product_codes pc ON pc.product_id = p.id"
  . " $where"
  . " GROUP BY p.id, p.sku, p.name, p.brand"
  . " ORDER BY code_exact_match DESC, p.name ASC, p.id ASC"
  . " LIMIT ? OFFSET ?";
$select_params = $params;
if ($numeric) {
  array_unshift($select_params, $q);
}
$select_params[] = $limit;
$select_params[] = $offset;
$st = db()->prepare($select_sql);
$st->execute($select_params);
$products = $st->fetchAll();

$query_base = [];
if ($q !== '') {
  $query_base['q'] = $q;
}
$prev_page = max(1, $page - 1);
$next_page = min($total_pages, $page + 1);
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Listado de productos</title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>

<div style="padding:10px;">
  <h2>Listado de productos</h2>

  <form method="get" action="product_list.php" id="product-search-form">
    <input type="text" name="q" id="product-search-input" value="<?= e($q) ?>" placeholder="Buscar por SKU, nombre o código" autofocus>
    <button type="submit">Buscar</button>
    <?php if ($q !== ''): ?><a href="product_list.php">Limpiar</a><?php endif; ?>
  </form>

  <table border="1" cellpadding="6" cellspacing="0" style="margin-top:10px;">
    <thead>
      <tr><th>sku</th><th>nombre</th><th>marca</th></tr>
    </thead>
    <tbody>
      <?php if (!$products): ?>
        <tr><td colspan="3">Sin productos.</td></tr>
      <?php else: ?>
        <?php foreach ($products as $p): ?>
          <tr style="cursor:pointer;" onclick="window.location='product_view.php?id=<?= (int)$p['id'] ?>'">
            <td><?= e($p['sku']) ?></td>
            <td><?= e($p['name']) ?></td>
            <td><?= e($p['brand']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top:10px;">
    <?php
      $prev_query = $query_base;
      $prev_query['page'] = $prev_page;
      $next_query = $query_base;
      $next_query['page'] = $next_page;
      $prev_link = 'product_list.php?' . http_build_query($prev_query);
      $next_link = 'product_list.php?' . http_build_query($next_query);
    ?>
    <?php if ($page > 1): ?>
      <a href="<?= e($prev_link) ?>">&laquo; Anterior</a>
    <?php else: ?>
      &laquo; Anterior
    <?php endif; ?>
    | Página <?= (int) $page ?> de <?= (int) $total_pages ?> |
    <?php if ($page < $total_pages): ?>
      <a href="<?= e($next_link) ?>">Siguiente &raquo;</a>
    <?php else: ?>
      Siguiente &raquo;
    <?php endif; ?>
  </div>
</div>

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
