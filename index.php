<?php
require_once __DIR__ . '/bootstrap.php';
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user'])) {
  header("Location: dashboard.php");
} elseif (!empty($_SESSION['gateway_ok'])) {
  header("Location: select_profile.php");
} else {
  header("Location: login.php");
}
exit;
