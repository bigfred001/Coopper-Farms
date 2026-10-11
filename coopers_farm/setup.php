<?php
/**
 * COOPERS FARM — First-time Setup Script
 * Run this once after importing schema.sql if the default login doesn't work.
 * Delete this file after running!
 *
 * URL: http://localhost/coopers_farm/setup.php
 */

require_once 'config/config.php';

$done = [];
$errors = [];

// 1. Check DB tables exist
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $done[] = "✅ Database connected. Tables found: " . implode(', ', $tables);
} catch (Exception $e) {
    $errors[] = "❌ DB error: " . $e->getMessage();
}

// 2. Fix/insert admin user with freshly hashed password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
    $adminEmail = trim($_POST['admin_email'] ?? 'admin@coopersfarm.com');
    $adminPass  = trim($_POST['admin_pass'] ?? 'password');
    $adminName  = trim($_POST['admin_name'] ?? 'Farm Manager');

    $hash = password_hash($adminPass, PASSWORD_DEFAULT);

    // Delete existing admin and re-insert with correct hash
    $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$adminEmail]);
    $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, role, is_active) VALUES (?, ?, '+233241234567', ?, 'admin', 1)")
        ->execute([$adminName, $adminEmail, $hash]);

    $done[] = "✅ Admin user created: <strong>$adminEmail</strong> / <strong>$adminPass</strong>";

    // Also ensure sample customer exists
    $custHash = password_hash('password', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT IGNORE INTO users (name, email, phone, password_hash, role) VALUES ('Test Customer','customer@example.com','+233201234567',?,'customer')")
        ->execute([$custHash]);
    $done[] = "✅ Sample customer created: customer@example.com / password";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Coopers Farm — Setup</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'DM Sans',sans-serif; background:#f0f4f0; display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px; }
    .card { background:white; border-radius:16px; padding:40px; max-width:520px; width:100%; box-shadow:0 12px 40px rgba(0,0,0,0.1); }
    h1 { font-size:24px; color:#2D5016; margin-bottom:6px; }
    .subtitle { color:#888; font-size:14px; margin-bottom:28px; }
    .status-item { padding:10px 14px; border-radius:8px; font-size:14px; margin-bottom:8px; }
    .status-ok { background:#d1fae5; color:#065f46; }
    .status-err { background:#fee2e2; color:#991b1b; }
    .form-group { margin-bottom:16px; }
    label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#333; }
    input { width:100%; padding:12px 14px; border:1.5px solid #e5e7eb; border-radius:10px; font-size:14px; font-family:inherit; outline:none; }
    input:focus { border-color:#4A7C59; }
    button { background:#4A7C59; color:white; border:none; padding:14px; width:100%; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; font-family:inherit; margin-top:8px; }
    button:hover { background:#2D5016; }
    .links { margin-top:24px; padding-top:20px; border-top:1px solid #e5e7eb; display:flex; gap:12px; flex-wrap:wrap; }
    .links a { padding:10px 18px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:600; }
    .link-admin { background:#4A7C59; color:white; }
    .link-customer { background:#f3f4f6; color:#555; }
    .warning { background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:12px 16px; font-size:13px; color:#92400e; margin-top:20px; }
  </style>
</head>
<body>
<div class="card">
  <h1>🐔 Coopers Farm Setup</h1>
  <div class="subtitle">First-time configuration and admin account creation</div>

  <?php foreach ($done as $msg): ?>
    <div class="status-item status-ok"><?php echo $msg; ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $msg): ?>
    <div class="status-item status-err"><?php echo $msg; ?></div>
  <?php endforeach; ?>

  <form method="POST" style="margin-top:24px;">
    <h3 style="font-size:16px;margin-bottom:16px;color:#1A1A1A;">Create / Reset Admin Account</h3>
    <div class="form-group">
      <label>Admin Name</label>
      <input type="text" name="admin_name" value="Farm Manager" required>
    </div>
    <div class="form-group">
      <label>Admin Email</label>
      <input type="email" name="admin_email" value="admin@coopersfarm.com" required>
    </div>
    <div class="form-group">
      <label>Admin Password</label>
      <input type="text" name="admin_pass" value="password" required>
    </div>
    <button type="submit" name="setup" value="1">
      🔧 Create Admin Account &amp; Fix Login
    </button>
  </form>

  <div class="links">
    <a href="admin/login.php" class="link-admin">→ Admin Login</a>
    <a href="admin/index.php" class="link-admin">→ Admin Dashboard</a>
    <a href="customer/index.php" class="link-customer">Customer Site</a>
  </div>

  <div class="warning">
    ⚠️ <strong>Delete this file after setup!</strong><br>
    Remove <code>setup.php</code> from your server once everything is working.
  </div>
</div>
</body>
</html>
