<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$u = current_user();
$is_superadmin = ($u['role'] ?? '') === 'superadmin';
?>
<div style="padding:10px;border-bottom:1px solid #ccc;">
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <strong>Entrada de Stock</strong>
    <span>|</span>
    <a href="dashboard.php">Listas</a> 
    <?php if (can_create_list()): ?>
      <a href="list_new.php">Nuevo Listado</a>
    <?php endif; ?>
    <?php if (can_create_product()): ?>
      <a href="product_new.php">Nuevo Producto</a>
    <?php endif; ?>
    <a href="product_list.php">Listado de productos</a>
    <?php if (can_import_csv()): ?>
      <a href="product_import.php">Importar CSV</a>
    <?php endif; ?>
    <?php if ($is_superadmin): ?>
      <a href="ps_config.php">Config PrestaShop</a>
    <?php endif; ?>
    <span style="margin-left:auto;"></span>
    <span>Logeado: <?= e($u['first_name'] . ' ' . $u['last_name']) ?> (<?= e($u['email']) ?>)</span>
    <a href="logout.php">Salir</a>
  </div>
</div>
