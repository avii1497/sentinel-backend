<?php
// Normalize host detection across different PHP/FastCGI setups.
$host = $_SERVER['HTTP_HOST']
    ?? ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
$host = strtolower($host);

// Detect production (InfinityFree domain)
if (str_contains($host, 'rf.gd') || str_contains($host, 'infinityfreeapp.com')) {
    require_once __DIR__ . '/config.prod.php';
} else {
    require_once __DIR__ . '/config.local.php';
}
