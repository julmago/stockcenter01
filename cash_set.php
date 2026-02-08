<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();

$cashbox_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cashbox = fetch_cashbox_by_id($cashbox_id, false);
$redirect = urldecode((string)($_GET['redirect'] ?? ''));

if ($redirect === '') {
  $redirect = url_path('cash_select.php');
}

if (strpos($redirect, '://') !== false || str_starts_with($redirect, '//')) {
  $redirect = url_path('cash_select.php');
}

if (!$cashbox) {
  redirect(url_path('cash_select.php?error=invalid'));
}

require_permission(hasCashboxPerm('can_open_module', $cashbox_id), 'Sin permiso para acceder a esta caja.');

$_SESSION['cashbox_id'] = $cashbox_id;
redirect($redirect);
