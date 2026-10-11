<?php
// ============================================================
// COOPERS FARM - Database & App Configuration
// ============================================================

session_start();

// Database configuration
$host     = 'localhost';
$dbname   = 'coopers_farm';
$username = 'root';
$password = '';

// Timezone
date_default_timezone_set('Africa/Accra');

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Site config
define('SITE_NAME', 'Coopers Farm');
define('SITE_URL',  'http://localhost/coopers_farm');
define('CURRENCY',        'GHS');
define('CURRENCY_SYMBOL', "\u{20B5}");  // ₵

// ============================================================
// EMAIL CONFIGURATION — Mailtrap (free testing inbox)
// Perfect for localhost / WAMP / XAMPP — no domain needed!
//
// SETUP (2 minutes):
// 1. Sign up FREE at: https://mailtrap.io
// 2. Go to: Email Testing → Inboxes → click your inbox
// 3. Click "Show Credentials" — copy Username & Password
// 4. Paste them below
// ============================================================
define('MAILTRAP_HOST',     'sandbox.smtp.mailtrap.io');
define('MAILTRAP_PORT',     2525);                        // works on all networks
define('MAILTRAP_USERNAME', 'YOUR_MAILTRAP_USERNAME');    // <- paste from Mailtrap
define('MAILTRAP_PASSWORD', 'YOUR_MAILTRAP_PASSWORD');    // <- paste from Mailtrap

// Sender — can be anything for Mailtrap (it's a test inbox)
define('EMAIL_FROM',     'noreply@coopersfarm.com');
define('EMAIL_FROM_NAME','Coopers Farm');

// Admin receives all farm alert emails (your real email)
define('ADMIN_EMAIL',    'admin@coopersfarm.com');        // <- your real email
define('ADMIN_NAME',     'Farm Manager');

// Alert thresholds
define('MORTALITY_ALERT_THRESHOLD', 5); // send alert when deaths >= this per log entry

// PDO Connection
try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('<div style="font-family:sans-serif;padding:40px;max-width:600px;margin:60px auto;background:#fee2e2;border:1px solid #fca5a5;border-radius:12px;">
        <h2 style="color:#991b1b;">Database Connection Failed</h2>
        <p style="color:#7f1d1d;">' . htmlspecialchars($e->getMessage()) . '</p>
        <hr style="border:1px solid #fca5a5;margin:20px 0;">
        <p style="color:#991b1b;font-size:14px;">Check:<br>
        1. MySQL is running (XAMPP/WAMP)<br>
        2. Database <strong>coopers_farm</strong> exists (import schema.sql)<br>
        3. Credentials in config/config.php are correct</p>
    </div>');
}

// Load email service
require_once __DIR__ . '/email.php';
$mailer = new EmailService();

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/customer/pages/login.php');
        exit;
    }
}

function requireAdmin() {
    if (!isLoggedIn() || !isAdmin()) {
        header('Location: ' . SITE_URL . '/admin/login.php');
        exit;
    }
}

function formatCurrency($amount) {
    return CURRENCY_SYMBOL . number_format((float)$amount, 2);
}

function generateOrderNumber() {
    return 'CF-' . strtoupper(substr(uniqid(), -6)) . '-' . date('Ymd');
}

function sanitize($str) {
    return htmlspecialchars(strip_tags(trim($str)));
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ============================================================
// AUDIT LOGGING
// ============================================================
function auditLog(PDO $pdo, string $action, string $entityType, ?int $entityId = null, string $description = ''): void {
    try {
        $userId   = $_SESSION['user_id'] ?? null;
        $userName = $_SESSION['name']    ?? 'System';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? null;

        // Create table if not exists (safe to run every time, cheap)
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT, user_name VARCHAR(100),
            action VARCHAR(100) NOT NULL, entity_type VARCHAR(50) NOT NULL,
            entity_id INT, description TEXT, ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->prepare("INSERT INTO audit_logs (user_id, user_name, action, entity_type, entity_id, description, ip_address) VALUES (?,?,?,?,?,?,?)")
            ->execute([$userId, $userName, $action, $entityType, $entityId, $description, $ip]);
    } catch (Exception $e) {
        error_log('[Audit] ' . $e->getMessage());
    }
}
?>
