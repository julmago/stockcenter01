<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_login();
require_permission(can_import_csv());

$error = '';
$message = '';
$errors = [];
$summary = [
  'created' => 0,
  'updated' => 0,
  'imported' => 0,
  'skipped' => 0,
];

$expected_headers = ['SKU', 'TITULO', 'MARCA', 'MPN', 'BARRA'];

if (is_post()) {
  if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $error = 'Subí un archivo CSV válido.';
  } else {
    $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$fh) {
      $error = 'No se pudo leer el archivo.';
    } else {
      $headers = fgetcsv($fh, 0, ';');
      if (!$headers) {
        $error = 'El CSV está vacío.';
      } else {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        $normalized = array_map(function ($h) {
          return strtoupper(trim((string)$h));
        }, $headers);
        if (array_slice($normalized, 0, count($expected_headers)) !== $expected_headers) {
          $error = 'Encabezados inválidos. Deben ser: SKU;TITULO;MARCA;MPN;BARRA';
        } else {
          $line = 1;
          while (($row = fgetcsv($fh, 0, ';')) !== false) {
            $line++;
            if (!$row || (count($row) === 1 && trim((string)$row[0]) === '')) {
              continue;
            }
            $row = array_pad($row, 5, '');
            $sku = trim((string)$row[0]);
            $title = trim((string)$row[1]);
            $brand = trim((string)$row[2]);
            $mpn = trim((string)$row[3]);
            $barra = trim((string)$row[4]);

            if ($sku === '') {
              $errors[] = "Línea {$line}: SKU vacío.";
              $summary['skipped']++;
              continue;
            }
            if ($title === '') {
              $errors[] = "Línea {$line}: TITULO vacío.";
              $summary['skipped']++;
              continue;
            }

            $codes = [];
            if ($barra !== '') $codes[] = ['code' => $barra, 'type' => 'BARRA'];
            if ($mpn !== '') $codes[] = ['code' => $mpn, 'type' => 'MPN'];

            try {
              db()->beginTransaction();
              $st = db()->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
              $st->execute([$sku]);
              $existing = $st->fetch();
              $product_id = $existing ? (int)$existing['id'] : 0;

              $conflict_code = '';
              foreach ($codes as $code) {
                $st = db()->prepare("SELECT product_id FROM product_codes WHERE LOWER(code) = LOWER(?) LIMIT 1");
                $st->execute([$code['code']]);
                $code_row = $st->fetch();
                if ($code_row && (int)$code_row['product_id'] !== $product_id) {
                  $conflict_code = $code['code'];
                  break;
                }
              }

              if ($conflict_code !== '') {
                db()->rollBack();
                $errors[] = "Línea {$line}: el código {$conflict_code} ya está asociado a otro producto.";
                $summary['skipped']++;
                continue;
              }

              if ($product_id > 0) {
                $st = db()->prepare("UPDATE products SET name = ?, brand = ?, updated_at = NOW() WHERE id = ?");
                $st->execute([$title, $brand, $product_id]);
                $summary['updated']++;
              } else {
                $st = db()->prepare("INSERT INTO products(sku, name, brand, updated_at) VALUES(?, ?, ?, NOW())");
                $st->execute([$sku, $title, $brand]);
                $product_id = (int)db()->lastInsertId();
                $summary['created']++;
              }

              foreach ($codes as $code) {
                $st = db()->prepare("SELECT id FROM product_codes WHERE product_id = ? AND LOWER(code) = LOWER(?) LIMIT 1");
                $st->execute([$product_id, $code['code']]);
                $existing_code = $st->fetch();
                if (!$existing_code) {
                  $st = db()->prepare("INSERT INTO product_codes(product_id, code, code_type) VALUES(?, ?, ?)");
                  $st->execute([$product_id, $code['code'], $code['type']]);
                }
              }

              db()->commit();
              $summary['imported']++;
            } catch (Throwable $t) {
              if (db()->inTransaction()) db()->rollBack();
              $errors[] = "Línea {$line}: error al importar.";
              $summary['skipped']++;
            }
          }

          $message = "Importación finalizada. Filas importadas: {$summary['imported']}. Nuevos: {$summary['created']}. Actualizados: {$summary['updated']}. Omitidos: {$summary['skipped']}.";
        }
      }
      fclose($fh);
    }
  }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Importar CSV</title></head>
<body>
<?php require __DIR__ . '/_header.php'; ?>

<div style="padding:10px;">
  <h2>Importar productos (CSV)</h2>

  <?php if ($message): ?><p style="color:green;"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p style="color:red;"><?= e($error) ?></p><?php endif; ?>

  <div style="border:1px solid #ccc;padding:10px;margin-bottom:12px;">
    <p><strong>Formato requerido</strong></p>
    <ul>
      <li>Separador: <code>;</code></li>
      <li>Charset: UTF-8</li>
      <li>Encabezados: <code>SKU;TITULO;MARCA;MPN;BARRA</code></li>
    </ul>
    <p>Reglas:</p>
    <ul>
      <li>SKU es único y obligatorio.</li>
      <li>BARRA y MPN se guardan como códigos del producto.</li>
      <li>Si un código ya está asociado a otro producto, esa fila se omite y se registra el error.</li>
    </ul>
  </div>

  <form method="post" enctype="multipart/form-data">
    <div>
      <input type="file" name="csv_file" accept=".csv,text/csv" required>
    </div>
    <div style="margin-top:10px;">
      <button type="submit">Importar</button>
      <a href="product_list.php">Volver</a>
    </div>
  </form>

  <?php if ($errors): ?>
    <div style="margin-top:16px;">
      <h3>Errores</h3>
      <ul>
        <?php foreach ($errors as $row_error): ?>
          <li><?= e($row_error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
