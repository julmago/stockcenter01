<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';

function cashbox_selected_id(): int {
  return (int)($_SESSION['cashbox_id'] ?? 0);
}

function fetch_cashbox_by_id(int $cashbox_id, bool $only_active = true): ?array {
  if ($cashbox_id <= 0) {
    return null;
  }
  $sql = "SELECT id, name, is_active, created_by_user_id, created_at FROM cashboxes WHERE id = ?";
  if ($only_active) {
    $sql .= " AND is_active = 1";
  }
  $sql .= " LIMIT 1";
  $st = db()->prepare($sql);
  $st->execute([$cashbox_id]);
  $row = $st->fetch();
  return $row ?: null;
}

function fetch_active_cashboxes(): array {
  $st = db()->query("SELECT id, name FROM cashboxes WHERE is_active = 1 ORDER BY name ASC");
  return $st->fetchAll();
}

function require_cashbox_selected(bool $only_active = true): array {
  $cashbox_id = cashbox_selected_id();
  if ($cashbox_id <= 0) {
    redirect('cash/cash_select.php');
  }
  $cashbox = fetch_cashbox_by_id($cashbox_id, $only_active);
  if (!$cashbox) {
    unset($_SESSION['cashbox_id']);
    redirect('cash/cash_select.php');
  }
  return $cashbox;
}
