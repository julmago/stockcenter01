<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
$u = current_user();
$role = $u['role'] ?? '';
$is_superadmin = $role === 'superadmin';
$can_manage_prestashop = hasPerm('menu_config_prestashop');
$can_view_design = hasPerm('menu_design');
$display_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
if ($display_name === '') {
  $display_name = $u['email'] ?? 'Usuario';
}
?>
<header class="topbar">
  <div class="container topbar-content">
    <div class="topbar-left">
      <a class="brand" href="dashboard.php">
        <span class="brand-title">TS WORK</span>
      </a>
      <nav class="nav nav-primary">
        <a class="nav-link" href="dashboard.php">Listas</a>
        <a class="nav-link" href="product_list.php">Productos</a>
        <a class="nav-link" href="tasks_all.php">Tareas</a>
        <?php if (hasPerm('tasks_settings')): ?>
          <a class="nav-link" href="task_settings.php">Tareas · Configuración</a>
        <?php endif; ?>
      </nav>
    </div>
    <div class="topbar-center" aria-hidden="true"></div>
    <div class="topbar-right">
      <nav class="nav nav-utility">
        <?php if ($can_manage_prestashop): ?>
          <a class="nav-link" href="ps_config.php">Config PrestaShop</a>
        <?php endif; ?>
        <?php if ($can_view_design): ?>
          <a class="nav-link" href="design.php">Diseño</a>
        <?php endif; ?>
        <?php if ($is_superadmin): ?>
          <a class="nav-link" href="roles.php">Roles</a>
        <?php endif; ?>
      </nav>
      <div class="user-menu" data-user-menu>
        <button class="user-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
          <?= e($display_name) ?> <span aria-hidden="true">▾</span>
        </button>
        <div class="user-menu-dropdown" role="menu">
          <a class="user-menu-item" href="logout.php?mode=profile" role="menuitem">Cambiar perfil</a>
          <a class="user-menu-item" href="logout.php?mode=full" role="menuitem">Salir del sistema</a>
        </div>
      </div>
    </div>
  </div>
</header>
<script>
  (() => {
    const menu = document.querySelector('[data-user-menu]');
    if (!menu) return;
    const button = menu.querySelector('.user-menu-button');
    const toggle = () => {
      const isOpen = menu.classList.toggle('user-menu--open');
      if (button) {
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      }
    };
    button?.addEventListener('click', (event) => {
      event.stopPropagation();
      toggle();
    });
    document.addEventListener('click', (event) => {
      if (!menu.contains(event.target)) {
        menu.classList.remove('user-menu--open');
        button?.setAttribute('aria-expanded', 'false');
      }
    });
  })();
</script>
