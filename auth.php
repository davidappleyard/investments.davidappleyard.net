<?php
// ---- Load credentials from .env ----
require_once __DIR__ . '/load_env.php';
load_env();

// ---- Harden session cookies before session_start() ----
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();

// ---- Security headers (applied to every page that includes auth.php) ----
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");

// ---- Database connection ----
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ---- CSRF protection ----
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** Returns an HTML hidden input containing the CSRF token. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}

/** Validates CSRF token on POST requests; terminates with 403 if invalid. */
function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!validate_csrf_token($token)) {
            http_response_code(403);
            die('Request validation failed. Please go back and try again.');
        }
    }
}

// ---- Table creation ----
function create_users_table(): bool {
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            must_change_password BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            is_active BOOLEAN DEFAULT TRUE
        )");
        // Add must_change_password column to existing installs that predate this column
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password BOOLEAN DEFAULT FALSE");
        } catch (Exception $e) {
            // Column already exists — ignore
        }
    } catch (Exception $e) {
        return false;
    }
    return true;
}

function create_remember_tokens_table(): bool {
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) UNIQUE NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
    } catch (Exception $e) {
        return false;
    }
    return true;
}

function create_login_attempts_table(): bool {
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            success BOOLEAN DEFAULT FALSE,
            INDEX idx_ip_time (ip_address, attempted_at)
        )");
    } catch (Exception $e) {
        return false;
    }
    return true;
}

create_users_table();
create_remember_tokens_table();
create_login_attempts_table();

// ---- Rate limiting helpers ----
function get_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function is_rate_limited(string $ip): bool {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip_address = ? AND success = 0
              AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Exception $e) {
        return false; // fail open — don't block everyone if DB is down
    }
}

function record_login_attempt(string $ip, bool $success): void {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, success) VALUES (?, ?)");
        $stmt->execute([$ip, $success ? 1 : 0]);
        // Prune old attempts to keep the table small
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    } catch (Exception $e) {
        // Non-critical — don't break login if this fails
    }
}

// ---- Authentication ----
function is_logged_in(): bool {
    if (isset($_SESSION['user_id'])) {
        return true;
    }
    if (isset($_COOKIE['remember_token'])) {
        try {
            $pdo  = db();
            $stmt = $pdo->prepare(
                "SELECT rt.user_id, u.must_change_password
                 FROM remember_tokens rt
                 JOIN users u ON u.id = rt.user_id
                 WHERE rt.token = ? AND rt.expires_at > NOW()"
            );
            $stmt->execute([$_COOKIE['remember_token']]);
            $result = $stmt->fetch();
            if ($result) {
                $_SESSION['user_id'] = $result['user_id'];
                if ($result['must_change_password']) {
                    $_SESSION['must_change_password'] = true;
                }
                return true;
            }
            // Invalid or expired token
            setcookie('remember_token', '', time() - 3600, '/');
        } catch (Exception $e) {
            setcookie('remember_token', '', time() - 3600, '/');
        }
    }
    return false;
}

function enforce_secure_auth(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    // Redirect to change_password.php if a password change is required
    if (!empty($_SESSION['must_change_password'])) {
        $page = basename($_SERVER['PHP_SELF'] ?? '');
        if ($page !== 'change_password.php') {
            header('Location: change_password.php');
            exit;
        }
    }
}

function generate_secure_token(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

function login(string $username, string $password, bool $remember_me = false): bool {
    $ip = get_client_ip();
    if (is_rate_limited($ip)) {
        return false;
    }

    $pdo  = db();
    $stmt = $pdo->prepare(
        "SELECT id, username, password_hash, must_change_password
         FROM users WHERE username = ? AND is_active = TRUE"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        record_login_attempt($ip, true);

        session_regenerate_id(true); // prevent session fixation
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        if ($user['must_change_password']) {
            $_SESSION['must_change_password'] = true;
        }

        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

        if ($remember_me) {
            $token      = generate_secure_token();
            $expires_at = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
            $pdo->prepare(
                "INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
            )->execute([$user['id'], $token, $expires_at]);

            setcookie('remember_token', $token, [
                'expires'  => time() + (30 * 24 * 60 * 60),
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        return true;
    }

    record_login_attempt($ip, false);
    return false;
}

function logout(): void {
    if (isset($_COOKIE['remember_token'])) {
        try {
            $pdo  = db();
            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE token = ?");
            $stmt->execute([$_COOKIE['remember_token']]);
        } catch (Exception $e) { /* ignore */ }
        setcookie('remember_token', '', time() - 3600, '/');
    }
    session_destroy();
    session_start();
}

// ---- Default admin user ----
function create_default_user(): bool {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->prepare(
                "INSERT INTO users (username, password_hash, must_change_password) VALUES (?, ?, 1)"
            )->execute(['admin', $hash]);

            echo "<div style='background:#fff3cd;border:1px solid #ffeaa7;padding:1rem;margin:1rem;border-radius:5px;'>";
            echo "<strong>Default user created:</strong><br>";
            echo "Username: <code>admin</code>&nbsp; Password: <code>admin123</code><br>";
            echo "<strong>You will be required to change this password on first login.</strong>";
            echo "</div>";
        }
    } catch (Exception $e) {
        return false;
    }
    return true;
}

create_default_user();

// ---- Login form submission (processed here because auth.php is included by login.php) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $csrf_token  = $_POST['csrf_token'] ?? '';
    $username    = $_POST['username']   ?? '';
    $password    = $_POST['password']   ?? '';
    $remember_me = isset($_POST['remember_me']);

    if (!validate_csrf_token($csrf_token)) {
        $login_error = "Request validation failed. Please try again.";
    } elseif (is_rate_limited(get_client_ip())) {
        $login_error = "Too many failed login attempts. Please try again in 15 minutes.";
    } elseif (login($username, $password, $remember_me)) {
        header('Location: index.php');
        exit;
    } else {
        $login_error = "Invalid username or password.";
    }
}

// ---- Logout ----
if (isset($_GET['logout'])) {
    logout();
    header('Location: index.php');
    exit;
}
