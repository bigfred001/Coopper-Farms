<?php
ob_start();   // stray output is discarded before any JSON reply
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

define('SP_CASHIER_DISCOUNT_MAX', 5);   // % a cashier may discount without a manager
define('SP_DELIVERY_FEE', 10);          // standard delivery fee (GHS)
define('SP_WAIVE_UNITS', 10);           // a delivery with this many units or more has no fee
$isManager = in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true);

function spJson(array $payload): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        error_log('POS: could not encode reply: ' . json_last_error_msg());
        $json = json_encode(['ok' => false, 'error' => 'The reply could not be encoded. Check Recent sales.']);
    }
    echo $json;
    exit;
}

// A customer by phone (last 9 digits), or a new one. New customers get the same placeholder account as the customers page.
function spCustomer(PDO $pdo, string $name, string $phone, string $email, string $location): array {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) >= 9) {
        $s = $pdo->prepare("SELECT id, name, phone, email FROM users
            WHERE role='customer' AND RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = ? LIMIT 1");
        $s->execute([substr($digits, -9)]);
        if ($row = $s->fetch()) return $row;
    }
    if ($email !== '') {
        $e = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $e->execute([$email]);
        if ($e->fetch()) throw new RuntimeException('That email belongs to another customer.');
    } else {
        $email = 'walkin_' . time() . $digits . '@coopersfarm.local';
    }
    $hash = password_hash('walkin_' . time(), PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (name, email, phone, location, password_hash, role, is_active)
        VALUES (?,?,?,?,?,'customer',1)")->execute([$name, $email, $phone, $location ?: null, $hash]);
    return ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'phone' => $phone, 'email' => $email];
}

// ── POST: complete a sale (AJAX, JSON response) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_sale'])) {
    $fail = function (string $m) { spJson(['ok' => false, 'error' => $m]); };

    $items    = json_decode($_POST['items'] ?? '[]', true);
    $payment  = $_POST['payment_method'] ?? 'cash';
    $dType    = $_POST['discount_type'] ?? 'none';
    $dValue   = (float)($_POST['discount_value'] ?? 0);
    $custId   = (int)($_POST['customer_id'] ?? 0);
    $ref      = sanitize($_POST['reference'] ?? '');
    $received = (float)($_POST['received'] ?? 0);
    $fulfil   = ($_POST['fulfilment'] ?? 'pickup') === 'delivery' ? 'delivery' : 'pickup';
    $timing   = ($_POST['pay_timing'] ?? 'now') === 'later' ? 'later' : 'now';
    $cName    = sanitize($_POST['cust_name'] ?? '');
    $cPhone   = sanitize($_POST['cust_phone'] ?? '');
    $cEmail   = sanitize($_POST['cust_email'] ?? '');
    $address  = sanitize($_POST['delivery_address'] ?? '');
    $cNotes   = sanitize($_POST['cust_notes'] ?? '');

    if (!is_array($items) || empty($items))                       $fail('Add at least one product to the sale.');
    if (!in_array($payment, ['cash','momo_mtn','momo_vodafone','momo_airteltigo'], true)) $fail('Choose a payment method.');
    if (!in_array($dType, ['none','percent','fixed'], true))      $fail('Unknown discount type.');
    if ($fulfil === 'delivery' && $custId <= 0 && ($cName === '' || $cPhone === '')) $fail('A delivery needs the customer\'s name and phone number.');
    if ($fulfil === 'delivery' && $address === '')                $fail('Enter the delivery address.');

    $pdo->beginTransaction();
    try {
        // Prices and stock come from the database, never from the browser
        $subtotal = 0.0; $units = 0; $lines = []; $stockAfter = [];
        foreach ($items as $it) {
            $pid = (int)($it['id'] ?? 0); $qty = (int)($it['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) throw new RuntimeException('An item in the sale is invalid.');
            $ps = $pdo->prepare("SELECT p.*, COALESCE(f.quantity, p.stock_quantity) AS live_stock
                FROM products p LEFT JOIN flocks f ON f.id = p.flock_id WHERE p.id=? AND p.is_active=1 FOR UPDATE");
            $ps->execute([$pid]);
            $prod = $ps->fetch();
            if (!$prod) throw new RuntimeException('A product in the sale is no longer available.');
            if ((int)$prod['live_stock'] < $qty)
                throw new RuntimeException("Only {$prod['live_stock']} of {$prod['name']} left in stock.");
            $line = (float)$prod['price'] * $qty;
            $subtotal += $line; $units += $qty;
            $lines[] = ['prod' => $prod, 'qty' => $qty, 'line' => $line];
        }

        // Discount, with a manager approval limit for cashiers
        $discount = 0.0; $pct = 0.0;
        if ($dType === 'percent') {
            if ($dValue < 0 || $dValue > 100) throw new RuntimeException('Percentage must be between 0 and 100.');
            $discount = round($subtotal * $dValue / 100, 2); $pct = $dValue;
        } elseif ($dType === 'fixed') {
            if ($dValue < 0) throw new RuntimeException('Discount cannot be negative.');
            $discount = round(min($dValue, $subtotal), 2);
            $pct = $subtotal > 0 ? $discount / $subtotal * 100 : 0;
        }
        if ($discount > 0 && !$isManager && $pct > SP_CASHIER_DISCOUNT_MAX + 0.0001)
            throw new RuntimeException('Discounts above ' . SP_CASHIER_DISCOUNT_MAX . '% need a manager to approve.');

        // Delivery fee: standard, waived at 10 units or more
        $fee = ($fulfil === 'delivery' && $units < SP_WAIVE_UNITS) ? (float)SP_DELIVERY_FEE : 0.0;
        $total = round($subtotal - $discount + $fee, 2);
        if ($total <= 0) throw new RuntimeException('The total must be more than zero.');

        $paidNow = !($fulfil === 'delivery' && $timing === 'later');
        if ($paidNow) {
            if ($payment === 'cash') {
                if ($received < $total) throw new RuntimeException('Amount received is less than the total.');
                $change = round($received - $total, 2);
            } else {
                $received = $total; $change = 0.0;
            }
        } else {
            $received = 0.0; $change = 0.0;   // collected on delivery
        }

        // Customer: an existing one, or a new one from the form
        $cust = null;
        if ($custId > 0) {
            $cs = $pdo->prepare("SELECT id, name, phone, email FROM users WHERE id=? AND role='customer'");
            $cs->execute([$custId]);
            $cust = $cs->fetch() ?: null;
        } elseif ($cName !== '') {
            $cust = spCustomer($pdo, $cName, $cPhone, $cEmail, $address);
        }
        $custName  = $cust ? $cust['name'] : 'Walk-in Customer';
        $custPhone = $cust ? (string)$cust['phone'] : '';
        $custEmail = $cust ? (string)$cust['email'] : '';

        $noteParts = [];
        if ($discount > 0) $noteParts[] = 'Discount ' . number_format($discount, 2) . ' (' . round($pct, 1) . '%)';
        if ($fulfil === 'delivery') {
            $noteParts[] = $fee > 0 ? 'Delivery fee ' . number_format($fee, 2) : 'Delivery fee waived (' . $units . ' units)';
            if ($cNotes !== '') $noteParts[] = 'Note: ' . $cNotes;
        }
        if ($ref !== '') $noteParts[] = 'Ref: ' . $ref;
        $notes = implode('; ', $noteParts);

        $orderNum  = generateOrderNumber();
        $orderType = $fulfil === 'delivery' ? 'delivery' : 'walkin';
        $orderStat = $fulfil === 'delivery' ? 'confirmed' : 'delivered';
        $payStat   = $paidNow ? 'paid' : 'pending';
        $location  = $fulfil === 'delivery' ? $address : 'Farm Counter';

        $pdo->prepare("INSERT INTO orders (order_number, user_id, total_amount, delivery_fee, payment_method, payment_status,
            order_status, customer_name, customer_phone, customer_email, notes, order_type, served_by, payment_received, delivery_address)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$orderNum, $cust ? $cust['id'] : null, $total, $fee, $payment, $payStat, $orderStat,
                $custName, $custPhone, $custEmail, $notes, $orderType, $_SESSION['user_id'], $received, $location]);
        $orderId = (int)$pdo->lastInsertId();

        $receiptLines = [];
        foreach ($lines as $ln) {
            $prod = $ln['prod']; $qty = $ln['qty'];
            $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, subtotal)
                VALUES (?,?,?,?,?,?)")
                ->execute([$orderId, $prod['id'], $prod['name'], $qty, $prod['price'], $ln['line']]);
            if (!empty($prod['flock_id'])) {
                $pdo->prepare("UPDATE flocks SET quantity = GREATEST(0, quantity - ?) WHERE id=?")
                    ->execute([$qty, (int)$prod['flock_id']]);
            } else {
                $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id=?")->execute([$qty, $prod['id']]);
                if (in_array($prod['category'], ['broiler', 'layer'], true)) {
                    $pdo->prepare("UPDATE flocks SET quantity = GREATEST(0, quantity - ?) WHERE bird_type=? AND status='active' LIMIT 1")
                        ->execute([$qty, $prod['category']]);
                }
            }
            $pdo->prepare("INSERT INTO inventory_logs (product_id, change_amount, reason, notes, created_by)
                VALUES (?,?,'sale',?,?)")->execute([$prod['id'], -$qty, "Sale {$orderNum}", $_SESSION['user_id']]);
            $stockAfter[(string)$prod['id']] = (int)$prod['live_stock'] - $qty;
            $receiptLines[] = ['name' => $prod['name'], 'unit' => $prod['unit'], 'qty' => $qty,
                               'price' => (float)$prod['price'], 'line' => $ln['line']];
        }

        // Money is recorded in the sales table when it is received
        if ($paidNow) {
            $pdo->prepare("INSERT INTO sales (order_id, sale_date, total_amount, payment_method) VALUES (?,CURDATE(),?,?)")
                ->execute([$orderId, $total, $payment]);
        }
        $pdo->commit();
        auditLog($pdo, 'walkin_order', 'order', $orderId,
            "Sale {$orderNum} to {$custName} ({$fulfil}, " . ($paidNow ? 'paid now' : 'pay on delivery') . "). Total " . number_format($total, 2));

        $resp = [
            'ok' => true,
            'receipt' => [
                'number' => $orderNum, 'date' => date('d M Y, g:i a'), 'lines' => $receiptLines,
                'subtotal' => round($subtotal, 2), 'discount' => $discount, 'fee' => $fee, 'total' => $total,
                'received' => $received, 'change' => $change, 'payment' => $payment,
                'fulfilment' => $fulfil, 'paid_now' => $paidNow, 'address' => $fulfil === 'delivery' ? $address : '',
                'customer' => $custName, 'cashier' => $_SESSION['name'] ?? 'Staff',
            ],
            'stock' => $stockAfter,
        ];
        spJson($resp);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        spJson(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('POS sale failed: ' . $e->getMessage());
        spJson(['ok' => false, 'error' => 'The sale could not be saved. Please try again.']);
    }
}

// ── Page data ─────────────────────────────────────────────────
$products = $pdo->query("SELECT p.id, p.name, p.category, p.unit, p.price, COALESCE(f.quantity, p.stock_quantity) AS stock_quantity, p.image_url
    FROM products p LEFT JOIN flocks f ON f.id = p.flock_id WHERE p.is_active=1 ORDER BY p.category, p.name")->fetchAll();
$productData = array_map(fn($p) => [
    'id' => (int)$p['id'], 'name' => $p['name'], 'category' => $p['category'], 'unit' => $p['unit'],
    'price' => (float)$p['price'], 'stock' => (int)$p['stock_quantity'], 'image' => $p['image_url'] ?: null,
], $products);

$customers = $pdo->query("SELECT id, name, phone, email, location FROM users WHERE role='customer' ORDER BY name LIMIT 500")->fetchAll();

$recentRows = $pdo->query("SELECT id, order_number, total_amount, payment_method, payment_received, customer_name, created_at
    FROM orders WHERE COALESCE(order_type,'walkin') IN ('walkin','delivery') ORDER BY created_at DESC LIMIT 20")->fetchAll();
$recent = [];
foreach ($recentRows as $r) {
    $it = $pdo->prepare("SELECT product_name, quantity, unit_price, subtotal FROM order_items WHERE order_id=?");
    $it->execute([$r['id']]);
    $recent[] = [
        'number' => $r['order_number'], 'total' => (float)$r['total_amount'], 'payment' => $r['payment_method'],
        'received' => (float)$r['payment_received'], 'customer' => $r['customer_name'],
        'date' => date('d M Y, g:i a', strtotime($r['created_at'])),
        'lines' => array_map(fn($x) => ['name' => $x['product_name'], 'qty' => (int)$x['quantity'],
                                       'price' => (float)$x['unit_price'], 'line' => (float)$x['subtotal']], $it->fetchAll()),
    ];
}

$todaySales = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS rev FROM orders
    WHERE COALESCE(order_type,'walkin')='walkin' AND DATE(created_at)=CURDATE()")->fetch();

$pageData = [
    'products'    => $productData,
    'customers'   => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'phone' => (string)$c['phone'],
                                          'email' => (string)$c['email'], 'location' => (string)($c['location'] ?? '')], $customers),
    'recent'      => $recent,
    'isManager'   => $isManager,
    'discountMax' => SP_CASHIER_DISCOUNT_MAX,
    'delivery'    => ['fee' => SP_DELIVERY_FEE, 'waiveUnits' => SP_WAIVE_UNITS],
];
$catCounts = [];
foreach ($productData as $p) { $catCounts[$p['category']] = ($catCounts[$p['category']] ?? 0) + 1; }
$lowCount = count(array_filter($productData, fn($p) => $p['stock'] > 0 && $p['stock'] <= 10));
$outCount = count(array_filter($productData, fn($p) => $p['stock'] <= 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Point of Sale — Coopers Admin</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../public/css/admin.css?v=8">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
<?php include 'includes/topbar.php'; ?>
<div class="dashboard-content sp">

  <div class="sp-top">
    <div class="sp-title">
      <h1>Point of Sale</h1>
      <span class="sp-served">Cashier · <?php echo htmlspecialchars($_SESSION['name'] ?? 'Staff'); ?></span>
    </div>
    <div class="sp-today"><strong><?php echo (int)$todaySales['n']; ?></strong> sales today · <strong>GH₵<?php echo number_format((float)$todaySales['rev'], 2); ?></strong></div>
    <div class="sp-top-actions">
      <button class="sp-btn" type="button" data-open="recent"><i class="fas fa-receipt"></i> Recent sales</button>
      <button class="sp-btn" type="button" data-open="held"><i class="fas fa-pause"></i> Held <span id="spHeldN">0</span></button>
    </div>
  </div>

  <div class="sp-body">

    <aside class="sp-cats">
      <div class="sp-label">Categories</div>
      <button class="sp-cat active" type="button" data-cat="all"><span>All products</span><em><?php echo count($productData); ?></em></button>
      <?php
        $catNames = ['eggs'=>['🥚','Eggs'], 'birds'=>['🐔','Birds'], 'byproduct'=>['🌾','Byproducts'], 'other'=>['📦','Other']];
        $birdCount = ($catCounts['broiler'] ?? 0) + ($catCounts['layer'] ?? 0);
        foreach ($catNames as $key => [$ico, $label]):
          $n = $key === 'birds' ? $birdCount : ($catCounts[$key] ?? 0);
          if ($n === 0) continue;
      ?>
      <button class="sp-cat" type="button" data-cat="<?php echo $key; ?>"><span><?php echo $ico; ?> <?php echo $label; ?></span><em><?php echo $n; ?></em></button>
      <?php endforeach; ?>
    </aside>

    <section class="sp-main">
      <div class="sp-search">
        <i class="fas fa-search"></i>
        <input id="spSearch" type="search" placeholder="Search products…  (F2)" autocomplete="off">
      </div>
      <div class="sp-chips">
        <button class="sp-chip active" type="button" data-filter="all">All</button>
        <button class="sp-chip warn" type="button" data-filter="low">Low stock <b><?php echo $lowCount; ?></b></button>
        <button class="sp-chip bad" type="button" data-filter="out">Out of stock <b><?php echo $outCount; ?></b></button>
      </div>
      <div class="sp-grid" id="spGrid"></div>
    </section>

    <aside class="sp-cart">
      <div class="sp-cart-head">
        <div>
          <div class="sp-cart-title">Current sale</div>
          <div class="sp-cust" id="spCustLabel">Walk-in customer</div>
          <div class="sp-cust" id="spFulLabel"></div>
        </div>
        <button class="sp-link" type="button" data-open="customer">Customer</button>
      </div>
      <div class="sp-custfields">
        <div class="sp-cf-row">
          <input type="text" id="pcName" placeholder="Customer name *" maxlength="120" autocomplete="off">
          <input type="tel" id="pcPhone" placeholder="Phone number *" maxlength="30" autocomplete="off">
        </div>
        <div class="sp-cf-row">
          <input type="email" id="pcEmail" placeholder="Email" maxlength="120" autocomplete="off">
          <input type="text" id="pcAddress" placeholder="Delivery address" maxlength="255" autocomplete="off">
        </div>
        <input type="text" id="pcNotes" placeholder="Other details (gate code, preferred time…)" maxlength="250" autocomplete="off">
        <div class="sp-cf-match" id="pcMatch"></div>
      </div>
      <div class="sp-lines" id="spLines"></div>
      <div class="sp-totals">
        <div><span>Subtotal</span><strong id="spSub">GH₵0.00</strong></div>
        <div><span>Discount</span><strong id="spDisc">GH₵0.00</strong></div>
        <div id="spFeeRow" style="display:none;"><span>Delivery fee</span><strong id="spFee">GH₵0.00</strong></div>
        <div class="sp-grand"><span>Total</span><strong id="spTotal">GH₵0.00</strong></div>
      </div>
      <div class="sp-cart-actions">
        <button class="sp-btn" type="button" id="spHold"><i class="fas fa-pause"></i> Hold</button>
        <button class="sp-btn" type="button" id="spClear"><i class="fas fa-trash-alt"></i> Clear</button>
      </div>
      <button class="sp-checkout" type="button" id="spCheckout" disabled>Checkout <span id="spCheckAmt">GH₵0.00</span> <kbd>F4</kbd></button>
    </aside>
  </div>
</div>
</div>

<!-- Customer: find an existing one, or enter a new one -->
<div class="modal-overlay" id="spCustModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header"><h3>Customer</h3><button class="modal-close" type="button" data-close><i class="fas fa-times"></i></button></div>
    <div style="padding:16px 24px 22px;max-height:70vh;overflow:auto;">
      <div class="sp-field-label">Find an existing customer</div>
      <input type="search" id="spCustSearch" class="form-control" placeholder="Search name or phone…" autocomplete="off">
      <div class="sp-custlist" id="spCustList"></div>

      <div class="sp-field-label">Or enter a new customer</div>
      <div class="form-row">
        <div class="form-group"><label>Name *</label><input type="text" id="ncName" class="form-control" maxlength="120"></div>
        <div class="form-group"><label>Phone *</label><input type="tel" id="ncPhone" class="form-control" maxlength="30"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Email</label><input type="email" id="ncEmail" class="form-control" maxlength="120"></div>
        <div class="form-group"><label>Delivery address</label><input type="text" id="ncAddress" class="form-control" maxlength="255"></div>
      </div>
      <div class="form-group"><label>Notes</label><input type="text" id="ncNotes" class="form-control" maxlength="250" placeholder="e.g. gate code, preferred time"></div>
      <div class="lot-flag lot-expired" id="ncError" style="display:none;"></div>
      <button type="button" class="sp-complete" id="ncUse" style="margin-top:10px;">Use this customer</button>
    </div>
  </div>
</div>

<!-- Checkout -->
<div class="modal-overlay" id="spCheckoutModal">
  <div class="modal co-modal">
    <div class="co-head">
      <div><h3>Checkout</h3><small id="coSub">Current sale</small></div>
      <button class="modal-close" type="button" data-close><i class="fas fa-times"></i></button>
    </div>
    <div class="co-body">

      <div class="co-summary">
        <div><span>Subtotal</span><strong id="coSubtotal">GH₵0.00</strong></div>
        <div id="coFeeRow" style="display:none;"><span>Delivery fee</span><strong id="coFeeAmt">GH₵0.00</strong></div>
        <div id="coDiscRow" style="display:none;"><span>Discount</span><strong id="coDiscAmt">−GH₵0.00</strong></div>
        <div class="co-total"><span>Total to pay</span><strong id="coTotal">GH₵0.00</strong></div>
      </div>

      <div class="co-label">Sale type</div>
      <div class="co-seg">
        <button type="button" class="co-seg-btn active" data-ful="pickup"><i class="fas fa-store"></i> Pickup</button>
        <button type="button" class="co-seg-btn" data-ful="delivery"><i class="fas fa-truck"></i> Delivery</button>
      </div>

      <div id="coDeliveryBox" style="display:none;">
        <div class="co-label">Customer &amp; delivery</div>
        <div class="co-field"><i class="fas fa-search"></i>
          <input type="text" id="coCustSearch" placeholder="Search customer or enter phone number" autocomplete="off">
        </div>
        <div class="co-hits" id="coCustHits"></div>
        <div class="co-field co-field-plain">
          <input type="text" id="coAddress" maxlength="255" placeholder="Delivery address">
        </div>
        <div class="sp-hint" id="coFeeHint"></div>
        <div class="co-label">When is payment taken?</div>
        <div class="co-seg">
          <button type="button" class="co-seg-btn active" data-timing="now">Pay now</button>
          <button type="button" class="co-seg-btn" data-timing="later">On delivery</button>
        </div>
      </div>

      <div id="coPayBox">
        <div class="co-label">Payment method</div>
        <div class="co-pay">
          <button type="button" class="co-pay-btn active" data-pay="cash"><i class="fas fa-money-bill-wave"></i><span>Cash</span></button>
          <button type="button" class="co-pay-btn" data-pay="momo_mtn"><i class="fas fa-mobile-alt"></i><span>MTN MoMo</span></button>
          <button type="button" class="co-pay-btn" data-pay="momo_vodafone"><i class="fas fa-mobile-alt"></i><span>Telecel Cash</span></button>
          <button type="button" class="co-pay-btn" data-pay="momo_airteltigo"><i class="fas fa-mobile-alt"></i><span>AirtelTigo Money</span></button>
        </div>

        <div id="coCashBox">
          <div class="co-label">Amount received (GHS)</div>
          <div class="co-quick">
            <button type="button" data-quick="exact">Exact</button>
            <button type="button" data-quick="10">+10</button>
            <button type="button" data-quick="20">+20</button>
            <button type="button" data-quick="50">+50</button>
          </div>
          <div class="co-field co-field-plain co-amount"><input type="number" id="coReceived" min="0" step="0.01" placeholder="0.00"></div>
          <div class="co-change"><span>Change due</span><strong id="coChange">GH₵0.00</strong></div>
        </div>
        <div id="coMomoBox" style="display:none;">
          <div class="co-label">Transaction reference (optional)</div>
          <div class="co-field co-field-plain"><input type="text" id="coRef" placeholder="e.g. MoMo transaction ID"></div>
        </div>
      </div>

      <div class="sp-hint" id="coLaterNote" style="display:none;">Payment is taken on delivery. The delivery stays open until it is delivered and paid.</div>

      <details class="co-disc">
        <summary>Add discount</summary>
        <div class="sp-disc-row">
          <select id="coDType" class="form-control">
            <option value="none">No discount</option>
            <option value="percent">Percentage %</option>
            <option value="fixed">Fixed amount</option>
          </select>
          <input type="number" id="coDVal" class="form-control" min="0" step="0.01" value="0" disabled>
        </div>
        <div class="sp-hint" id="coDHint"></div>
      </details>

      <div class="lot-flag lot-expired" id="coError" style="display:none;"></div>
      <button type="button" class="co-complete" id="coComplete" disabled><i class="fas fa-check-circle"></i> Complete sale · <span id="coBtnAmt">GH₵0.00</span></button>
      <div class="co-note">Totals are worked out on the server when the sale is completed.</div>
    </div>
  </div>
</div>

<!-- Receipt -->
<div class="modal-overlay" id="spReceiptModal">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header"><h3>Sale complete</h3><button class="modal-close" type="button" data-close><i class="fas fa-times"></i></button></div>
    <div style="padding:14px 24px 6px;"><div class="sp-receipt" id="spReceipt"></div></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" id="rcPrint"><i class="fas fa-print"></i> Print</button>
      <button type="button" class="btn btn-primary" id="rcNew"><i class="fas fa-plus"></i> New sale</button>
    </div>
  </div>
</div>

<!-- Recent sales -->
<div class="modal-overlay" id="spRecentModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><h3>Recent sales</h3><button class="modal-close" type="button" data-close><i class="fas fa-times"></i></button></div>
    <div style="padding:12px 24px 20px;max-height:60vh;overflow:auto;" id="spRecentList"></div>
  </div>
</div>

<!-- Held sales -->
<div class="modal-overlay" id="spHeldModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header"><h3>Held sales</h3><button class="modal-close" type="button" data-close><i class="fas fa-times"></i></button></div>
    <div style="padding:12px 24px 20px;" id="spHeldList"></div>
  </div>
</div>

<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
