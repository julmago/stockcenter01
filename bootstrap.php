<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

error_reporting(E_ALL);
ini_set('log_errors', '1');
$debug = (bool)($config['debug'] ?? false);
ini_set('display_errors', $debug ? '1' : '0');

$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
  mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/php-error.log');

set_exception_handler(function (Throwable $e): void {
  error_log(sprintf(
    '[%s] Uncaught exception: %s in %s:%d',
    date('c'),
    $e->getMessage(),
    $e->getFile(),
    $e->getLine()
  ));
  abort(500, 'Ocurrió un error interno. Intentá nuevamente más tarde.');
});

register_shutdown_function(function (): void {
  $error = error_get_last();
  if ($error !== null) {
    error_log(sprintf(
      '[%s] Fatal error: %s in %s:%d',
      date('c'),
      $error['message'] ?? 'unknown',
      $error['file'] ?? 'unknown',
      $error['line'] ?? 0
    ));
    if (!headers_sent()) {
      abort(500, 'Ocurrió un error interno. Intentá nuevamente más tarde.');
    }
  }
});

$sessionLifetime = 31536000;
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
ini_set('session.cookie_lifetime', (string)$sessionLifetime);
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
  'lifetime' => $sessionLifetime,
  'path' => '/',
  'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
  error_log(sprintf('[%s] Session failed to start.', date('c')));
  abort(500, 'No se pudo iniciar la sesión. Intentá nuevamente más tarde.');
}

function abort(int $code, string $message): void {
  http_response_code($code);
  echo "<h1>Ocurrió un problema</h1>";
  echo "<p>" . htmlspecialchars($message) . "</p>";
  exit;
}

function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void {
  header("Location: {$to}");
  exit;
}

function require_login(): void {
  if (empty($_SESSION['user'])) {
    redirect('login.php');
  }
}

function require_role(array $roles, string $message = 'Sin permisos'): void {
  require_login();
  $user = current_user();
  $role = $user['role'] ?? '';
  if (!in_array($role, $roles, true)) {
    abort(403, $message);
  }
}

function require_permission(bool $allowed, string $message = 'Sin permisos'): void {
  if (!$allowed) {
    abort(403, $message);
  }
}

function current_user(): array {
  return $_SESSION['user'] ?? [];
}

function current_role(): string {
  $user = current_user();
  return (string)($user['role'] ?? '');
}

function can_import_csv(): bool {
  return in_array(current_role(), ['superadmin', 'admin'], true);
}

function can_sync_prestashop(): bool {
  return in_array(current_role(), ['superadmin', 'admin'], true);
}

function can_delete_list_item(): bool {
  return in_array(current_role(), ['superadmin', 'admin'], true);
}

function is_readonly_role(): bool {
  return current_role() === 'lectura';
}

function can_create_list(): bool {
  return !is_readonly_role();
}

function can_create_product(): bool {
  return !is_readonly_role();
}

function can_edit_list(): bool {
  return !is_readonly_role();
}

function can_scan(): bool {
  return !is_readonly_role();
}

function can_close_list(): bool {
  return !is_readonly_role();
}

function can_edit_product(): bool {
  return !is_readonly_role();
}

function can_add_code(): bool {
  return !is_readonly_role();
}

function is_post(): bool {
  return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post(string $key, string $default=''): string {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function get(string $key, string $default=''): string {
  return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}
