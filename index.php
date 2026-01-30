<?php
require_once __DIR__ . '/bootstrap.php';
if (!empty($_SESSION['user'])) {
  header("Location: dashboard.php");
} else {
  header("Location: login.php");
}
exit;
