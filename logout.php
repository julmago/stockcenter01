<?php
require_once __DIR__ . '/bootstrap.php';

$mode = (string)($_GET['mode'] ?? 'full');

if ($mode === 'profile') {
  unset($_SESSION['user'], $_SESSION['logged_in'], $_SESSION['profile_selected']);
  header('Location: select_profile.php');
  exit;
}

$params = session_get_cookie_params();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
  setcookie(
    session_name(),
    '',
    [
      'expires' => time() - 42000,
      'path' => $params['path'] ?? '/',
      'domain' => $params['domain'] ?? '',
      'secure' => $params['secure'] ?? false,
      'httponly' => $params['httponly'] ?? true,
      'samesite' => $params['samesite'] ?? 'Lax',
    ]
  );
}
session_destroy();
header('Location: login.php');
exit;
