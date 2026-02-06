<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
$u = current_user();
$role = $u['role'] ?? '';
$is_superadmin = $role === 'superadmin';
$can_manage_tasks_settings = hasPerm('tasks_settings');
$can_manage_prestashop = hasPerm('menu_config_prestashop');
$can_view_design = hasPerm('menu_design');
$show_config_menu = $can_manage_tasks_settings || $can_manage_prestashop || $can_view_design || $is_superadmin;
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
      </nav>
    </div>
    <div class="topbar-center" aria-hidden="true"></div>
    <div class="topbar-right">
      <nav class="nav nav-utility">
        <?php if ($show_config_menu): ?>
          <div class="config-menu" data-config-menu>
            <button class="config-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
              Config <span aria-hidden="true">▾</span>
            </button>
            <div class="config-menu-dropdown" role="menu">
              <?php if ($can_manage_tasks_settings): ?>
                <a class="config-menu-item" href="task_settings.php" role="menuitem">Tareas · Configuración</a>
              <?php endif; ?>
              <?php if ($can_manage_prestashop): ?>
                <a class="config-menu-item" href="ps_config.php" role="menuitem">Config PrestaShop</a>
              <?php endif; ?>
              <?php if ($can_view_design): ?>
                <a class="config-menu-item" href="design.php" role="menuitem">Diseño</a>
              <?php endif; ?>
              <?php if ($is_superadmin): ?>
                <a class="config-menu-item" href="roles.php" role="menuitem">Roles</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </nav>
      <div class="user-menu" data-user-menu>
        <button class="user-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
          <?= e($display_name) ?> <span aria-hidden="true">▾</span>
        </button>
        <div class="user-menu-dropdown" role="menu">
          <a class="user-menu-item" href="logout_profile.php" role="menuitem">Cambiar perfil</a>
          <a class="user-menu-item" href="logout_profile.php" role="menuitem">Salir</a>
        </div>
      </div>
    </div>
  </div>
</header>
<script>
  (() => {
    const setupMenu = (selector, buttonSelector, openClass) => {
      const menu = document.querySelector(selector);
      if (!menu) return;
      const button = menu.querySelector(buttonSelector);
      const close = () => {
        menu.classList.remove(openClass);
        button?.setAttribute('aria-expanded', 'false');
      };
      const toggle = () => {
        const isOpen = menu.classList.toggle(openClass);
        button?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      };
      button?.addEventListener('click', (event) => {
        event.stopPropagation();
        toggle();
      });
      menu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', close);
      });
      document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) {
          close();
        }
      });
    };

    setupMenu('[data-user-menu]', '.user-menu-button', 'user-menu--open');
    setupMenu('[data-config-menu]', '.config-menu-button', 'config-menu--open');
  })();
</script>
