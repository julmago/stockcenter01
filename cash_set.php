<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();
require_permission(hasPerm('cashbox_access'), 'Sin permiso para acceder a Caja.');

$cashbox_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cashbox = fetch_cashbox_by_id($cashbox_id, true);
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

$_SESSION['cashbox_id'] = $cashbox_id;
redirect($redirect);
