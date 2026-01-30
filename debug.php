<?php
require_once __DIR__ . '/config/config.php';
header('Content-Type: application/json');

echo json_encode([
  'http_host' => $_SERVER['HTTP_HOST'] ?? null,
  'server_name' => $_SERVER['SERVER_NAME'] ?? null,
  'db_host' => defined('DB_HOST') ? DB_HOST : null,
  'db_name' => defined('DB_NAME') ? DB_NAME : null,
]);
