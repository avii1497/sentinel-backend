<?php
// 🔴 GLOBAL CORS — runs BEFORE routing
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin === 'https://relaxed-tartufo-866a0b.netlify.app') {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-XSRF-TOKEN, Accept");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 🔽 Let normal routing continue
require __DIR__ . '/router.php'; // or login.php dispatcher
