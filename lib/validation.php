<?php

function bad_request(string $message): void {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $message,
        'message' => $message,
    ]);
    exit;
}

function sanitize_array(array $data): array {
    foreach ($data as $key => $value) {
        if (is_string($value)) {
            $data[$key] = trim($value);
        } elseif (is_array($value)) {
            $data[$key] = sanitize_array($value);
        }
    }
    return $data;
}

function v_string($value, string $field, int $max, int $min = 1, bool $required = true): ?string {
    if ($value === null) {
        if ($required) {
            bad_request(ucfirst($field) . ' is required.');
        }
        return null;
    }
    $value = trim((string)$value);
    if ($required && $value === '') {
        bad_request(ucfirst($field) . ' is required.');
    }
    $len = strlen($value);
    if ($len > $max) {
        bad_request(ucfirst($field) . " must be at most {$max} characters.");
    }
    if ($required && $len < $min) {
        bad_request(ucfirst($field) . " must be at least {$min} characters.");
    }
    return $value;
}

function v_email($value, string $field = 'email', bool $required = true, int $max = 254): ?string {
    $email = v_string($value, $field, $max, 1, $required);
    if ($email === null) {
        return null;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        bad_request('Invalid email format.');
    }
    return strtolower($email);
}

function v_phone($value, string $field = 'phone', bool $required = true, int $max = 30): ?string {
    $phone = v_string($value, $field, $max, 7, $required);
    if ($phone === null) {
        return null;
    }
    if (!preg_match('/^[0-9+\\-().\\s]{7,30}$/', $phone)) {
        bad_request('Invalid phone format.');
    }
    return $phone;
}

function v_int($value, string $field, int $min = 1, int $max = 2147483647, bool $required = true): ?int {
    if ($value === null || $value === '') {
        if ($required) {
            bad_request(ucfirst($field) . ' is required.');
        }
        return null;
    }
    if (is_int($value)) {
        // ok
    } elseif (is_string($value) && preg_match('/^-?\\d+$/', $value)) {
        $value = (int)$value;
    } elseif (is_numeric($value) && (int)$value == $value) {
        $value = (int)$value;
    } else {
        bad_request(ucfirst($field) . ' must be an integer.');
    }
    if ($value < $min || $value > $max) {
        bad_request(ucfirst($field) . " must be between {$min} and {$max}.");
    }
    return (int)$value;
}

function v_float($value, string $field, float $min = 0.0, float $max = 1000000000.0, bool $required = true): ?float {
    if ($value === null || $value === '') {
        if ($required) {
            bad_request(ucfirst($field) . ' is required.');
        }
        return null;
    }
    if (!is_numeric($value)) {
        bad_request(ucfirst($field) . ' must be a number.');
    }
    $value = (float)$value;
    if ($value < $min || $value > $max) {
        bad_request(ucfirst($field) . " must be between {$min} and {$max}.");
    }
    return $value;
}

function v_bool($value, string $field, bool $required = true): ?int {
    if ($value === null || $value === '') {
        if ($required) {
            bad_request(ucfirst($field) . ' is required.');
        }
        return null;
    }
    $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($bool === null) {
        bad_request(ucfirst($field) . ' must be a boolean.');
    }
    return $bool ? 1 : 0;
}

function v_enum($value, string $field, array $allowed, bool $required = true): ?string {
    $val = v_string($value, $field, 100, 1, $required);
    if ($val === null) {
        return null;
    }
    if (!in_array($val, $allowed, true)) {
        bad_request(ucfirst($field) . ' is invalid.');
    }
    return $val;
}

function v_date($value, string $field, bool $required = true): ?string {
    $val = v_string($value, $field, 10, 1, $required);
    if ($val === null) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $val);
    if (!$dt || $dt->format('Y-m-d') !== $val) {
        bad_request(ucfirst($field) . ' must be in YYYY-MM-DD format.');
    }
    return $val;
}

function v_time($value, string $field, bool $required = true): ?string {
    $val = v_string($value, $field, 8, 1, $required);
    if ($val === null) {
        return null;
    }
    $formats = ['H:i', 'H:i:s'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $val);
        if ($dt && $dt->format($fmt) === $val) {
            return $val;
        }
    }
    bad_request(ucfirst($field) . ' must be a valid time (HH:MM or HH:MM:SS).');
    return null;
}

function v_datetime($value, string $field, bool $required = true): ?string {
    $val = v_string($value, $field, 25, 1, $required);
    if ($val === null) {
        return null;
    }
    $formats = ['Y-m-d H:i:s', 'Y-m-d\\TH:i', 'Y-m-d\\TH:i:s'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $val);
        if ($dt && $dt->format($fmt) === $val) {
            return $val;
        }
    }
    bad_request(ucfirst($field) . ' must be a valid datetime.');
    return null;
}

function v_int_list($value, string $field, int $min = 1, int $max = 2147483647, bool $required = true): ?array {
    if ($value === null) {
        if ($required) {
            bad_request(ucfirst($field) . ' is required.');
        }
        return null;
    }
    if (!is_array($value)) {
        bad_request(ucfirst($field) . ' must be an array.');
    }
    $out = [];
    foreach ($value as $item) {
        $out[] = v_int($item, $field, $min, $max, true);
    }
    if ($required && !$out) {
        bad_request(ucfirst($field) . ' is required.');
    }
    return $out;
}
