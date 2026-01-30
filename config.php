<?php
// CONFIGURACIÓN
// Cambiá estos datos por los de tu servidor.
function env(string $key, string $default = ''): string {
  $value = getenv($key);
  if ($value === false || $value === '') {
    return $default;
  }
  return $value;
}

return [
  'config_file' => __FILE__,
  'db' => [
    'host' => env('DB_HOST', 'localhost'),
    'name' => env('DB_NAME', 'stockcenter'),
    'user' => env('DB_USER', 'root'),
    'pass' => env('DB_PASS', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
  ],
  // Para producción: poné esto en true
  'debug' => true,
];
