<?php
require_once '../config/config.php';

// Already logged in as admin? Go to dashboard
if (isLoggedIn() && isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin' AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];
            header('Location: ' . SITE_URL . '/admin/index.php');
            exit;
        } else {
            $error = 'Invalid admin credentials. Please try again.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Login — Coopers Farm</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--cream); }
    .login-card {
      background:white;
      border-radius:20px;
      padding:48px;
      width:100%;
      max-width:440px;
      box-shadow:0 20px 60px rgba(0,0,0,0.1);
    }
    .login-logo {
      font-family:var(--font-display);
      font-size:28px;
      color:var(--primary);
      margin-bottom:8px;
      display:flex;
      align-items:center;
      gap:10px;
    }
    .login-subtitle {
      color:var(--text-light);
      font-size:14px;
      margin-bottom:36px;
    }
    .login-card h2 {
      font-family:var(--font-display);
      font-size:26px;
      margin-bottom:4px;
    }
    .demo-box {
      background:var(--primary-light);
      border-radius:10px;
      padding:14px 16px;
      font-size:13px;
      color:var(--primary-dark);
      margin-top:24px;
    }
    .demo-box strong { display:block; margin-bottom:6px; }
    .demo-box code {
      display:block;
      font-family:monospace;
      font-size:12px;
      line-height:1.8;
    }
    .back-link {
      display:block;
      text-align:center;
      margin-top:20px;
      font-size:13px;
      color:var(--text-light);
      text-decoration:none;
    }
    .back-link:hover { color:var(--primary); }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="login-logo">
      <i class="fas fa-drumstick-bite"></i> Coopers
    </div>
    <div class="login-subtitle">Admin Dashboard Access</div>

    <h2>Sign In</h2>
    <p style="color:var(--text-light);font-size:14px;margin-bottom:28px;">Enter your admin credentials below</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" class="form-control" placeholder="admin@coopersfarm.com" required
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autofocus>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" class="form-control" placeholder="Your password" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;">
        <i class="fas fa-sign-in-alt"></i> Sign In to Dashboard
      </button>
    </form>

    <div class="demo-box">
      <strong>🔑 Default Demo Credentials</strong>
      <code>
        Email: admin@coopersfarm.com<br>
        Password: password
      </code>
    </div>

    <a href="../customer/index.php" class="back-link">
      ← Back to Customer Site
    </a>
  </div>
</body>
</html>
