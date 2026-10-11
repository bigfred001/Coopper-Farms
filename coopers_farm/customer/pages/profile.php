<?php
require_once '../../config/config.php';
requireLogin();

$userId = $_SESSION['user_id'];

// Fetch user info
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

// Fetch orders
$ordersStmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$ordersStmt->execute([$userId]);
$orders = $ordersStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Profile - Coopers Farm</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../../public/css/user.css"/>
</head>
<body>
<nav class="navbar">
  <div class="nav-container">
    <a href="../index.php" class="logo">Coopers</a>
    <div class="nav-actions">
      <a href="logout.php" style="color:#ef4444;text-decoration:none;font-size:14px;font-weight:500;">Logout</a>
    </div>
  </div>
</nav>

<div class="profile-page">
  <div class="profile-container">
    <div class="profile-card">
      <div class="profile-avatar"><?php echo strtoupper(substr($user['name'],0,1)); ?></div>
      <div class="profile-name"><?php echo htmlspecialchars($user['name']); ?></div>
      <div class="profile-email"><?php echo htmlspecialchars($user['email']); ?></div>
      <ul class="profile-nav">
        <li><a href="profile.php" class="active"><i class="fas fa-box"></i> My Orders</a></li>
        <li><a href="../index.php"><i class="fas fa-shopping-bag"></i> Shop</a></li>
        <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
      </ul>
    </div>

    <div class="orders-table-wrap">
      <h3>My Orders (<?php echo count($orders); ?>)</h3>
      <?php if(empty($orders)): ?>
        <div style="text-align:center;padding:60px;color:#888;">
          <i class="fas fa-box-open" style="font-size:48px;margin-bottom:16px;"></i>
          <p>No orders yet. <a href="../index.php" style="color:#4A7C59;">Start shopping →</a></p>
        </div>
      <?php else: ?>
      <table class="orders-table">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Date</th>
            <th>Amount</th>
            <th>Payment</th>
            <th>Status</th>
            <th>Delivery</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($orders as $order): ?>
          <tr>
            <td><strong><?php echo $order['order_number']; ?></strong></td>
            <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
            <td><strong><?php echo formatCurrency($order['total_amount']); ?></strong></td>
            <td><?php echo str_replace(['momo_','_'],'',ucfirst($order['payment_method'])); ?></td>
            <td>
              <span class="order-status status-<?php echo $order['order_status']; ?>">
                <?php echo ucwords(str_replace('_',' ',$order['order_status'])); ?>
              </span>
            </td>
            <td><?php echo $order['delivery_date'] ? date('M d', strtotime($order['delivery_date'])) : '—'; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
