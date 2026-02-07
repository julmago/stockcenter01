<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();
$u = current_user();
$role = $u['role'] ?? '';
$is_superadmin = $role === 'superadmin';
$can_manage_tasks_settings = hasPerm('tasks_settings');
$can_manage_prestashop = hasPerm('menu_config_prestashop');
$can_view_design = hasPerm('menu_design');
$can_cashbox_access = hasPerm('cashbox_access');
$can_cashbox_manage = hasPerm('cashbox_manage_boxes');
$show_config_menu = $can_manage_tasks_settings || $can_manage_prestashop || $can_view_design || $is_superadmin;
$display_name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
if ($display_name === '') {
  $display_name = $u['email'] ?? 'Usuario';
}

$cashboxes = [];
$active_cashbox_id = 0;
$active_cashbox_name = 'Sin caja';
$cash_redirect_target = (string)($_SERVER['REQUEST_URI'] ?? 'dashboard.php');
if ($can_cashbox_access) {
  require_once __DIR__ . '/../cash/cash_helpers.php';
  $cashboxes = fetch_active_cashboxes();
  $active_cashbox_id = cashbox_selected_id();
  $active_cashbox = $active_cashbox_id > 0 ? fetch_cashbox_by_id($active_cashbox_id, false) : null;
  if ($active_cashbox) {
    $active_cashbox_name = $active_cashbox['name'];
  } elseif ($cashboxes) {
    $active_cashbox_name = 'Sin seleccionar';
  }
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
        <?php if ($can_cashbox_access): ?>
          <div class="cash-menu" data-cash-menu>
            <button class="cash-menu-button" type="button" aria-haspopup="true" aria-expanded="false">
              Caja: <?= e($active_cashbox_name) ?> <span aria-hidden="true">▾</span>
            </button>
            <div class="cash-menu-dropdown" role="menu">
              <?php if ($cashboxes): ?>
                <?php foreach ($cashboxes as $cashbox): ?>
                  <?php
                    $cashbox_id = (int)$cashbox['id'];
                    $is_active = $cashbox_id === $active_cashbox_id;
                  ?>
                  <a class="cash-menu-item<?= $is_active ? ' cash-menu-item--active' : '' ?>"
                     href="cash/cash_set.php?id=<?= $cashbox_id ?>&redirect=<?= urlencode($cash_redirect_target) ?>"
                     role="menuitem">
                    <?= e($cashbox['name']) ?>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="cash-menu-empty">No hay cajas activas.</span>
              <?php endif; ?>
              <a class="cash-menu-item" href="cash/cash_select.php" role="menuitem">Ir a Caja</a>
              <?php if ($can_cashbox_manage): ?>
                <a class="cash-menu-item" href="cash/cash_manage.php" role="menuitem">Administrar cajas</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
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
          <a class="user-menu-item" href="logout_profile.php" role="menuitem">Cerrar perfil</a>
          <a class="user-menu-item" href="logout_system.php" role="menuitem">Salir del sistema</a>
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
    setupMenu('[data-cash-menu]', '.cash-menu-button', 'cash-menu--open');
  })();
</script>
