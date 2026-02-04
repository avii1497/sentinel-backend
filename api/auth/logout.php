<?php
require_once __DIR__ . '/../cors.php';
// Destroy the PHP session properly
$_SESSION = [];
clearSessionCookie();
session_destroy();

header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Logout successful']);
