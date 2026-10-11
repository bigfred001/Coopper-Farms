<?php
require_once '../../config/config.php';

if (isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            if ($user['role'] === 'admin') {
                header('Location: ' . SITE_URL . '/admin/index.php');
            } else {
                header('Location: ' . SITE_URL . '/customer/index.php');
            }
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
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
  <title>Login - Coopers Farm</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../../public/css/user.css"/>
</head>
<body>
<div class="auth-page">
  <div class="auth-brand">
    <div class="auth-brand-logo">🐔 Coopers</div>
    <h2>Welcome back to the farm!</h2>
    <p>Log in to order fresh eggs, broilers, and more — delivered straight from our farm to your door.</p>
  </div>
  <div style="display:flex;align-items:center;justify-content:center;padding:40px;">
    <div class="auth-form-side" style="max-width:420px;width:100%;">
      <h2>Sign In</h2>
      <p>Don't have an account? <a href="signup.php">Create one free</a></p>

      <?php if($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="you@example.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" class="form-control" placeholder="Your password" required>
        </div>
        <button type="submit" class="btn-primary">Sign In <i class="fas fa-arrow-right"></i></button>
      </form>

      <div class="auth-divider">or</div>
      <p style="text-align:center;font-size:13px;color:#888;">
        Demo: <strong>admin@coopersfarm.com</strong> / <strong>password</strong>
      </p>
      <div class="auth-link">
        <a href="../index.php">← Back to Homepage</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
