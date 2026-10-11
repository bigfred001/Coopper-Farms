<?php
require_once '../../config/config.php';

if (isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = sanitize($_POST['name'] ?? '');
    $email    = sanitize($_POST['email'] ?? '');
    $phone    = sanitize($_POST['phone'] ?? '');
    $location = sanitize($_POST['location'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$name || !$email || !$password) {
        $error = 'Please fill in all required fields.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        // Check if email exists
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, location, password_hash, role) VALUES (?, ?, ?, ?, ?, 'customer')");
            $stmt->execute([$name, $email, $phone, $location, $hash]);

            // Send welcome email to customer + notify admin
            $mailer->sendWelcome($email, $name);

            $success = 'Account created successfully! Check your email for a welcome message.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Account - Coopers Farm</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../../public/css/user.css"/>
</head>
<body>
<div class="auth-page">
  <div class="auth-brand">
    <div class="auth-brand-logo">🐔 Coopers</div>
    <h2>Join the Coopers Family</h2>
    <p>Create your account today and enjoy fresh farm products delivered right to your door. Mobile Money accepted!</p>
  </div>
  <div style="display:flex;align-items:center;justify-content:center;padding:40px;overflow-y:auto;">
    <div class="auth-form-side" style="max-width:440px;width:100%;">
      <h2>Create Account</h2>
      <p>Already have one? <a href="login.php">Sign in here</a></p>

      <?php if($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
      <?php endif; ?>
      <?php if($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?> <a href="login.php">Login now →</a></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label>Full Name *</label>
          <input type="text" name="name" class="form-control" placeholder="Kwame Asante" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Email Address *</label>
          <input type="email" name="email" class="form-control" placeholder="you@example.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="tel" name="phone" class="form-control" placeholder="+233 24 000 0000" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Delivery Location</label>
          <input type="text" name="location" class="form-control" placeholder="e.g. East Legon, Accra" value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Password *</label>
          <input type="password" name="password" class="form-control" placeholder="Min. 6 characters" required>
        </div>
        <div class="form-group">
          <label>Confirm Password *</label>
          <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" required>
        </div>
        <button type="submit" class="btn-primary">Create Account <i class="fas fa-arrow-right"></i></button>
      </form>
      <div class="auth-link">
        <a href="../index.php">← Back to Homepage</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
