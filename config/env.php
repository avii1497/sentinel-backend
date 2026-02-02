<?php
$envPaths = [
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
];

$loaded = [];
foreach ($envPaths as $envPath) {
    if (!file_exists($envPath)) {
        continue;
    }
    $realPath = realpath($envPath) ?: $envPath;
    if (isset($loaded[$realPath])) {
        continue;
    }
    $loaded[$realPath] = true;

    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if ($key === '') {
            continue;
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}
