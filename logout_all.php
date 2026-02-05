<?php
require_once __DIR__ . '/bootstrap.php';

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
redirect('login.php');
