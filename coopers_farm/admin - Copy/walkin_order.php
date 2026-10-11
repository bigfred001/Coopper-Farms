<?php
require_once '../config/config.php';
requireAdmin();

$msg = ''; $msgType = 'success'; $newOrderNum = '';
$orderTotal = 0; $orderReceived = 0; $receiptItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $custName  = sanitize($_POST['customer_name']);
    $custPhone = sanitize($_POST['customer_phone']);
    $custEmail = sanitize($_POST['customer_email'] ?? '');
    $payment   = $_POST['payment_method'] ?? 'cash';
    $received  = (float)($_POST['payment_received'] ?? 0);
    $notes     = sanitize($_POST['notes'] ?? '');
    $items     = $_POST['items'] ?? [];

    if (!$custName || !$custPhone || empty($items)) {
        $msg = 'Please fill customer name, phone and add at least one item.';
        $msgType = 'error';
    } else {
        $cartItems = []; $subtotal = 0; $stockErrors = [];
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if (!$pid || !$qty) continue;
            $p = $pdo->prepare("SELECT * FROM products WHERE id=? AND is_active=1");
            $p->execute([$pid]);
            $product = $p->fetch();
            if (!$product) continue;
            if ($product['stock_quantity'] < $qty) { $stockErrors[] = "{$product['name']}: only {$product['stock_quantity']} in stock"; continue; }
            $line = $product['price'] * $qty;
            $subtotal += $line;
            $cartItems[] = ['product' => $product, 'quantity' => $qty, 'subtotal' => $line];
        }
        if (!empty($stockErrors)) {
            $msg = 'Stock issues: ' . implode('; ', $stockErrors); $msgType = 'error';
        } elseif (empty($cartItems)) {
            $msg = 'No valid items added.'; $msgType = 'error';
        } else {
            $orderNum = generateOrderNumber();
            $total    = $subtotal;
            $pdo->prepare("INSERT INTO orders (order_number, total_amount, delivery_fee, payment_method, payment_status, order_status, customer_name, customer_phone, customer_email, notes, order_type, served_by, payment_received, delivery_address) VALUES (?,?,0,?,?,?,?,?,?,?,'walkin',?,?,?)")
                ->execute([$orderNum, $total, $payment, $received >= $total ? 'paid' : 'pending', 'delivered',
                           $custName, $custPhone, $custEmail, $notes, $_SESSION['user_id'], $received, 'Farm Counter']);
            $orderId = $pdo->lastInsertId();
            foreach ($cartItems as $ci) {
                $prod = $ci['product'];
                $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?)")
                    ->execute([$orderId, $prod['id'], $prod['name'], $ci['quantity'], $prod['price'], $ci['subtotal']]);
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id=?")
                    ->execute([$ci['quantity'], $prod['id']]);
                $pdo->prepare("INSERT INTO inventory_logs (product_id, change_amount, reason, notes, created_by) VALUES (?,?,'sale',?,?)")
                    ->execute([$prod['id'], -$ci['quantity'], "Walk-in $orderNum", $_SESSION['user_id']]);
                if (in_array($prod['category'], ['broiler','layer']))
                    $pdo->prepare("UPDATE flocks SET quantity=GREATEST(0,quantity-?) WHERE bird_type=? AND status='active' LIMIT 1")
                        ->execute([$ci['quantity'], $prod['category']]);
                $receiptItems[] = ['name'=>$prod['name'],'emoji'=>['eggs'=>'🥚','broiler'=>'🍗','layer'=>'🐔','byproduct'=>'🌾','other'=>'📦'][$prod['category']]??'📦','qty'=>$ci['quantity'],'price'=>(float)$prod['price'],'sub'=>$ci['subtotal']];
            }
            $pdo->prepare("INSERT INTO sales (order_id, sale_date, total_amount, payment_method) VALUES (?,CURDATE(),?,?)")
                ->execute([$orderId, $total, $payment]);
            auditLog($pdo, 'walkin_order', 'order', $orderId, "Walk-in {$orderNum} for {$custName}. Total: ".formatCurrency($total).". Served by: ".($_SESSION['name']??'Admin'));
            $newOrderNum   = $orderNum;
            $orderTotal    = $total;
            $orderReceived = $received;
            $change        = max(0, $received - $total);
            $msg = "✅ Order <strong>$orderNum</strong> placed! Total: <strong>".formatCurrency($total)."</strong>".($received > 0 ? " · Change: <strong style='color:var(--primary)'>".formatCurrency($change)."</strong>" : '');
        }
    }
}

$products        = $pdo->query("SELECT * FROM products WHERE is_active=1 ORDER BY category, name")->fetchAll();
$grouped         = [];
foreach ($products as $p) { $grouped[$p['category']][] = $p; }
$recentWalkins   = $pdo->query("SELECT o.*, COUNT(oi.id) as item_count FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id WHERE COALESCE(o.order_type,'walkin')='walkin' GROUP BY o.id ORDER BY o.created_at DESC LIMIT 10")->fetchAll();
$outOfStockProds = $pdo->query("SELECT * FROM products WHERE stock_quantity<=0 AND is_active=1")->fetchAll();
$lowStockProds   = $pdo->query("SELECT * FROM products WHERE stock_quantity>0 AND stock_quantity<=10 AND is_active=1")->fetchAll();
$emojis = ['eggs'=>'🥚','broiler'=>'🍗','layer'=>'🐔','byproduct'=>'🌾','other'=>'📦'];
$todayRevenue    = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE COALESCE(order_type,'walkin')='walkin' AND DATE(created_at)=CURDATE()")->fetchColumn();
$todayCount      = $pdo->query("SELECT COUNT(*) FROM orders WHERE COALESCE(order_type,'walkin')='walkin' AND DATE(created_at)=CURDATE()")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Walk-in Sale — Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css">
  <style>
    /* ── Layout ─────────────────────────────────────────────── */
    .pos-wrap   { display:grid; grid-template-columns:1fr 380px; gap:20px; align-items:start; }
    .pos-left   { display:flex; flex-direction:column; gap:16px; }
    .pos-right  { position:sticky; top:80px; display:flex; flex-direction:column; gap:0; }

    /* ── Today strip ─────────────────────────────────────────── */
    .today-strip { display:flex; gap:12px; margin-bottom:4px; }
    .today-chip  { background:white; border:1px solid var(--border); border-radius:10px; padding:10px 16px; font-size:13px; flex:1; text-align:center; }
    .today-chip strong { display:block; font-size:18px; font-weight:700; font-family:'Playfair Display',serif; color:var(--primary); }
    .today-chip span   { font-size:11px; color:#9ca3af; }

    /* ── Category tabs ──────────────────────────────────────── */
    .cat-bar { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
    .cat-tab { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:50px; font-size:13px; font-weight:600; cursor:pointer; border:1.5px solid var(--border); background:white; color:var(--text-mid); transition:all 0.18s; }
    .cat-tab:hover, .cat-tab.active { background:var(--primary); color:white; border-color:var(--primary); }
    .cat-count { background:rgba(255,255,255,0.3); border-radius:50px; padding:0 5px; font-size:10px; }
    .cat-tab:not(.active) .cat-count { background:#f3f4f6; color:#888; }

    /* ── Product grid ───────────────────────────────────────── */
    .product-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
    .ptile { background:white; border:2px solid var(--border); border-radius:14px; padding:16px 12px 12px; cursor:pointer; transition:border-color 0.15s,transform 0.15s,box-shadow 0.15s; text-align:center; position:relative; user-select:none; }
    .ptile:hover:not(.p-out) { border-color:var(--primary); transform:translateY(-3px); box-shadow:0 8px 24px rgba(74,124,89,.15); }
    .ptile.p-selected { border-color:var(--primary); background:#F0F9F4; box-shadow:0 0 0 3px rgba(74,124,89,.12); }
    .ptile.p-out { opacity:0.45; cursor:not-allowed; border-color:#fca5a5; background:#fff5f5; }
    .ptile.p-low { border-color:#fbbf24; }
    .ptile-img { width:60px; height:60px; border-radius:10px; object-fit:cover; margin:0 auto 8px; display:block; }
    .ptile-emoji { font-size:40px; margin-bottom:8px; line-height:1; }
    .ptile-name  { font-size:12px; font-weight:700; color:#1a1a1a; margin-bottom:3px; line-height:1.3; }
    .ptile-price { font-size:13px; font-weight:700; color:var(--primary); }
    .ptile-stock { font-size:10px; margin-top:3px; font-weight:600; }
    .ptile-sbadge { position:absolute; top:8px; right:8px; font-size:9px; font-weight:700; padding:2px 6px; border-radius:50px; }
    .ptile-sbadge.out { background:#fee2e2; color:#991b1b; }
    .ptile-sbadge.low { background:#fef3c7; color:#92400e; }
    .ptile-qty-pill { position:absolute; top:8px; left:8px; background:var(--primary); color:white; font-size:10px; font-weight:700; min-width:22px; height:22px; border-radius:50px; display:none; align-items:center; justify-content:center; padding:0 5px; }
    .ptile.p-selected .ptile-qty-pill { display:flex; }

    /* Inline qty stepper on selected tile */
    .ptile-stepper { display:none; align-items:center; justify-content:center; gap:6px; margin-top:8px; }
    .ptile.p-selected .ptile-stepper { display:flex; }
    .ptile-stepper .sq-btn { width:24px; height:24px; border-radius:50%; border:1.5px solid var(--primary); background:white; color:var(--primary); font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s; }
    .ptile-stepper .sq-btn:hover { background:var(--primary); color:white; }
    .ptile-stepper .sq-num { font-size:13px; font-weight:700; min-width:20px; text-align:center; }

    /* ── Cart panel ─────────────────────────────────────────── */
    .cart-panel { background:white; border-radius:16px; border:1px solid var(--border); overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.06); }
    .cart-head  { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--primary); color:white; }
    .cart-head h3 { font-size:15px; font-weight:700; margin:0; color:white; }
    .cart-clear { background:rgba(255,255,255,.2); border:none; color:white; padding:5px 10px; border-radius:6px; font-size:12px; cursor:pointer; transition:background 0.15s; }
    .cart-clear:hover { background:rgba(255,255,255,.35); }
    .cart-body  { min-height:160px; max-height:300px; overflow-y:auto; }

    /* Empty state */
    .cart-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:40px 20px; color:#c4c4c4; text-align:center; }
    .cart-empty-icon { font-size:52px; margin-bottom:10px; opacity:.4; }
    .cart-empty strong { font-size:14px; color:#9ca3af; margin-bottom:4px; }
    .cart-empty small  { font-size:12px; color:#c4c4c4; }

    /* Cart rows */
    .cart-row { display:flex; align-items:center; gap:10px; padding:11px 16px; border-bottom:1px solid #f3f4f6; transition:background 0.15s; }
    .cart-row:hover { background:#fafafa; }
    .cart-row:last-child { border-bottom:none; }
    .crt-emoji { font-size:22px; flex-shrink:0; }
    .crt-info  { flex:1; min-width:0; }
    .crt-name  { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .crt-sub   { font-size:11px; color:#888; margin-top:1px; }
    .qty-ctrl  { display:flex; align-items:center; gap:5px; flex-shrink:0; }
    .qty-btn   { width:25px; height:25px; border-radius:50%; border:1.5px solid #e5e7eb; background:none; cursor:pointer; font-size:14px; font-weight:700; display:flex; align-items:center; justify-content:center; transition:all 0.15s; }
    .qty-btn:hover { background:var(--primary); color:white; border-color:var(--primary); }
    .qty-num   { font-size:13px; font-weight:700; min-width:20px; text-align:center; }
    .crt-del   { background:none; border:none; color:#d1d5db; cursor:pointer; padding:3px; font-size:13px; transition:color 0.15s; }
    .crt-del:hover { color:#ef4444; }

    /* Order summary in cart */
    .cart-summary { padding:14px 16px; border-top:1px solid var(--border); background:#fafafa; }
    .sum-row { display:flex; justify-content:space-between; font-size:13px; margin-bottom:5px; color:#6b7280; }
    .sum-row.total { font-size:17px; font-weight:700; color:#1a1a1a; border-top:1px solid var(--border); padding-top:8px; margin-top:5px; }
    .sum-row.change-ok   { color:#16a34a; font-weight:700; background:#dcfce7; margin:6px -16px -14px; padding:8px 16px; }
    .sum-row.change-short{ color:#dc2626; font-weight:700; background:#fee2e2; margin:6px -16px -14px; padding:8px 16px; }

    /* Payment + place order */
    .cart-foot { padding:16px; border-top:2px solid var(--border); }
    .pay-methods { display:grid; grid-template-columns:repeat(2,1fr); gap:6px; margin-bottom:12px; }
    .pay-btn { padding:8px 6px; border:1.5px solid var(--border); border-radius:8px; background:white; cursor:pointer; font-size:11px; font-weight:600; color:#6b7280; text-align:center; transition:all 0.15s; }
    .pay-btn:hover, .pay-btn.selected { border-color:var(--primary); background:#F0F9F4; color:var(--primary); }
    .amt-row { display:flex; gap:8px; align-items:center; margin-bottom:12px; }
    .amt-row label { font-size:12px; font-weight:600; color:#6b7280; white-space:nowrap; }
    .amt-input { flex:1; padding:9px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:14px; font-weight:600; font-family:inherit; outline:none; transition:border-color 0.15s; }
    .amt-input:focus { border-color:var(--primary); }
    .place-btn { width:100%; padding:14px; border-radius:10px; background:var(--primary); color:white; border:none; font-size:15px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:10px; transition:all 0.18s; font-family:inherit; }
    .place-btn:hover:not(:disabled) { background:#2D5016; transform:translateY(-1px); box-shadow:0 6px 20px rgba(74,124,89,.3); }
    .place-btn:disabled { opacity:0.4; cursor:not-allowed; transform:none; box-shadow:none; }

    /* ── Customer + Notes section ───────────────────────────── */
    .section-card { background:white; border:1px solid var(--border); border-radius:14px; padding:20px; }
    .section-title { font-size:14px; font-weight:700; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
    .form-row-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .fld label { font-size:12px; font-weight:600; color:#6b7280; margin-bottom:5px; display:block; }
    .fld input, .fld textarea { width:100%; padding:9px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:13px; font-family:inherit; outline:none; transition:border-color 0.15s; background:white; }
    .fld input:focus, .fld textarea:focus { border-color:var(--primary); }

    /* ── Cart line items preview (fills the blank space) ─────── */
    .cart-preview-panel { background:white; border:1px solid var(--border); border-radius:14px; padding:18px 20px; }
    .cart-preview-title { font-size:13px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; }
    .cpv-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:13px; }
    .cpv-row:last-child { border-bottom:none; }
    .cpv-empty { text-align:center; padding:20px; color:#d1d5db; font-size:13px; }

    /* ── Recent sales ───────────────────────────────────────── */
    .recent-card { background:white; border:1px solid var(--border); border-radius:14px; overflow:hidden; }
    .recent-head { padding:14px 20px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border); }
    .recent-head h3 { font-size:14px; font-weight:700; margin:0; }
    .rt-row { display:grid; grid-template-columns:2fr 1.5fr .5fr 1fr auto; gap:8px; align-items:center; padding:10px 16px; border-bottom:1px solid #f9f9f9; font-size:13px; transition:background 0.15s; }
    .rt-row:last-child { border-bottom:none; }
    .rt-row:hover { background:#f9fafb; }
    .rt-head { background:#f9fafb; padding:8px 16px; font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.5px; }

    /* ── Stock warning strip ────────────────────────────────── */
    .stock-strip { display:flex; gap:8px; flex-wrap:wrap; }
    .stock-chip  { display:inline-flex; align-items:center; gap:6px; padding:5px 10px; border-radius:6px; font-size:12px; font-weight:600; }
    .stock-chip.out { background:#fee2e2; color:#991b1b; }
    .stock-chip.low { background:#fef3c7; color:#92400e; }

    /* Receipt modal */
    .rmodal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center; padding:16px; }
    .rmodal-bg.show { display:flex; }
    .rmodal { background:white; border-radius:18px; padding:0; max-width:420px; width:100%; box-shadow:0 24px 80px rgba(0,0,0,.2); overflow:hidden; }
    .rmodal-header { background:var(--primary); color:white; padding:20px 24px; text-align:center; }
    .rmodal-header h2 { font-size:20px; font-family:'Playfair Display',serif; margin-bottom:2px; }
    .rmodal-header small { font-size:12px; opacity:.8; }
    .rmodal-body { padding:20px 24px; }
    .rline { display:flex; justify-content:space-between; padding:7px 0; font-size:13px; border-bottom:1px solid #f3f4f6; }
    .rline:last-child { border-bottom:none; }
    .rtotal { display:flex; justify-content:space-between; padding:12px 0 8px; font-size:17px; font-weight:700; border-top:2px solid #e5e7eb; margin-top:4px; }
    .rchange { display:flex; justify-content:space-between; font-size:14px; font-weight:700; color:#16a34a; padding:6px 0; }
    .rmodal-foot { padding:16px 24px; display:flex; gap:10px; border-top:1px solid var(--border); }

    @media(max-width:1100px) { .pos-wrap { grid-template-columns:1fr; } .pos-right { position:static; } }
    @media print { .sidebar,.topbar,.no-print { display:none!important; } .main-content { margin-left:0!important; } }
  </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">

    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;" >
      <div>
        <h1>Walk-in / Counter Sale</h1>
        <p>POS for customers buying directly at the farm · Served by <strong><?php echo htmlspecialchars($_SESSION['name']??'Admin'); ?></strong></p>
      </div>
      <a href="orders.php" class="btn btn-secondary no-print"><i class="fas fa-list"></i> All Orders</a>
    </div>

    <?php if($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:16px;">
      <?php echo $msg; ?>
      <?php if($newOrderNum): ?>
      <button type="button" onclick="openReceipt()" class="btn btn-sm btn-primary no-print" style="margin-left:14px;">
        <i class="fas fa-receipt"></i> Print Receipt
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Today's stats strip -->
    <div class="today-strip no-print">
      <div class="today-chip">
        <strong><?php echo $todayCount; ?></strong>
        <span>Sales today</span>
      </div>
      <div class="today-chip">
        <strong><?php echo formatCurrency($todayRevenue); ?></strong>
        <span>Revenue today</span>
      </div>
      <div class="today-chip" style="<?php echo !empty($outOfStockProds) ? 'border-color:#fca5a5;' : ''; ?>">
        <strong style="color:<?php echo !empty($outOfStockProds) ? '#ef4444' : 'var(--primary)'; ?>">
          <?php echo count($outOfStockProds) > 0 ? count($outOfStockProds).' out of stock' : 'All in stock ✓'; ?>
        </strong>
        <span>Stock status</span>
      </div>
    </div>

    <!-- Stock alerts -->
    <?php if(!empty($outOfStockProds) || !empty($lowStockProds)): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding:12px 16px;background:white;border:1px solid var(--border);border-radius:10px;" class="no-print">
      <?php foreach($outOfStockProds as $oos): ?>
        <span class="stock-chip out"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($oos['name']); ?> — OUT</span>
      <?php endforeach; ?>
      <?php foreach($lowStockProds as $ls): ?>
        <span class="stock-chip low"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($ls['name']); ?> — <?php echo $ls['stock_quantity']; ?> left</span>
      <?php endforeach; ?>
      <a href="products.php" style="margin-left:auto;font-size:12px;color:var(--primary);font-weight:700;align-self:center;">Restock →</a>
    </div>
    <?php endif; ?>

    <form method="POST" id="posForm">
    <input type="hidden" name="place_order" value="1">
    <div id="cartInputsHolder"></div>
    <input type="hidden" name="payment_method" id="payMethodInput" value="cash">

    <div class="pos-wrap">

      <!-- ── LEFT COLUMN ─────────────────────────────────── -->
      <div class="pos-left">

        <!-- Product selector -->
        <div class="section-card">
          <!-- Category filter -->
          <div class="cat-bar">
            <span class="cat-tab active" onclick="filterCat('all',this)">
              All <span class="cat-count"><?php echo count($products); ?></span>
            </span>
            <?php
            $catLabels = ['eggs'=>'🥚 Eggs','broiler'=>'🍗 Broiler','layer'=>'🐔 Layer','byproduct'=>'🌾 Byproduct','other'=>'📦 Other'];
            foreach($grouped as $cat => $catProds):
              $lbl = $catLabels[$cat] ?? ucfirst($cat);
            ?>
            <span class="cat-tab" onclick="filterCat('<?php echo $cat; ?>',this)">
              <?php echo $lbl; ?> <span class="cat-count"><?php echo count($catProds); ?></span>
            </span>
            <?php endforeach; ?>
          </div>

          <!-- Product tiles -->
          <div class="product-grid" id="productGrid">
            <?php foreach($products as $p):
              $isOut  = $p['stock_quantity'] <= 0;
              $isLow  = !$isOut && $p['stock_quantity'] <= 10;
              $emoji  = $emojis[$p['category']] ?? '📦';
              $cls    = $isOut ? 'p-out' : ($isLow ? 'p-low' : '');
            ?>
            <div class="ptile <?php echo $cls; ?>"
                 id="tile_<?php echo $p['id']; ?>"
                 data-cat="<?php echo $p['category']; ?>"
                 data-id="<?php echo $p['id']; ?>"
                 data-name="<?php echo htmlspecialchars($p['name'],ENT_QUOTES); ?>"
                 data-price="<?php echo $p['price']; ?>"
                 data-unit="<?php echo $p['unit']; ?>"
                 data-stock="<?php echo $p['stock_quantity']; ?>"
                 data-emoji="<?php echo $emoji; ?>"
                 data-img="<?php echo $p['image_url'] ? htmlspecialchars('../public/'.$p['image_url']) : ''; ?>"
                 <?php echo !$isOut ? 'onclick="tileClick(this)"' : ''; ?>>

              <!-- qty pill -->
              <span class="ptile-qty-pill" id="tileqty_<?php echo $p['id']; ?>">1</span>

              <?php if($isOut): ?>
                <span class="ptile-sbadge out">OUT</span>
              <?php elseif($isLow): ?>
                <span class="ptile-sbadge low">LOW</span>
              <?php endif; ?>

              <?php if(!empty($p['image_url'])): ?>
                <img src="../public/<?php echo htmlspecialchars($p['image_url']); ?>" class="ptile-img"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                <div class="ptile-emoji" style="display:none;"><?php echo $emoji; ?></div>
              <?php else: ?>
                <div class="ptile-emoji"><?php echo $emoji; ?></div>
              <?php endif; ?>

              <div class="ptile-name"><?php echo htmlspecialchars($p['name']); ?></div>
              <div class="ptile-price"><?php echo formatCurrency($p['price']); ?>/<?php echo $p['unit']; ?></div>
              <div class="ptile-stock" style="color:<?php echo $isOut?'#ef4444':($isLow?'#fbbf24':'#9ca3af'); ?>">
                <?php echo $isOut ? 'Out of stock' : ($isLow ? $p['stock_quantity'].' left' : 'Stock: '.$p['stock_quantity']); ?>
              </div>

              <!-- Inline qty stepper (shown when in cart) -->
              <div class="ptile-stepper" onclick="event.stopPropagation()">
                <button type="button" class="sq-btn" onclick="changeQty('<?php echo $p['id']; ?>',-1)">−</button>
                <span class="sq-num" id="sqnum_<?php echo $p['id']; ?>">1</span>
                <button type="button" class="sq-btn" onclick="changeQty('<?php echo $p['id']; ?>',1)">+</button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Customer details -->
        <div class="section-card">
          <div class="section-title">
            <i class="fas fa-user" style="color:var(--primary);"></i> Customer Details
          </div>
          <div class="form-row-2" style="margin-bottom:10px;">
            <div class="fld">
              <label>Customer Name *</label>
              <input type="text" name="customer_name" required placeholder="Walk-in Customer">
            </div>
            <div class="fld">
              <label>Phone Number *</label>
              <input type="tel" name="customer_phone" required placeholder="+233 XX XXX XXXX">
            </div>
          </div>
          <div class="form-row-2">
            <div class="fld">
              <label>Email <small style="font-weight:400;color:#9ca3af;">(optional)</small></label>
              <input type="email" name="customer_email" placeholder="customer@email.com">
            </div>
            <div class="fld">
              <label>Order Notes</label>
              <input type="text" name="notes" placeholder="Special instructions...">
            </div>
          </div>
        </div>

        <!-- Cart line-item preview (fills blank space, replaces empty void) -->
        <div class="cart-preview-panel" id="cartPreviewPanel" style="display:none;">
          <div class="cart-preview-title">Order Summary</div>
          <div id="cartPreviewItems"></div>
          <div style="display:flex;justify-content:space-between;font-weight:700;font-size:15px;padding-top:10px;border-top:2px solid var(--border);margin-top:4px;">
            <span>Total</span>
            <span id="previewTotal" style="color:var(--primary);">₵0.00</span>
          </div>
        </div>

      </div><!-- /pos-left -->

      <!-- ── RIGHT COLUMN ────────────────────────────────── -->
      <div class="pos-right">

        <!-- Cart panel -->
        <div class="cart-panel">
          <div class="cart-head">
            <h3><i class="fas fa-shopping-basket" style="margin-right:8px;"></i>Cart <span id="cartCount" style="font-size:12px;opacity:.7;"></span></h3>
            <button type="button" class="cart-clear" onclick="clearCart()">
              <i class="fas fa-trash-alt"></i> Clear
            </button>
          </div>

          <!-- Cart body -->
          <div class="cart-body" id="cartBody">
            <div class="cart-empty" id="cartEmpty">
              <div class="cart-empty-icon">🛒</div>
              <strong>Cart is empty</strong>
              <small>Select eggs, chicks, or broilers<br>to begin sale</small>
            </div>
          </div>

          <!-- Order summary -->
          <div class="cart-summary" id="cartSummary" style="display:none;">
            <div class="sum-row"><span>Subtotal</span><span id="sumSubtotal">₵0.00</span></div>
            <div class="sum-row total"><span>Total</span><span id="sumTotal" style="color:var(--primary);">₵0.00</span></div>
            <div id="sumChange" style="display:none;"></div>
          </div>

          <!-- Footer: payment + place order -->
          <div class="cart-foot">
            <!-- Payment method buttons -->
            <div style="font-size:12px;font-weight:600;color:#6b7280;margin-bottom:8px;">Payment Method</div>
            <div class="pay-methods" id="payMethods">
              <button type="button" class="pay-btn selected" data-method="cash" onclick="selectPay(this)">💵 Cash</button>
              <button type="button" class="pay-btn" data-method="momo_mtn" onclick="selectPay(this)">📱 MTN MoMo</button>
              <button type="button" class="pay-btn" data-method="momo_vodafone" onclick="selectPay(this)">📲 Vodafone</button>
              <button type="button" class="pay-btn" data-method="momo_airteltigo" onclick="selectPay(this)">📡 AirtelTigo</button>
            </div>

            <!-- Amount received (only for cash) -->
            <div class="amt-row" id="amtRow">
              <label>Received (₵)</label>
              <input type="number" name="payment_received" id="amountReceived" class="amt-input"
                     min="0" step="0.01" placeholder="0.00" oninput="calcChange()">
            </div>

            <button type="submit" class="place-btn" id="placeOrderBtn" disabled>
              <i class="fas fa-check-circle"></i> Place Order
            </button>
          </div>
        </div>

        <!-- Recent walk-in sales -->
        <div class="recent-card no-print" style="margin-top:16px;">
          <div class="recent-head">
            <h3>Recent Walk-in Sales</h3>
            <a href="orders.php?type=walkin" class="btn btn-sm btn-secondary" style="font-size:11px;padding:5px 10px;">View All</a>
          </div>
          <?php if(empty($recentWalkins)): ?>
          <div style="text-align:center;padding:24px;color:#9ca3af;font-size:13px;">No walk-in sales yet today</div>
          <?php else: ?>
          <div class="rt-head" style="display:grid;grid-template-columns:2fr 1.5fr .5fr 1fr auto;gap:8px;">
            <span>Order</span><span>Customer</span><span>Items</span><span>Total</span><span>Time</span>
          </div>
          <?php foreach($recentWalkins as $w): ?>
          <div class="rt-row">
            <span style="font-weight:700;font-size:12px;"><?php echo $w['order_number']; ?></span>
            <span><?php echo htmlspecialchars($w['customer_name']); ?></span>
            <span style="text-align:center;font-size:12px;">
              <span style="background:#f3f4f6;border-radius:50px;padding:2px 7px;font-weight:700;"><?php echo $w['item_count']; ?></span>
            </span>
            <span style="font-weight:700;color:var(--primary);"><?php echo formatCurrency($w['total_amount']); ?></span>
            <span style="font-size:11px;color:#9ca3af;white-space:nowrap;"><?php echo date('g:ia',strtotime($w['created_at'])); ?></span>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div><!-- /pos-right -->
    </div><!-- /pos-wrap -->
    </form>

  </div>
</div>

<!-- ── RECEIPT MODAL ──────────────────────────────────────── -->
<div class="rmodal-bg no-print" id="receiptModal">
  <div class="rmodal">
    <div class="rmodal-header">
      <h2>🐔 Coopers Farm</h2>
      <small>Farm Counter · <?php echo date('M d, Y'); ?></small>
    </div>
    <div class="rmodal-body">
      <div style="display:flex;justify-content:space-between;font-size:12px;color:#888;margin-bottom:14px;padding-bottom:10px;border-bottom:1px dashed #e5e7eb;">
        <span>Order: <strong id="rOrderNum">—</strong></span>
        <span id="rDate"><?php echo date('g:i A'); ?></span>
      </div>
      <div id="receiptItemsList"></div>
      <div class="rtotal"><span>TOTAL</span><span id="rTotal">—</span></div>
      <div style="display:flex;justify-content:space-between;font-size:13px;color:#888;margin-top:4px;">
        <span>Received:</span><span id="rReceived">—</span>
      </div>
      <div class="rchange" id="rChangeRow" style="display:none;">
        <span>Change:</span><span id="rChange">—</span>
      </div>
      <div style="text-align:center;margin-top:16px;font-size:12px;color:#9ca3af;padding-top:12px;border-top:1px dashed #e5e7eb;">
        Served by: <strong><?php echo htmlspecialchars($_SESSION['name']??'Staff'); ?></strong><br>
        Thank you for shopping with us! 🙏
      </div>
    </div>
    <div class="rmodal-foot">
      <button onclick="window.print()" class="btn btn-secondary" style="flex:1;justify-content:center;"><i class="fas fa-print"></i> Print</button>
      <button onclick="document.getElementById('receiptModal').classList.remove('show')" class="btn btn-primary" style="flex:1;justify-content:center;">Done</button>
    </div>
  </div>
</div>

<script src="../public/js/admin.js"></script>
<script>
// ── Cart state ────────────────────────────────────────────────
const cart = {}; // { id: { name, price, qty, unit, emoji, stock } }

// ── Tile click ────────────────────────────────────────────────
function tileClick(tile) {
  const id    = tile.dataset.id;
  const stock = parseInt(tile.dataset.stock, 10);
  if (cart[id]) {
    if (cart[id].qty >= stock) { showToast('Only ' + stock + ' in stock!', 'warn'); return; }
    cart[id].qty++;
  } else {
    cart[id] = { name:tile.dataset.name, price:parseFloat(tile.dataset.price), qty:1, unit:tile.dataset.unit, emoji:tile.dataset.emoji, stock };
  }
  tile.style.transform = 'scale(0.93)';
  setTimeout(() => tile.style.transform = '', 140);
  refreshCart();
}

// ── Qty change (from both cart rows and tile steppers) ────────
function changeQty(id, delta) {
  if (!cart[id]) return;
  const nq = cart[id].qty + delta;
  if (nq <= 0)              { delete cart[id]; }
  else if (nq > cart[id].stock) { showToast('Only ' + cart[id].stock + ' in stock!', 'warn'); return; }
  else                           { cart[id].qty = nq; }
  refreshCart();
}
function removeItem(id) { delete cart[id]; refreshCart(); }
function clearCart()    { Object.keys(cart).forEach(k => delete cart[k]); refreshCart(); }

// ── Main render ───────────────────────────────────────────────
function refreshCart() {
  const cartBody   = document.getElementById('cartBody');
  const cartEmpty  = document.getElementById('cartEmpty');
  const inputsEl   = document.getElementById('cartInputsHolder');
  const totalEl    = document.getElementById('sumTotal');
  const subtotalEl = document.getElementById('sumSubtotal');
  const countEl    = document.getElementById('cartCount');
  const placeBtn   = document.getElementById('placeOrderBtn');
  const summaryEl  = document.getElementById('cartSummary');

  const entries = Object.entries(cart);
  if (!entries.length) {
    cartBody.innerHTML = ''; cartBody.appendChild(cartEmpty); cartEmpty.style.display = 'flex';
    inputsEl.innerHTML = ''; summaryEl.style.display = 'none';
    countEl.textContent = ''; placeBtn.disabled = true;
    // Clear tile states
    document.querySelectorAll('.ptile').forEach(t => {
      t.classList.remove('p-selected');
      const b = document.getElementById('tileqty_' + t.dataset.id);
      if (b) b.textContent = '1';
      const sn = document.getElementById('sqnum_' + t.dataset.id);
      if (sn) sn.textContent = '1';
    });
    updatePreviewPanel([], 0);
    calcChange();
    return;
  }

  cartEmpty.style.display = 'none';
  let rowsHtml = '', inputsHtml = '', total = 0, totalItems = 0, idx = 0;

  entries.forEach(([id, item]) => {
    const sub = item.price * item.qty;
    total += sub; totalItems += item.qty;
    rowsHtml += `
      <div class="cart-row" id="crow_${id}">
        <div class="crt-emoji">${item.emoji}</div>
        <div class="crt-info">
          <div class="crt-name">${item.name}</div>
          <div class="crt-sub">₵${item.price.toFixed(2)} × ${item.qty} = <strong>₵${sub.toFixed(2)}</strong></div>
        </div>
        <div class="qty-ctrl">
          <button type="button" class="qty-btn" data-id="${id}" data-delta="-1">−</button>
          <span class="qty-num">${item.qty}</span>
          <button type="button" class="qty-btn" data-id="${id}" data-delta="1">+</button>
        </div>
        <button type="button" class="crt-del" data-del="${id}"><i class="fas fa-times"></i></button>
      </div>`;
    inputsHtml += `<input type="hidden" name="items[${idx}][product_id]" value="${id}">`;
    inputsHtml += `<input type="hidden" name="items[${idx}][quantity]" value="${item.qty}">`;
    idx++;
  });

  cartBody.innerHTML = rowsHtml;
  inputsEl.innerHTML = inputsHtml;

  // Bind events
  cartBody.querySelectorAll('.qty-btn').forEach(btn => btn.addEventListener('click', () => changeQty(btn.dataset.id, parseInt(btn.dataset.delta))));
  cartBody.querySelectorAll('.crt-del').forEach(btn => btn.addEventListener('click', () => removeItem(btn.dataset.del)));

  // Totals
  totalEl.textContent    = '₵' + total.toFixed(2);
  subtotalEl.textContent = '₵' + total.toFixed(2);
  summaryEl.style.display = 'block';
  countEl.textContent = `(${totalItems})`;
  placeBtn.disabled = false;

  // Tile states + inline steppers
  document.querySelectorAll('.ptile').forEach(t => {
    const id = t.dataset.id;
    const b  = document.getElementById('tileqty_' + id);
    const sn = document.getElementById('sqnum_' + id);
    if (cart[id]) {
      t.classList.add('p-selected');
      if (b)  b.textContent  = cart[id].qty;
      if (sn) sn.textContent = cart[id].qty;
    } else {
      t.classList.remove('p-selected');
      if (b)  b.textContent  = '1';
      if (sn) sn.textContent = '1';
    }
  });

  calcChange();
  updatePreviewPanel(entries, total);
}

// ── Preview panel (fills blank left-column space) ─────────────
function updatePreviewPanel(entries, total) {
  const panel = document.getElementById('cartPreviewPanel');
  const items = document.getElementById('cartPreviewItems');
  const prev  = document.getElementById('previewTotal');
  if (!entries.length) { panel.style.display = 'none'; return; }
  panel.style.display = 'block';
  items.innerHTML = entries.map(([id,item]) => `
    <div class="cpv-row">
      <span>${item.emoji} ${item.name} <span style="color:#9ca3af;">×${item.qty}</span></span>
      <strong>₵${(item.price*item.qty).toFixed(2)}</strong>
    </div>`).join('');
  prev.textContent = '₵' + total.toFixed(2);
}

// ── Change calc ───────────────────────────────────────────────
function calcChange() {
  const totalText = document.getElementById('sumTotal')?.textContent || '₵0';
  const total     = parseFloat(totalText.replace('₵','')) || 0;
  const received  = parseFloat(document.getElementById('amountReceived').value) || 0;
  const changeDiv = document.getElementById('sumChange');
  const rChangeRow= document.getElementById('rChangeRow');
  if (received > 0 && total > 0) {
    const diff = received - total;
    const cls  = diff < 0 ? 'change-short' : 'change-ok';
    const label= diff < 0 ? `SHORT ₵${Math.abs(diff).toFixed(2)}` : `Change ₵${diff.toFixed(2)}`;
    changeDiv.className = 'sum-row ' + cls;
    changeDiv.innerHTML = `<span>${diff < 0 ? '⚠ Amount short' : '✓ Change due'}</span><strong>${label}</strong>`;
    changeDiv.style.display = 'flex';
  } else {
    changeDiv.style.display = 'none';
  }
}

// ── Payment method selection ──────────────────────────────────
function selectPay(btn) {
  document.querySelectorAll('.pay-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('payMethodInput').value = btn.dataset.method;
  // Hide amount-received field for non-cash (still let them enter)
  const amtRow = document.getElementById('amtRow');
  amtRow.style.opacity = btn.dataset.method === 'cash' ? '1' : '0.5';
}

// ── Category filter ───────────────────────────────────────────
function filterCat(cat, el) {
  document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.ptile').forEach(t => {
    t.style.display = (cat === 'all' || t.dataset.cat === cat) ? '' : 'none';
  });
}

// ── Receipt ───────────────────────────────────────────────────
const lastOrder = {
  num     : <?php echo json_encode($newOrderNum); ?>,
  total   : <?php echo (float)$orderTotal; ?>,
  received: <?php echo (float)$orderReceived; ?>,
  change  : <?php echo max(0,(float)$orderReceived-(float)$orderTotal); ?>,
  items   : <?php echo json_encode($receiptItems); ?>
};

function openReceipt() {
  const d = document.getElementById;
  d('rOrderNum').textContent = lastOrder.num || '—';
  d('rTotal').textContent    = '₵' + lastOrder.total.toFixed(2);
  d('rReceived').textContent = lastOrder.received > 0 ? '₵' + lastOrder.received.toFixed(2) : '—';
  const rch = document.getElementById('rChange');
  const rchRow = document.getElementById('rChangeRow');
  if (lastOrder.received > 0) {
    rch.textContent = '₵' + lastOrder.change.toFixed(2);
    rchRow.style.display = 'flex';
  }
  let html = '';
  if (lastOrder.items.length > 0) {
    lastOrder.items.forEach(it => {
      html += `<div class="rline"><span>${it.emoji || ''} ${it.name} ×${it.qty}</span><span>₵${it.sub.toFixed(2)}</span></div>`;
    });
  } else {
    Object.values(cart).forEach(item => {
      html += `<div class="rline"><span>${item.emoji} ${item.name} ×${item.qty}</span><span>₵${(item.price*item.qty).toFixed(2)}</span></div>`;
    });
  }
  document.getElementById('receiptItemsList').innerHTML = html;
  document.getElementById('receiptModal').classList.add('show');
}

<?php if($newOrderNum): ?>
window.addEventListener('DOMContentLoaded', () => setTimeout(openReceipt, 350));
<?php endif; ?>

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type = 'info') {
  const d = document.createElement('div');
  const color = type === 'warn' ? '#fbbf24' : '#4A7C59';
  d.style.cssText = `position:fixed;bottom:28px;right:28px;z-index:9999;background:white;padding:12px 20px;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.15);font-size:13px;font-weight:600;border-left:4px solid ${color};`;
  d.textContent = msg;
  document.body.appendChild(d);
  setTimeout(() => d.remove(), 2500);
}
</script>
</body>
</html>
