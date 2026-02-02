<?php
require_once __DIR__ . '/env.php';

return [
  "host" => $_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?? '',
  "port" => (int)($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?? 587),
  "secure" => $_ENV['SMTP_SECURE'] ?? getenv('SMTP_SECURE') ?? 'tls', // tls
  "username" => $_ENV['SMTP_USERNAME'] ?? getenv('SMTP_USERNAME') ?? '',
  "password" => $_ENV['SMTP_PASSWORD'] ?? getenv('SMTP_PASSWORD') ?? '',
  "from_email" => $_ENV['SMTP_FROM_EMAIL'] ?? getenv('SMTP_FROM_EMAIL') ?? '',
  "from_name" => $_ENV['SMTP_FROM_NAME'] ?? getenv('SMTP_FROM_NAME') ?? ''
];
