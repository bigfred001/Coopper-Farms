<?php
require_once '../../config/config.php';

$cart = $_SESSION['cart'] ?? [];
$cartItems = [];
$subtotal = 0;

if (!empty($cart)) {
    foreach ($cart as $pid => $item) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1");
        $stmt->execute([$pid]);
        $product = $stmt->fetch();
        if ($product) {
            $lineTotal = $product['price'] * $item['quantity'];
            $subtotal += $lineTotal;
            $cartItems[] = [
                'product' => $product,
                'quantity' => $item['quantity'],
                'total' => $lineTotal,
                'emoji' => $item['emoji'] ?? '🛒'
            ];
        }
    }
}

$deliveryFee = $subtotal > 100 ? 0 : 10;
$total = $subtotal + $deliveryFee;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($cartItems)) {
    $name    = sanitize($_POST['customer_name'] ?? '');
    $phone   = sanitize($_POST['customer_phone'] ?? '');
    $email   = sanitize($_POST['customer_email'] ?? '');
    $address = sanitize($_POST['delivery_address'] ?? '');
    $date    = $_POST['delivery_date'] ?? date('Y-m-d', strtotime('+1 day'));
    $payment = $_POST['payment_method'] ?? 'cash';

    if (!$name || !$phone || !$address) {
        $error = 'Please fill all required fields.';
    } else {
        $orderNum = generateOrderNumber();
        $userId = $_SESSION['user_id'] ?? null;

        $stmt = $pdo->prepare("INSERT INTO orders (user_id, order_number, total_amount, delivery_fee, payment_method, delivery_address, delivery_date, customer_name, customer_phone, customer_email, order_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$userId, $orderNum, $total, $deliveryFee, $payment, $address, $date, $name, $phone, $email]);
        $orderId = $pdo->lastInsertId();

        foreach ($cartItems as $ci) {
            $p = $ci['product'];
            $stmt2 = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt2->execute([$orderId, $p['id'], $p['name'], $ci['quantity'], $p['price'], $ci['total']]);
            // Update stock
            $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?")->execute([$ci['quantity'], $p['id']]);
        }

        // Log sale
        $pdo->prepare("INSERT INTO sales (order_id, sale_date, total_amount, payment_method) VALUES (?, CURDATE(), ?, ?)")->execute([$orderId, $total, $payment]);

        // Send order confirmation email to customer + admin alert
        $fullOrder = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $fullOrder->execute([$orderId]);
        $orderRow = $fullOrder->fetch();

        $orderItemsRows = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $orderItemsRows->execute([$orderId]);
        $itemsList = $orderItemsRows->fetchAll();

        $mailer->sendOrderConfirmation($orderRow, $itemsList);

        // Clear cart
        unset($_SESSION['cart']);
        $success = "Order placed successfully! Order #$orderNum";
        $cartItems = [];
        $subtotal = 0;
        $total = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Checkout - Coopers Farm</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../../public/css/user.css"/>
</head>
<body>
<nav class="navbar">
  <div class="nav-container">
    <a href="../index.php" class="logo">Coopers</a>
    <div style="font-size:14px;color:#555;">Secure Checkout <i class="fas fa-lock" style="color:#4A7C59;margin-left:6px;"></i></div>
  </div>
</nav>

<div class="checkout-page">
  <div class="page-header" style="max-width:1100px;margin:0 auto 24px;">
    <h1 style="font-family:'Playfair Display',serif;">Checkout</h1>
  </div>

  <?php if($success): ?>
    <div style="max-width:600px;margin:0 auto;text-align:center;padding:60px 20px;">
      <div style="font-size:80px;margin-bottom:20px;">✅</div>
      <h2 style="font-family:'Playfair Display',serif;font-size:32px;margin-bottom:12px;">Order Placed!</h2>
      <p style="color:#555;margin-bottom:8px;"><?php echo $success; ?></p>
      <p style="color:#888;font-size:14px;margin-bottom:32px;">We'll contact you shortly to confirm your order and delivery details.</p>
      <a href="../index.php" style="background:#4A7C59;color:white;padding:16px 36px;border-radius:50px;text-decoration:none;font-weight:600;">Continue Shopping</a>
    </div>
  <?php else: ?>

  <div class="checkout-container">
    <div class="checkout-form-card">
      <h3>Delivery Details</h3>
      <?php if($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
      <?php if(empty($cartItems)): ?>
        <div style="text-align:center;padding:40px;color:#888;">
          <i class="fas fa-shopping-basket" style="font-size:48px;margin-bottom:16px;"></i>
          <p>Your cart is empty. <a href="../index.php" style="color:#4A7C59;">Shop now →</a></p>
        </div>
      <?php else: ?>
      <form method="POST">
        <div class="form-row">
          <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="customer_name" class="form-control" required value="<?php echo htmlspecialchars($_SESSION['name'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>Phone Number *</label>
            <input type="tel" name="customer_phone" class="form-control" required placeholder="+233 XX XXX XXXX">
          </div>
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="customer_email" class="form-control" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Delivery Address *</label>
          <textarea name="delivery_address" class="form-control" rows="3" placeholder="House number, street, area, city..." required></textarea>
        </div>
        <div class="form-group">
          <label>Preferred Delivery Date</label>
          <input type="date" name="delivery_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
        </div>

        <h3 style="font-family:'Playfair Display',serif;font-size:18px;margin:28px 0 16px;">Payment Method</h3>
        <div class="payment-methods">
          <label class="payment-method selected">
            <input type="radio" name="payment_method" value="momo_mtn" checked>
            <div class="payment-icon mtn">MTN</div>
            <div><strong>MTN MoMo</strong><br><small>Mobile Money</small></div>
          </label>
          <label class="payment-method">
            <input type="radio" name="payment_method" value="momo_vodafone">
            <div class="payment-icon vodafone">VF</div>
            <div><strong>Vodafone Cash</strong><br><small>Mobile Money</small></div>
          </label>
          <label class="payment-method">
            <input type="radio" name="payment_method" value="momo_airteltigo">
            <div class="payment-icon airteltigo">AT</div>
            <div><strong>AirtelTigo Money</strong><br><small>Mobile Money</small></div>
          </label>
          <label class="payment-method">
            <input type="radio" name="payment_method" value="cash">
            <div class="payment-icon cash-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div><strong>Cash on Delivery</strong><br><small>Pay when received</small></div>
          </label>
        </div>

        <button type="submit" class="btn-checkout" style="margin-top:24px;">Place Order — <?php echo formatCurrency($total); ?></button>
      </form>
      <?php endif; ?>
    </div>

    <div class="checkout-summary">
      <div class="summary-card">
        <h3>Order Summary</h3>
        <?php foreach($cartItems as $ci): ?>
        <div class="summary-item">
          <span><?php echo $ci['emoji']; ?> <?php echo htmlspecialchars($ci['product']['name']); ?> ×<?php echo $ci['quantity']; ?></span>
          <span><?php echo formatCurrency($ci['total']); ?></span>
        </div>
        <?php endforeach; ?>
        <div class="summary-item"><span>Subtotal</span><span><?php echo formatCurrency($subtotal); ?></span></div>
        <div class="summary-item">
          <span>Delivery</span>
          <span><?php echo $deliveryFee == 0 ? '🎉 Free' : formatCurrency($deliveryFee); ?></span>
        </div>
        <?php if($deliveryFee > 0): ?>
        <p style="font-size:12px;color:#888;margin:-8px 0 12px;">Free delivery on orders above ₵100</p>
        <?php endif; ?>
        <div class="summary-total">
          <span>Total</span>
          <span><?php echo formatCurrency($total); ?></span>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
  // Payment method selection
  document.querySelectorAll('.payment-method').forEach(m => {
    m.addEventListener('click', function() {
      document.querySelectorAll('.payment-method').forEach(x => x.classList.remove('selected'));
      this.classList.add('selected');
    });
  });
</script>
</body>
</html>
