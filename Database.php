<?php
require_once __DIR__ . '/config/config.php';

/**
 * Database class with PDO connection
 * Centralized DB logic and helper methods for user roles, linking, etc.
 */
class Database {
  private PDO $pdo;

  public function __construct() {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
      $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
      exit;
    }
  }

  /** ✅ External PDO Accessor */
  public function getPdo(): PDO {
    return $this->pdo;
  }

  /* ========================
        USER FETCH HELPERS
     ======================== */
  public function getUserByEmail(string $email): ?array {
    $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    return $stmt->fetch() ?: null;
  }

  public function getUserById(int $id): ?array {
    $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
  }

  public function updateLastLogin(int $userId): void {
    $stmt = $this->pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $userId]);
  }

  public function logUserLogin(int $userId, ?string $ipAddress, ?string $userAgent): void {
    $stmt = $this->pdo->prepare("
      INSERT INTO user_login_audit (user_id, ip_address, user_agent, logged_in_at)
      VALUES (:user_id, :ip_address, :user_agent, NOW())
    ");
    $stmt->execute([
      ':user_id' => $userId,
      ':ip_address' => $ipAddress,
      ':user_agent' => $userAgent,
    ]);
  }

  /* ========================
        REGISTRATION
     ======================== */
  public function registerUser(array $user, array $roleData = []): int {
    $this->pdo->beginTransaction();
    try {
      $sql = "INSERT INTO users (first_name, last_name, email, password_hash, role, status, email_verified, created_at)
              VALUES (:first_name, :last_name, :email, :password_hash, :role, 0, 0, NOW())";
      $stmt = $this->pdo->prepare($sql);

      $passwordHash = password_hash($user['password'], PASSWORD_DEFAULT);
      $stmt->execute([
        ':first_name'    => $user['first_name'],
        ':last_name'     => $user['last_name'],
        ':email'         => $user['email'],
        ':password_hash' => $passwordHash,
        ':role'          => $user['role'],
      ]);

      $userId = (int)$this->pdo->lastInsertId();

      switch ($user['role']) {
        case 'owner': $this->createOwner($userId, $roleData); break;
        case 'agent': $this->createAgent($userId, $roleData); break;
        case 'customer':
        case 'premium_customer': $this->createCustomer($userId, $roleData); break;
      }

      $this->pdo->commit();
      return $userId;

    } catch (PDOException $e) {
      $this->pdo->rollBack();
      if ($e->getCode() === '23000') {
        throw new RuntimeException('Email already exists.');
      }
      throw $e;
    }
  }

  /* ========================
        ROLE HELPERS
     ======================== */
  public function createOwner(int $userId, array $data = []): int {
    $stmt = $this->pdo->prepare("
      INSERT INTO owners (user_id, company, phone, address, created_at)
      VALUES (:user_id, :company, :phone, :address, NOW())
    ");
    $stmt->execute([
      ':user_id' => $userId,
      ':company' => $data['company'] ?? null,
      ':phone'   => $data['phone'] ?? null,
      ':address' => $data['address'] ?? null,
    ]);
    return (int)$this->pdo->lastInsertId();
  }

  public function createAgent(int $userId, array $data = []): int {
    $stmt = $this->pdo->prepare("
      INSERT INTO agents (user_id, owner_id, license_no, specialization, commission_rate, phone, created_at)
      VALUES (:user_id, :owner_id, :license_no, :specialization, :commission_rate, :phone, NOW())
    ");
    $stmt->execute([
      ':user_id'         => $userId,
      ':owner_id'        => $data['owner_id'] ?? null,
      ':license_no'      => $data['license_no'] ?? null,
      ':specialization'  => $data['specialization'] ?? null,
      ':commission_rate' => $data['commission_rate'] ?? null,
      ':phone'           => $data['phone'] ?? null,
    ]);
    return (int)$this->pdo->lastInsertId();
  }

  public function createCustomer(int $userId, array $data = []): int {
    $stmt = $this->pdo->prepare("
      INSERT INTO customers (user_id, preferred_city, budget_min, budget_max, notes, created_at)
      VALUES (:user_id, :preferred_city, :budget_min, :budget_max, :notes, NOW())
    ");
    $stmt->execute([
      ':user_id'        => $userId,
      ':preferred_city' => $data['preferred_city'] ?? null,
      ':budget_min'     => $data['budget_min'] ?? null,
      ':budget_max'     => $data['budget_max'] ?? null,
      ':notes'          => $data['notes'] ?? null,
    ]);
    return (int)$this->pdo->lastInsertId();
  }

  /* ========================
        LOGIN LOGIC
     ======================== */
  public function loginUser(string $email, string $password): array {
    $user = $this->getUserByEmail($email);
    if (!$user || !password_verify($password, $user['password_hash'])) {
      throw new RuntimeException('Invalid credentials.');
    }

    if ((int)($user['status'] ?? 0) !== 1) {
      throw new RuntimeException('Account inactive. Contact support.');
    }

    $this->updateLastLogin((int)$user['id']);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

    return [
      'user' => [
        'id'    => (int)$user['id'],
        'name'  => $_SESSION['name'],
        'email' => $user['email'],
        'role'  => $user['role']
      ],
      'redirect' => match ($user['role']) {
        'owner'            => '/dashboard/owner',
        'agent'            => '/dashboard/agent',
        'customer', 
        'premium_customer' => '/interface/client',
        default            => '/dashboard'
      }
    ];
  }

  /* ========================
        PROFILE UPDATE
     ======================== */
  public function updateUserProfile(int $userId, array $fields): bool {
    $allowed = ['first_name', 'last_name', 'email', 'phone', 'status', 'email_verified'];
    $set = [];
    $params = [':id' => $userId];

    foreach ($fields as $col => $val) {
      if (in_array($col, $allowed, true)) {
        $set[] = "`$col` = :$col";
        $params[":$col"] = $val;
      }
    }

    if (!$set) return false;

    $sql = "UPDATE users SET " . implode(', ', $set) . " WHERE id = :id";
    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute($params);
  }

  /* ========================
        AGENT ↔ OWNER LINKING
     ======================== */
  public function assignAgentToOwner(int $ownerId, int $agentId): bool {
    $stmt = $this->pdo->prepare("
      UPDATE owners SET assigned_agent_id = :agent_id WHERE id = :owner_id
    ");
    $stmt->execute([':agent_id' => $agentId, ':owner_id' => $ownerId]);
    return $stmt->rowCount() > 0;
  }

  public function getAgentByOwner(int $ownerId): ?array {
    $sql = "
      SELECT 
        a.id AS agent_id,
        au.first_name AS agent_first_name,
        au.last_name AS agent_last_name,
        au.email AS agent_email,
        a.phone AS agent_phone,
        a.license_no,
        a.specialization,
        a.commission_rate,
        o.id AS owner_id,
        ou.first_name AS owner_first_name,
        ou.last_name AS owner_last_name,
        ou.email AS owner_email
      FROM owners o
      LEFT JOIN agents a ON o.assigned_agent_id = a.id
      LEFT JOIN users au ON a.user_id = au.id
      LEFT JOIN users ou ON o.user_id = ou.id
      WHERE o.id = :owner_id
      LIMIT 1
    ";
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([':owner_id' => $ownerId]);
    return $stmt->fetch() ?: null;
  }
}

/* ======================
     AUTH GUARDS
   ====================== */
function requireLogin(): void {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
  }
}

function requireRole(array|string $allowed): void {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (empty($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
  }
  $allowed = (array)$allowed;
  if (!in_array($_SESSION['role'], $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
  }
}

function issueCsrfToken(): string {
  if (session_status() === PHP_SESSION_NONE) session_start();
  if (!empty($_SESSION['csrf_token'])) {
    return (string)$_SESSION['csrf_token'];
  }
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf_token'];
}


function requireCsrf(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $sessionToken = $_SESSION['csrf_token'] ?? '';

    // 1️⃣ Header token (preferred)
    $headerToken =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_SERVER['HTTP_X_XSRF_TOKEN']
        ?? '';

    // 2️⃣ FormData token
    $postToken = $_POST['csrf_token'] ?? '';

    // 3️⃣ JSON body token (fallback)
    $jsonToken = '';
    if (
        $headerToken === '' &&
        $postToken === '' &&
        isset($_SERVER['CONTENT_TYPE']) &&
        str_contains($_SERVER['CONTENT_TYPE'], 'application/json')
    ) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $jsonToken = $json['csrf_token'] ?? '';
        }
    }

    // Pick the first available token
    $token = $headerToken ?: $postToken ?: $jsonToken;

    if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid CSRF token'
        ]);
        exit;
    }
}


function validateUpload(array $file, array $allowedExt, array $allowedMime, int $maxBytes): array {
  if (!isset($file['error']) || is_array($file['error'])) {
    throw new RuntimeException('Invalid upload parameters.');
  }

  switch ($file['error']) {
    case UPLOAD_ERR_OK:
      break;
    case UPLOAD_ERR_NO_FILE:
      throw new RuntimeException('No file uploaded.');
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
      throw new RuntimeException('Uploaded file is too large.');
    default:
      throw new RuntimeException('File upload failed.');
  }

  if (!isset($file['size']) || $file['size'] > $maxBytes) {
    throw new RuntimeException('Uploaded file is too large.');
  }

  $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
  if ($ext === '' || !in_array($ext, $allowedExt, true)) {
    throw new RuntimeException('Unsupported file extension.');
  }

  $mime = '';
  if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
  } else {
    $mime = (string)($file['type'] ?? '');
  }

  if ($mime === '' || !in_array($mime, $allowedMime, true)) {
    throw new RuntimeException('Unsupported file type.');
  }

  return ['ext' => $ext, 'mime' => $mime];
}

/* ✅ Safe session start */
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
?>
