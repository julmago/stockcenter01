<?php
$bootstrapPath = __DIR__ . '/bootstrap.php';
if (file_exists($bootstrapPath)) {
  require_once $bootstrapPath;
}

$dbPath = __DIR__ . '/db.php';
if (file_exists($dbPath)) {
  require_once $dbPath;
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
  require_once $autoloadPath;
}

if (!function_exists('abort')) {
  function abort(int $code, string $message): void {
    http_response_code($code);
    echo "<h1>Ocurrió un problema</h1>";
    echo "<p>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>";
    exit;
  }
}

if (!function_exists('get')) {
  function get(string $key, string $default = ''): string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
  }
}

if (!function_exists('db')) {
  function db(): PDO {
    $configPath = __DIR__ . '/config.php';
    if (!file_exists($configPath)) {
      abort(500, 'Falta el archivo de configuración para la base de datos.');
    }
    $config = require $configPath;
    $db = $config['db'] ?? [];
    $host = $db['host'] ?? 'localhost';
    $name = $db['name'] ?? '';
    $user = $db['user'] ?? '';
    $pass = $db['pass'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';
    if ($name === '' || $user === '') {
      abort(500, 'La configuración de la base de datos está incompleta.');
    }
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    try {
      return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
    } catch (PDOException $e) {
      abort(500, 'No se pudo conectar con la base de datos.');
    }
  }
}

if (!function_exists('require_login')) {
  function require_login(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }
    if (empty($_SESSION['user'])) {
      abort(403, 'No tenés permisos para descargar este archivo.');
    }
  }
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
require_login();

$list_id = (int)get('id','0');
if ($list_id <= 0) abort(400, 'Falta id.');

try {
  $st = db()->prepare("SELECT id, name FROM stock_lists WHERE id = ? LIMIT 1");
  $st->execute([$list_id]);
  $list = $st->fetch();
} catch (Throwable $e) {
  abort(500, 'No se pudo obtener el listado solicitado.');
}
if (!$list) abort(404, 'Listado no encontrado.');

try {
  $st = db()->prepare("
    SELECT p.sku, p.name, i.qty
    FROM stock_list_items i
    JOIN products p ON p.id = i.product_id
    WHERE i.stock_list_id = ?
    ORDER BY p.name ASC
  ");
  $st->execute([$list_id]);
  $rows = $st->fetchAll();
} catch (Throwable $e) {
  abort(500, 'No se pudieron obtener los productos del listado.');
}

if (headers_sent()) {
  abort(500, 'No se pudo generar el archivo.');
}

while (ob_get_level() > 0) {
  ob_end_clean();
}

$hasXlsx = class_exists(Spreadsheet::class) && class_exists(Xlsx::class);

if (!$hasXlsx) {
  $filename = "listado_{$list_id}.csv";
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: max-age=0');
  echo "\xEF\xBB\xBF";
  $output = fopen('php://output', 'w');
  if ($output === false) {
    abort(500, 'No se pudo generar el CSV.');
  }
  fputcsv($output, ['sku', 'nombre', 'cantidad']);
  foreach ($rows as $r) {
    fputcsv($output, [$r['sku'], $r['name'], (int)$r['qty']]);
  }
  fclose($output);
  exit;
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Listado');

$sheet->setCellValue('A1', 'sku');
$sheet->setCellValue('B1', 'nombre');
$sheet->setCellValue('C1', 'cantidad');
$sheet->getStyle('A1:C1')->getFont()->setBold(true);

$rowIndex = 2;
foreach ($rows as $r) {
  $sheet->setCellValue("A{$rowIndex}", $r['sku']);
  $sheet->setCellValue("B{$rowIndex}", $r['name']);
  $sheet->setCellValue("C{$rowIndex}", (int)$r['qty']);
  $rowIndex++;
}

foreach (['A', 'B', 'C'] as $column) {
  $sheet->getColumnDimension($column)->setAutoSize(true);
}

$filename = "listado_{$list_id}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
