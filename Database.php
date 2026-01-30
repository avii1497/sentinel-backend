<?php
require_once __DIR__ . '/config/config.prod.php';

// <?php
// require_once __DIR__ . '/config/config.php';

/**
 * Database class with PDO connection
 */
class Database {
    private PDO $pdo;

    public function __construct() {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Database connection failed'
            ]);
            exit;
        }
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    /* ========================
          USER FETCH HELPERS
       ======================== */
    public function getUserByEmail(string $email): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE email = :email LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }

    public function getUserById(int $id): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function updateLastLogin(int $userId): void {
        $stmt = $this->pdo->prepare(
            "UPDATE users SET last_login_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    /* ========================
          REGISTRATION
       ======================== */
    public function registerUser(array $user, array $roleData = []): int {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO users
                (first_name, last_name, email, password_hash, role, status, email_verified, created_at)
                VALUES
                (:first_name, :last_name, :email, :password_hash, :role, 1, 0, NOW())
            ");

            $stmt->execute([
                'first_name'    => $user['first_name'],
                'last_name'     => $user['last_name'],
                'email'         => $user['email'],
                'password_hash' => password_hash($user['password'], PASSWORD_DEFAULT),
                'role'          => $user['role'],
            ]);

            $userId = (int)$this->pdo->lastInsertId();

            match ($user['role']) {
                'owner'             => $this->createOwner($userId, $roleData),
                'agent'             => $this->createAgent($userId, $roleData),
                'customer',
                'premium_customer'  => $this->createCustomer($userId, $roleData),
                default             => null
            };

            $this->pdo->commit();
            return $userId;

        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /* ========================
          ROLE HELPERS
       ======================== */
    private function createOwner(int $userId, array $data): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO owners (user_id, company, phone, address, created_at)
            VALUES (:user_id, :company, :phone, :address, NOW())
        ");
        $stmt->execute([
            'user_id' => $userId,
            'company' => $data['company'] ?? null,
            'phone'   => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
        ]);
    }

    private function createAgent(int $userId, array $data): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO agents (user_id, owner_id, license_no, specialization, commission_rate, phone, created_at)
            VALUES (:user_id, :owner_id, :license_no, :specialization, :commission_rate, :phone, NOW())
        ");
        $stmt->execute([
            'user_id'         => $userId,
            'owner_id'        => $data['owner_id'] ?? null,
            'license_no'      => $data['license_no'] ?? null,
            'specialization'  => $data['specialization'] ?? null,
            'commission_rate' => $data['commission_rate'] ?? null,
            'phone'           => $data['phone'] ?? null,
        ]);
    }

    private function createCustomer(int $userId, array $data): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO customers (user_id, preferred_city, budget_min, budget_max, notes, created_at)
            VALUES (:user_id, :preferred_city, :budget_min, :budget_max, :notes, NOW())
        ");
        $stmt->execute([
            'user_id'        => $userId,
            'preferred_city' => $data['preferred_city'] ?? null,
            'budget_min'     => $data['budget_min'] ?? null,
            'budget_max'     => $data['budget_max'] ?? null,
            'notes'          => $data['notes'] ?? null,
        ]);
    }
}

/* ======================
     AUTH / CSRF HELPERS
   ====================== */

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

function requireRole(array|string $roles): void {
    $roles = (array)$roles;
    if (empty($_SESSION['role']) || !in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
}

function issueCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireCsrf(): void {
    $token =
        $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['csrf_token']
        ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        empty($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}
