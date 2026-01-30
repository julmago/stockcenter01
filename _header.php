<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$u = current_user();
?>
<div style="padding:10px;border-bottom:1px solid #ccc;">
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <strong>Entrada de Stock</strong>
    <span>|</span>
    <a href="list_new.php">Nuevo Listado</a>
    <a href="product_new.php">Nuevo Producto</a>
    <a href="product_list.php">Listado de productos</a>
    <a href="ps_config.php">Config PrestaShop</a>
    <span style="margin-left:auto;"></span>
    <span>Logeado: <?= e($u['first_name'] . ' ' . $u['last_name']) ?> (<?= e($u['email']) ?>)</span>
    <a href="logout.php">Salir</a>
  </div>
</div>
