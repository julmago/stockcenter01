<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$u = current_user();
$is_superadmin = ($u['role'] ?? '') === 'superadmin';
?>
<header class="topbar">
  <div class="container topbar-content">
    <div class="brand">
      <span class="brand-title">TS WORK</span>
    </div>
    <nav class="nav">
      <a class="nav-link" href="dashboard.php">Listas</a>
      <?php if (can_create_list()): ?>
        <a class="nav-link" href="list_new.php">Nuevo Listado</a>
      <?php endif; ?>
      <?php if (can_create_product()): ?>
        <a class="nav-link" href="product_new.php">Nuevo Producto</a>
      <?php endif; ?>
      <a class="nav-link" href="product_list.php">Listado de productos</a>
      <?php if (can_import_csv()): ?>
        <a class="nav-link" href="product_import.php">Importar CSV</a>
      <?php endif; ?>
      <?php if (hasPerm('menu_config_prestashop')): ?>
        <a class="nav-link" href="ps_config.php">Config PrestaShop</a>
      <?php endif; ?>
      <?php if (hasPerm('menu_design')): ?>
        <a class="nav-link" href="design.php">Diseño</a>
      <?php endif; ?>
      <?php if ($is_superadmin): ?>
        <a class="nav-link" href="roles.php">Roles</a>
      <?php endif; ?>
    </nav>
    <div class="topbar-user">
      <span class="muted small">
        <?= e($u['first_name'] . ' ' . $u['last_name']) ?> · <?= e($u['email']) ?>
      </span>
      <a class="btn btn-ghost" href="logout.php">Salir</a>
    </div>
  </div>
</header>
