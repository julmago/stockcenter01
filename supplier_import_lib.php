<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

function supplier_import_normalize_discount($raw): ?string {
  $value = trim((string)$raw);
  if ($value === '') {
    $value = '0';
  }
  if (!preg_match('/^-?\d{1,3}(?:[\.,]\d{1,2})?$/', $value)) {
    return null;
  }
  $normalized = (float)str_replace(',', '.', $value);
  if ($normalized < -100 || $normalized > 100) {
    return null;
  }
  return number_format($normalized, 2, '.', '');
}

function supplier_import_detect_separator(string $line): string {
  $candidates = [',', ';', "\t"];
  $best = ';';
  $bestCount = -1;
  foreach ($candidates as $candidate) {
    $count = substr_count($line, $candidate);
    if ($count > $bestCount) {
      $best = $candidate;
      $bestCount = $count;
    }
  }
  return $best;
}

function supplier_import_normalize_price($raw): ?float {
  $value = trim((string)$raw);
  if ($value === '') {
    return null;
  }
  $value = str_replace(['$', ' '], '', $value);
  $value = preg_replace('/[^0-9,\.\-]/', '', $value) ?? '';
  if ($value === '' || $value === '-' || $value === '.' || $value === ',') {
    return null;
  }

  $lastComma = strrpos($value, ',');
  $lastDot = strrpos($value, '.');
  if ($lastComma !== false && $lastDot !== false) {
    if ($lastComma > $lastDot) {
      $value = str_replace('.', '', $value);
      $value = str_replace(',', '.', $value);
    } else {
      $value = str_replace(',', '', $value);
    }
  } elseif ($lastComma !== false) {
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
  }

  if (!is_numeric($value)) {
    return null;
  }
  return (float)$value;
}

function supplier_import_pick_header(array $headers, array $aliases): ?int {
  foreach ($headers as $idx => $header) {
    if (in_array($header, $aliases, true)) {
      return (int)$idx;
    }
  }
  return null;
}

function supplier_import_normalize_header(string $header): string {
  $header = trim(mb_strtolower($header));
  $header = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $header);
  return preg_replace('/\s+/', '_', $header) ?? $header;
}

function supplier_import_extract_rows(array $rows): array {
  $result = [];
  if (!$rows) {
    return $result;
  }

  $first = $rows[0];
  $normalizedHeaders = array_map(static fn($v) => supplier_import_normalize_header((string)$v), $first);
  $skuIdx = supplier_import_pick_header($normalizedHeaders, ['supplier_sku', 'sku', 'codigo', 'codigo_proveedor', 'cod', 'mpn']);
  $priceIdx = supplier_import_pick_header($normalizedHeaders, ['price', 'precio', 'costo', 'cost', 'importe']);
  $descIdx = supplier_import_pick_header($normalizedHeaders, ['description', 'descripcion', 'nombre', 'detalle']);
  $typeIdx = supplier_import_pick_header($normalizedHeaders, ['cost_type', 'tipo', 'tipo_costo']);
  $unitsIdx = supplier_import_pick_header($normalizedHeaders, ['units_per_pack', 'unidades_pack', 'unidades_por_pack', 'upp']);

  $hasHeader = $skuIdx !== null || $priceIdx !== null;
  $start = $hasHeader ? 1 : 0;

  for ($i = $start; $i < count($rows); $i++) {
    $row = $rows[$i];
    if (!is_array($row) || !$row) {
      continue;
    }
    $supplierSku = trim((string)($row[$skuIdx ?? 0] ?? ''));
    $price = $row[$priceIdx ?? 1] ?? null;
    $description = trim((string)($row[$descIdx ?? 2] ?? ''));
    $costType = trim((string)($row[$typeIdx ?? -1] ?? ''));
    $unitsPerPack = trim((string)($row[$unitsIdx ?? -1] ?? ''));

    if ($supplierSku === '' && trim((string)$price) === '' && $description === '') {
      continue;
    }

    $result[] = [
      'supplier_sku' => $supplierSku,
      'description' => $description,
      'raw_price_input' => $price,
      'raw_cost_type_input' => $costType,
      'raw_units_per_pack_input' => $unitsPerPack,
      'line_number' => $i + 1,
    ];
  }

  return $result;
}

function supplier_import_xlsx_col_to_index(string $ref): int {
  $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
  if ($letters === '') {
    return -1;
  }

  $index = 0;
  for ($i = 0; $i < strlen($letters); $i++) {
    $index = ($index * 26) + (ord($letters[$i]) - 64);
  }

  return $index - 1;
}

function supplier_import_xlsx_load_shared_strings(ZipArchive $zip): array {
  $sharedRaw = $zip->getFromName('xl/sharedStrings.xml');
  if (!is_string($sharedRaw) || trim($sharedRaw) === '') {
    return [];
  }

  $xml = simplexml_load_string($sharedRaw);
  if (!$xml) {
    return [];
  }

  $strings = [];
  foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $si) {
    $parts = [];
    foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $textNode) {
      $parts[] = (string)$textNode;
    }
    $strings[] = implode('', $parts);
  }

  return $strings;
}

function supplier_import_xlsx_sheet_path(ZipArchive $zip): ?string {
  $workbookRaw = $zip->getFromName('xl/workbook.xml');
  $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
  if (!is_string($workbookRaw) || !is_string($relsRaw)) {
    return null;
  }

  $workbookXml = simplexml_load_string($workbookRaw);
  $relsXml = simplexml_load_string($relsRaw);
  if (!$workbookXml || !$relsXml) {
    return null;
  }

  $sheetNodes = $workbookXml->xpath('//*[local-name()="sheet"]') ?: [];
  if (!$sheetNodes) {
    return null;
  }

  $sheetAttrs = $sheetNodes[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
  $rid = (string)($sheetAttrs['id'] ?? '');
  if ($rid === '') {
    return null;
  }

  foreach ($relsXml->xpath('//*[local-name()="Relationship"]') ?: [] as $relNode) {
    $id = (string)($relNode['Id'] ?? '');
    if ($id !== $rid) {
      continue;
    }

    $target = (string)($relNode['Target'] ?? '');
    if ($target === '') {
      return null;
    }

    $target = ltrim($target, '/');
    if (strpos($target, 'xl/') === 0) {
      return $target;
    }
    return 'xl/' . ltrim($target, './');
  }

  return null;
}

function supplier_import_parse_xlsx_basic(string $tmpPath): array {
  if (!class_exists('ZipArchive')) {
    throw new RuntimeException('No se puede leer XLSX: falta extensión ZipArchive.');
  }

  $zip = new ZipArchive();
  if ($zip->open($tmpPath) !== true) {
    throw new RuntimeException('No se pudo abrir el archivo XLSX.');
  }

  try {
    $sheetPath = supplier_import_xlsx_sheet_path($zip);
    if ($sheetPath === null) {
      throw new RuntimeException('No se encontró una hoja válida dentro del XLSX.');
    }

    $sheetRaw = $zip->getFromName($sheetPath);
    if (!is_string($sheetRaw) || trim($sheetRaw) === '') {
      throw new RuntimeException('La hoja del XLSX está vacía o dañada.');
    }

    $sheetXml = simplexml_load_string($sheetRaw);
    if (!$sheetXml) {
      throw new RuntimeException('No se pudo interpretar el contenido del XLSX.');
    }

    $sharedStrings = supplier_import_xlsx_load_shared_strings($zip);
    $rows = [];

    foreach ($sheetXml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowNode) {
      $row = [];
      $fallbackIndex = 0;
      foreach ($rowNode->xpath('./*[local-name()="c"]') ?: [] as $cellNode) {
        $ref = (string)($cellNode['r'] ?? '');
        $idx = supplier_import_xlsx_col_to_index($ref);
        if ($idx < 0) {
          $idx = $fallbackIndex;
        }
        $fallbackIndex = $idx + 1;

        $type = (string)($cellNode['t'] ?? '');
        $value = '';
        if ($type === 'inlineStr') {
          $parts = [];
          foreach ($cellNode->xpath('.//*[local-name()="is"]//*[local-name()="t"]') ?: [] as $textNode) {
            $parts[] = (string)$textNode;
          }
          $value = implode('', $parts);
        } else {
          $value = (string)($cellNode->v ?? '');
          if ($type === 's') {
            $sharedIndex = (int)$value;
            $value = (string)($sharedStrings[$sharedIndex] ?? '');
          }
        }

        $row[$idx] = $value;
      }

      if ($row) {
        ksort($row);
        $normalized = [];
        $maxIndex = (int)max(array_keys($row));
        for ($i = 0; $i <= $maxIndex; $i++) {
          $normalized[] = $row[$i] ?? '';
        }
        $rows[] = $normalized;
      }
    }

    return $rows;
  } finally {
    $zip->close();
  }
}

function supplier_import_parse_input(string $sourceType, string $tmpPath, string $pasteText): array {
  $sourceType = strtoupper($sourceType);
  $rows = [];

  if ($sourceType === 'CSV') {
    $content = file_get_contents($tmpPath);
    if ($content === false) {
      return [];
    }
    $lines = preg_split('/\R/u', $content) ?: [];
    $sample = '';
    foreach ($lines as $line) {
      if (trim($line) !== '') {
        $sample = $line;
        break;
      }
    }
    $sep = supplier_import_detect_separator($sample);
    $fh = fopen($tmpPath, 'r');
    if (!$fh) {
      return [];
    }
    while (($data = fgetcsv($fh, 0, $sep)) !== false) {
      $rows[] = $data;
    }
    fclose($fh);
    return supplier_import_extract_rows($rows);
  }

  if ($sourceType === 'XLSX') {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
      require_once $autoload;
      $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
      $sheet = $spreadsheet->getSheet(0);
      foreach ($sheet->toArray(null, true, true, false) as $row) {
        $rows[] = $row;
      }
      return supplier_import_extract_rows($rows);
    }

    $rows = supplier_import_parse_xlsx_basic($tmpPath);
    return supplier_import_extract_rows($rows);
  }

  if ($sourceType === 'TXT' || $sourceType === 'PASTE') {
    $content = $sourceType === 'TXT' ? (string)file_get_contents($tmpPath) : $pasteText;
    $lines = preg_split('/\R/u', $content) ?: [];
    $lineNo = 0;
    foreach ($lines as $line) {
      $lineNo++;
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $parts = preg_split('/\t+/', $line) ?: [];
      if (count($parts) < 2) {
        $parts = preg_split('/\s{2,}/', $line) ?: [];
      }
      if (count($parts) < 2) {
        if (preg_match('/^(\S+)\s+([\$\d\.,]+)\s*(.*)$/u', $line, $m)) {
          $parts = [$m[1], $m[2], trim((string)($m[3] ?? ''))];
        }
      }
      if (count($parts) < 2) {
        $parts = preg_split('/\s+/', $line, 3) ?: [];
      }
      $rows[] = [
        'supplier_sku' => trim((string)($parts[0] ?? '')),
        'description' => trim((string)($parts[2] ?? '')),
        'raw_price_input' => $parts[1] ?? null,
        'raw_cost_type_input' => '',
        'raw_units_per_pack_input' => '',
        'line_number' => $lineNo,
      ];
    }
    return $rows;
  }

  if ($sourceType === 'PDF') {
    throw new RuntimeException('PDF requiere conversión previa a texto/CSV.');
  }

  return [];
}

function supplier_import_build_run(int $supplierId, array $supplier, array $payload): int {
  $pdo = db();
  $sourceType = strtoupper((string)$payload['source_type']);
  $extraDiscount = supplier_import_normalize_discount($payload['extra_discount_percent'] ?? '0');
  if ($extraDiscount === null) {
    throw new RuntimeException('Descuento extra inválido.');
  }

  $defaultCostType = (string)($payload['default_cost_type'] ?? ($supplier['import_default_cost_type'] ?? 'UNIDAD'));
  if (!in_array($defaultCostType, ['UNIDAD', 'PACK'], true)) {
    $defaultCostType = 'UNIDAD';
  }

  $defaultUnits = trim((string)($payload['default_units_per_pack'] ?? ($supplier['import_default_units_per_pack'] ?? '')));
  $defaultUnitsValue = $defaultUnits === '' ? null : (int)$defaultUnits;
  if ($defaultUnitsValue !== null && $defaultUnitsValue <= 0) {
    $defaultUnitsValue = null;
  }

  $rows = supplier_import_parse_input($sourceType, (string)($payload['tmp_name'] ?? ''), (string)($payload['paste_text'] ?? ''));
  if (!$rows) {
    throw new RuntimeException('No se encontraron filas válidas para importar.');
  }

  $filename = trim((string)($payload['filename'] ?? ''));
  if ($filename === '') {
    $filename = null;
  }

  $createdBy = (int)(current_user()['id'] ?? 0);
  if ($createdBy <= 0) {
    $createdBy = null;
  }

  $stRun = $pdo->prepare('INSERT INTO supplier_import_runs(supplier_id, filename, source_type, extra_discount_percent, created_by, notes) VALUES(?, ?, ?, ?, ?, ?)');
  $stRun->execute([$supplierId, $filename, $sourceType, $extraDiscount, $createdBy, null]);
  $runId = (int)$pdo->lastInsertId();

  $stMatch = $pdo->prepare('SELECT id, product_id FROM product_suppliers WHERE supplier_id = ? AND supplier_sku = ? LIMIT 1');

  $prepared = [];
  foreach ($rows as $row) {
    $supplierSku = trim((string)($row['supplier_sku'] ?? ''));
    $description = trim((string)($row['description'] ?? ''));
    $rawPrice = supplier_import_normalize_price($row['raw_price_input'] ?? null);

    $rawCostType = strtoupper(trim((string)($row['raw_cost_type_input'] ?? '')));
    if (!in_array($rawCostType, ['UNIDAD', 'PACK'], true)) {
      $rawCostType = $defaultCostType;
    }

    $unitsInput = trim((string)($row['raw_units_per_pack_input'] ?? ''));
    $rawUnitsPerPack = $unitsInput === '' ? null : (int)$unitsInput;
    if ($rawUnitsPerPack !== null && $rawUnitsPerPack <= 0) {
      $rawUnitsPerPack = null;
    }

    if ($rawCostType === 'PACK' && $rawUnitsPerPack === null) {
      $rawUnitsPerPack = $defaultUnitsValue;
    }

    $status = 'UNMATCHED';
    $reason = null;
    $normalizedUnitCost = null;
    $matchedProductSupplierId = null;
    $matchedProductId = null;

    if ($supplierSku === '') {
      $status = 'INVALID';
      $reason = 'SKU vacío';
    } elseif ($rawPrice === null || $rawPrice < 0) {
      $status = 'INVALID';
      $reason = 'Precio inválido';
    } else {
      $afterDiscount = $rawPrice * (1 - ((float)$extraDiscount / 100));
      if ($rawCostType === 'PACK') {
        if ($rawUnitsPerPack === null || $rawUnitsPerPack <= 0) {
          $status = 'INVALID';
          $reason = 'PACK sin units_per_pack válido';
        } else {
          $normalizedUnitCost = (float)round($afterDiscount / $rawUnitsPerPack, 0);
        }
      } else {
        $normalizedUnitCost = (float)round($afterDiscount, 0);
      }

      if ($status !== 'INVALID') {
        $stMatch->execute([$supplierId, $supplierSku]);
        $match = $stMatch->fetch();
        if ($match) {
          $status = 'MATCHED';
          $matchedProductSupplierId = (int)$match['id'];
          $matchedProductId = (int)$match['product_id'];
        } else {
          $status = 'UNMATCHED';
          $reason = 'No vinculado por supplier_sku';
        }
      }
    }

    $prepared[] = [
      'supplier_sku' => $supplierSku,
      'description' => $description,
      'raw_price' => $rawPrice,
      'raw_cost_type' => $rawCostType,
      'raw_units_per_pack' => $rawUnitsPerPack,
      'normalized_unit_cost' => $normalizedUnitCost,
      'matched_product_supplier_id' => $matchedProductSupplierId,
      'matched_product_id' => $matchedProductId,
      'status' => $status,
      'chosen_by_rule' => 0,
      'reason' => $reason,
      'line_number' => (int)($row['line_number'] ?? 0),
    ];
  }

  $groups = [];
  foreach ($prepared as $idx => $row) {
    $sku = $row['supplier_sku'];
    if ($sku === '' || $row['status'] === 'INVALID') {
      continue;
    }
    $groups[$sku][] = $idx;
  }

  $dedupeMode = (string)($supplier['import_dedupe_mode'] ?? 'LAST');
  foreach ($groups as $sku => $indexes) {
    $validIndexes = array_values(array_filter($indexes, static function ($idx) use ($prepared) {
      return $prepared[$idx]['status'] !== 'INVALID';
    }));
    if (!$validIndexes) {
      continue;
    }

    $chosen = $validIndexes[0];
    if (count($validIndexes) > 1) {
      if ($dedupeMode === 'LAST') {
        $chosen = $validIndexes[count($validIndexes) - 1];
      } elseif ($dedupeMode === 'FIRST') {
        $chosen = $validIndexes[0];
      } elseif ($dedupeMode === 'MIN' || $dedupeMode === 'MAX') {
        $chosen = $validIndexes[0];
        foreach ($validIndexes as $candidate) {
          $candCost = (float)($prepared[$candidate]['normalized_unit_cost'] ?? 0);
          $bestCost = (float)($prepared[$chosen]['normalized_unit_cost'] ?? 0);
          if (($dedupeMode === 'MIN' && $candCost < $bestCost) || ($dedupeMode === 'MAX' && $candCost > $bestCost)) {
            $chosen = $candidate;
          }
        }
      } elseif ($dedupeMode === 'PREFER_PROMO') {
        $promoCandidate = null;
        foreach ($validIndexes as $candidate) {
          $desc = mb_strtolower((string)$prepared[$candidate]['description']);
          if (preg_match('/\b(promo|oferta|dto)\b/u', $desc)) {
            $promoCandidate = $candidate;
          }
        }
        $chosen = $promoCandidate ?? $validIndexes[count($validIndexes) - 1];
      }
    }

    foreach ($validIndexes as $candidate) {
      if ($candidate === $chosen) {
        $prepared[$candidate]['chosen_by_rule'] = 1;
        $prepared[$candidate]['reason'] = 'dedupe_mode=' . $dedupeMode;
      } else {
        $prepared[$candidate]['status'] = 'DUPLICATE_SKU';
        $prepared[$candidate]['chosen_by_rule'] = 0;
        $prepared[$candidate]['reason'] = 'dedupe_mode=' . $dedupeMode;
      }
    }
  }

  $stRow = $pdo->prepare('INSERT INTO supplier_import_rows(run_id, supplier_sku, description, raw_price, raw_cost_type, raw_units_per_pack, normalized_unit_cost, matched_product_supplier_id, matched_product_id, status, chosen_by_rule, reason) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
  foreach ($prepared as $row) {
    $stRow->execute([
      $runId,
      $row['supplier_sku'],
      $row['description'] !== '' ? $row['description'] : null,
      $row['raw_price'],
      $row['raw_cost_type'],
      $row['raw_units_per_pack'],
      $row['normalized_unit_cost'],
      $row['matched_product_supplier_id'],
      $row['matched_product_id'],
      $row['status'],
      $row['chosen_by_rule'],
      $row['reason'],
    ]);
  }

  return $runId;
}
