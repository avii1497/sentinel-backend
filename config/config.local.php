<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/env.php';

// Database (local)
define('DB_HOST', $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? '');
define('DB_PORT', $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306');
define('DB_NAME', $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? '');
define('DB_USER', $_ENV['DB_USER'] ?? getenv('DB_USER') ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? getenv('DB_CHARSET') ?? 'utf8mb4');
