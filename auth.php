<?php
session_start();

// Database configuration (same as main app)
const DB_HOST = 'localhost';
const DB_NAME = 'investments';
const DB_USER = 'root';
const DB_PASS = 'gN6mCgrP!Gi6z9gxp';
const DB_CHARSET = 'utf8mb4';

// Database connection function
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// Create users table if it doesn't exist
function create_users_table() {
    try {
        $pdo = db();
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            is_active BOOLEAN DEFAULT TRUE
        )";
        $pdo->exec($sql);
    } catch (Exception $e) {
        // Database not available - skip table creation
        return false;
    }
    return true;
}

// Initialize the users table (only if database is available)
create_users_table();

// Check if user is logged in
function is_logged_in() {
    // Check session first
    if (isset($_SESSION['user_id'])) {
        return true;
    }
    
    // Check for remember me cookie
    if (isset($_COOKIE['remember_token'])) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT user_id FROM remember_tokens WHERE token = ? AND expires_at > NOW()");
            $stmt->execute([$_COOKIE['remember_token']]);
            $result = $stmt->fetch();
            
            if ($result) {
                $_SESSION['user_id'] = $result['user_id'];
                return true;
            } else {
                // Invalid or expired token, remove cookie
                setcookie('remember_token', '', time() - 3600, '/');
            }
        } catch (Exception $e) {
            // Database not available - remove cookie and return false
            setcookie('remember_token', '', time() - 3600, '/');
        }
    }
    
    return false;
}

// Create remember tokens table if it doesn't exist
function create_remember_tokens_table() {
    try {
        $pdo = db();
        $sql = "CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) UNIQUE NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $pdo->exec($sql);
    } catch (Exception $e) {
        // Database not available - skip table creation
        return false;
    }
    return true;
}

// Initialize the remember tokens table (only if database is available)
create_remember_tokens_table();

// Generate secure random token
function generate_secure_token($length = 32) {
    return bin2hex(random_bytes($length));
}

// Login function
function login($username, $password, $remember_me = false) {
    $pdo = db();
    
    // Get user from database
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ? AND is_active = TRUE");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        
        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Handle remember me
        if ($remember_me) {
            $token = generate_secure_token();
            $expires_at = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
            
            // Store token in database
            $stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $token, $expires_at]);
            
            // Set cookie
            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
        }
        
        return true;
    }
    
    return false;
}

// Logout function
function logout() {
    // Remove remember token from database if it exists
    if (isset($_COOKIE['remember_token'])) {
        $pdo = db();
        $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE token = ?");
        $stmt->execute([$_COOKIE['remember_token']]);
        setcookie('remember_token', '', time() - 3600, '/');
    }
    
    // Destroy session
    session_destroy();
    session_start();
}

// Create default admin user if no users exist
function create_default_user() {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            $default_password = 'admin123'; // Change this!
            $password_hash = password_hash($default_password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
            $stmt->execute(['admin', $password_hash]);
            
            echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 1rem; margin: 1rem; border-radius: 5px;'>";
            echo "<strong>Default user created:</strong><br>";
            echo "Username: admin<br>";
            echo "Password: admin123<br>";
            echo "<strong>Please change this password immediately!</strong>";
            echo "</div>";
        }
    } catch (Exception $e) {
        // Database not available - skip user creation
        return false;
    }
    return true;
}

// Initialize default user (only if database is available)
create_default_user();

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    
    if (login($username, $password, $remember_me)) {
        header('Location: index.php');
        exit;
    } else {
        $login_error = "Invalid username or password";
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    logout();
    header('Location: index.php');
    exit;
}
?>
