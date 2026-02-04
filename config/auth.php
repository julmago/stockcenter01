<?php
if (!function_exists('env')) {
  function env(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
      return $default;
    }
    return $value;
  }
}
return [
  'gateway_email' => env('GATEWAY_EMAIL', 'gateway@tswork.local'),
  'gateway_password_hash' => env(
    'GATEWAY_PASSWORD_HASH',
    '$2y$12$C33HqhxyaqvKi/tTjduh0u2JNEOB/yumj8zQkwk4bKI4jLRit91Vm'
  ),
  'session_lifetime_days' => (int)env('SESSION_LIFETIME_DAYS', '30'),
];
