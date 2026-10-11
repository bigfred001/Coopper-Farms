<?php
require_once '../config/config.php';
requireAdmin();

// ── Phone normaliser (strips non-digits, normalises to 0XXXXXXXXX) ─
function normalPhone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) === 12 && str_starts_with($d,'233')) $d = '0' . substr($d, 3);
    return $d;
}

// ── POST handlers ────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_walkin'])) {
        $name     = sanitize($_POST['name']);
        $phone    = sanitize($_POST['phone']);
        $email    = sanitize($_POST['email'] ?? '');
        $location = sanitize($_POST['location'] ?? '');
        $notes    = sanitize($_POST['notes'] ?? '');
        if (!$name || !$phone) {
            $msg = 'error:Name and phone are required.';
        } else {
            $normPhone  = normalPhone($phone);
            // Check phone (both original and normalised format)
            $checkPhone = $pdo->prepare("SELECT id, name FROM users WHERE (phone=? OR phone=?) AND role='customer'");
            $checkPhone->execute([$phone, $normPhone]);
            $existPhone = $checkPhone->fetch();
            // Check email (skip placeholder addresses)
            $existEmail = null;
            if ($email && !str_ends_with($email, '@coopersfarm.local')) {
                $checkEmail = $pdo->prepare("SELECT id, name FROM users WHERE email=? AND role='customer'");
                $checkEmail->execute([$email]);
                $existEmail = $checkEmail->fetch();
            }
            if ($existPhone) {
                $msg = 'error:⚠️ Phone number already in use by <strong>' . htmlspecialchars($existPhone['name']) . '</strong>. Check for duplicates before adding.';
            } elseif ($existEmail) {
                $msg = 'error:⚠️ Email already registered to <strong>' . htmlspecialchars($existEmail['name']) . '</strong>. Use a different email or leave it blank.';
            } else {
                if (!$email) $email = 'walkin_' . time() . '@coopersfarm.local';
                $hash = password_hash('walkin_' . time(), PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (name, email, phone, location, password_hash, role, is_active) VALUES (?,?,?,?,?,'customer',1)")
                    ->execute([$name, $email, $phone, $location, $hash]);
                auditLog($pdo, 'add_customer', 'customer', (int)$pdo->lastInsertId(), "Added walk-in customer: $name");
                $msg = "success:Customer <strong>$name</strong> added!";
            }
        }
        header('Location: customers.php?msg=' . urlencode($msg)); exit;
    }

    if (isset($_POST['edit_customer'])) {
        $cid      = (int)$_POST['customer_id'];
        $name     = sanitize($_POST['name']);
        $phone    = sanitize($_POST['phone']);
        $email    = sanitize($_POST['email'] ?? '');
        $location = sanitize($_POST['location'] ?? '');
        $pdo->prepare("UPDATE users SET name=?,phone=?,email=?,location=? WHERE id=?")
            ->execute([$name, $phone, $email, $location, $cid]);
        auditLog($pdo, 'edit_customer', 'customer', $cid, "Updated customer: $name");
        $msg = 'success:Customer updated!';
        header('Location: customers.php?msg=' . urlencode($msg)); exit;
    }
}

// ── Filters + Sorting ────────────────────────────────────────
$urlMsg      = $_GET['msg']    ?? '';
$search      = $_GET['search'] ?? '';
$filterTab   = $_GET['tab']    ?? 'all';
$filterBehav = $_GET['behav']  ?? '';     // has_orders, high_value, repeat, inactive
$sortBy      = $_GET['sort']   ?? 'total_spent';
$sortDir     = $_GET['dir']    ?? 'desc';
$viewId      = (int)($_GET['view'] ?? 0);

$allowedSort = ['name','total_spent','order_count','last_order','created_at'];
$sortCol = in_array($sortBy,$allowedSort) ? $sortBy : 'total_spent';
$sortSQL = $sortDir === 'asc' ? 'ASC' : 'DESC';

// ── Registered customers ─────────────────────────────────────
$regWhere  = "WHERE u.role='customer'";
$regParams = [];
if ($search) {
    $regWhere .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $regParams = ["%$search%","%$search%","%$search%"];
}
$customers = $pdo->prepare("
    SELECT u.*,
        COUNT(DISTINCT o.id)                as order_count,
        COALESCE(SUM(o.total_amount),0)     as total_spent,
        MAX(o.created_at)                   as last_order,
        SUM(CASE WHEN COALESCE(o.order_type,'online')='walkin'  THEN 1 ELSE 0 END) as walkin_count,
        SUM(CASE WHEN COALESCE(o.order_type,'online')='online'  THEN 1 ELSE 0 END) as online_count
    FROM users u
    LEFT JOIN orders o ON o.user_id=u.id
    $regWhere
    GROUP BY u.id
    ORDER BY $sortCol $sortSQL
");
$customers->execute($regParams);
$customers = $customers->fetchAll();

// ── Guest walk-ins (no user account) ─────────────────────────
$guestWhere  = "WHERE COALESCE(o.order_type,'walkin')='walkin' AND o.user_id IS NULL";
$guestParams = [];
if ($search) {
    $guestWhere .= " AND (o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
    $guestParams = ["%$search%","%$search%"];
}
$guestStmt = $pdo->prepare("
    SELECT
        o.customer_name  as name,
        o.customer_phone as phone,
        o.customer_email as email,
        COUNT(o.id)              as order_count,
        SUM(o.total_amount)      as total_spent,
        MAX(o.created_at)        as last_order,
        MIN(o.created_at)        as first_order,
        'walkin_guest'           as type
    FROM orders o
    $guestWhere
    GROUP BY o.customer_name, o.customer_phone
    ORDER BY total_spent $sortSQL
");
$guestStmt->execute($guestParams);
$guestCustomers = $guestStmt->fetchAll();

// ── Duplicate detection ───────────────────────────────────────
// Flag customers whose normalised phone matches another entry
$allPhones = [];
foreach ($customers as $c) {
    $np = normalPhone($c['phone']??'');
    if ($np) $allPhones[$np][] = ['type'=>'registered','id'=>$c['id'],'name'=>$c['name']];
}
foreach ($guestCustomers as $g) {
    $np = normalPhone($g['phone']??'');
    if ($np) $allPhones[$np][] = ['type'=>'guest','name'=>$g['name']];
}
$duplicatePhones = array_filter($allPhones, fn($arr) => count($arr) > 1);
$dupPhoneSet = array_keys($duplicatePhones); // normalised phones that are duplicated

// ── Behaviour filter ─────────────────────────────────────────
function behavTag(array $c): string {
    $spent = (float)$c['total_spent'];
    $orders= (int)$c['order_count'];
    $days  = $c['last_order'] ? (time() - strtotime($c['last_order'])) / 86400 : 9999;
    if ($orders === 0)   return 'no_orders';
    if ($spent >= 5000)  return 'high_value';
    if ($orders >= 3)    return 'repeat';
    if ($days > 60)      return 'inactive';
    return 'active';
}
if ($filterBehav) {
    $customers     = array_filter($customers,     fn($c) => behavTag($c) === $filterBehav);
    $guestCustomers= array_filter($guestCustomers,fn($c) => behavTag($c) === $filterBehav);
}

// ── Profile view data ─────────────────────────────────────────
$profileCustomer = null; $profileOrders = [];
if ($viewId) {
    $ps = $pdo->prepare("
        SELECT u.*, COUNT(DISTINCT o.id) as order_count, COALESCE(SUM(o.total_amount),0) as total_spent,
               MAX(o.created_at) as last_order
        FROM users u LEFT JOIN orders o ON o.user_id=u.id
        WHERE u.id=? AND u.role='customer' GROUP BY u.id");
    $ps->execute([$viewId]);
    $profileCustomer = $ps->fetch();
    if ($profileCustomer) {
        $po = $pdo->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
        $po->execute([$viewId]);
        $profileOrders = $po->fetchAll();
    }
}

// ── Stats ─────────────────────────────────────────────────────
$totalRegistered  = count($customers);
$totalGuests      = count($guestCustomers);
$totalAll         = $totalRegistered + $totalGuests;
$totalPaying      = count(array_filter($customers,fn($c)=>$c['order_count']>0)) + $totalGuests;
$totalRevenue     = array_sum(array_column($customers,'total_spent')) + array_sum(array_column($guestCustomers,'total_spent'));
// Fix: usort then pick first
$sortedForTop = $customers;
usort($sortedForTop, fn($a,$b) => $b['total_spent'] <=> $a['total_spent']);
$topCustomer = !empty($sortedForTop) ? $sortedForTop[0] : null;
$dupCount         = count($duplicatePhones);

// Helper: tag badge
function tagBadge(array $c): string {
    $tag = behavTag($c);
    return match($tag) {
        'high_value' => '<span style="background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:50px;font-size:10px;font-weight:700;">⭐ High Value</span>',
        'repeat'     => '<span style="background:#dcfce7;color:#15803d;padding:2px 9px;border-radius:50px;font-size:10px;font-weight:700;">🔄 Repeat Buyer</span>',
        'inactive'   => '<span style="background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:50px;font-size:10px;font-weight:700;">💤 Inactive</span>',
        'no_orders'  => '<span style="background:#f1f5f9;color:#64748b;padding:2px 9px;border-radius:50px;font-size:10px;font-weight:700;">No Orders Yet</span>',
        default      => '<span style="background:#dcfce7;color:#15803d;padding:2px 9px;border-radius:50px;font-size:10px;font-weight:700;">✓ Active</span>',
    };
}

function avatarBg(string $name): string {
    $colors = ['#4A7C59','#3b82f6','#E87722','#a855f7','#06b6d4','#ec4899'];
    return $colors[ord($name[0]) % count($colors)];
}

function sortUrl(string $col): string {
    $dir = ($_GET['sort']??'total_spent')===$col && ($_GET['dir']??'desc')==='desc' ? 'asc' : 'desc';
    return '?' . http_build_query(array_merge($_GET,['sort'=>$col,'dir'=>$dir]));
}
function sortIcon(string $col): string {
    if (($_GET['sort']??'total_spent')!==$col) return '<i class="fas fa-sort" style="color:#d1d5db;margin-left:3px;font-size:10px;"></i>';
    return ($_GET['dir']??'desc')==='desc'
        ? '<i class="fas fa-sort-down" style="color:var(--primary);margin-left:3px;font-size:10px;"></i>'
        : '<i class="fas fa-sort-up"   style="color:var(--primary);margin-left:3px;font-size:10px;"></i>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Customers — Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css">
  <style>
    /* ── Summary strip ─────────────────────────────────────── */
    .cust-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
    .cust-card  { background:white; border:1px solid var(--border); border-radius:12px; padding:16px 18px; }
    .cust-card-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:5px; }
    .cust-card-val { font-size:22px; font-weight:700; font-family:'Playfair Display',serif; line-height:1.1; }
    .cust-card-sub { font-size:11px; color:#9ca3af; margin-top:3px; }

    /* ── Filter bar ────────────────────────────────────────── */
    .filter-bar { background:white; border:1px solid var(--border); border-radius:12px; padding:14px 18px; margin-bottom:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .fchip { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border-radius:50px; font-size:12px; font-weight:700; cursor:pointer; border:1.5px solid var(--border); background:white; color:#6b7280; text-decoration:none; transition:all .15s; white-space:nowrap; }
    .fchip.active, .fchip:hover { background:var(--primary); color:white; border-color:var(--primary); }
    .fchip.warn.active, .fchip.warn:hover { background:#ef4444; border-color:#ef4444; }
    .fchip.amber.active,.fchip.amber:hover { background:#d97706; border-color:#d97706; }
    .fld-ctrl { padding:8px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:13px; font-family:inherit; outline:none; transition:border-color .15s; background:white; }
    .fld-ctrl:focus { border-color:var(--primary); }

    /* ── Tabs ──────────────────────────────────────────────── */
    .ctabs { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:20px; }
    .ctab  { padding:10px 22px; font-size:13px; font-weight:700; border:none; background:none; cursor:pointer; color:#9ca3af; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .15s; font-family:inherit; text-decoration:none; }
    .ctab.active, .ctab:hover { color:var(--primary); border-bottom-color:var(--primary); }

    /* ── Table ─────────────────────────────────────────────── */
    .cust-table { width:100%; border-collapse:collapse; }
    .cust-table thead th { padding:10px 14px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; border-bottom:2px solid var(--border); text-align:left; white-space:nowrap; }
    .cust-table thead th a { color:inherit; text-decoration:none; display:inline-flex; align-items:center; }
    .cust-table tbody tr { border-bottom:1px solid #f3f4f6; transition:background .12s; cursor:pointer; }
    .cust-table tbody tr:last-child { border-bottom:none; }
    .cust-table tbody tr:hover { background:#f8fffe; }
    .cust-table td { padding:12px 14px; font-size:13px; vertical-align:middle; }
    .dup-row td:first-child { border-left:3px solid #fbbf24; }

    /* Avatar */
    .av { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; color:white; flex-shrink:0; }

    /* Action buttons */
    .act-btn { width:30px; height:30px; border-radius:7px; border:1px solid var(--border); background:white; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:12px; color:#6b7280; transition:all .15s; text-decoration:none; }
    .act-btn:hover { border-color:var(--primary); color:var(--primary); background:#F0F9F4; }

    /* ── Profile drawer ─────────────────────────────────────── */
    .drawer-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:900; }
    .drawer-overlay.open { display:block; }
    .drawer { position:fixed; top:0; right:-540px; width:500px; max-width:100vw; height:100vh; background:white; z-index:901; box-shadow:-8px 0 40px rgba(0,0,0,.12); transition:right .3s ease; overflow-y:auto; display:flex; flex-direction:column; }
    .drawer.open { right:0; }
    .drawer-head { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--primary); color:white; flex-shrink:0; }
    .drawer-head h3 { font-size:16px; font-weight:700; margin:0; color:white; }
    .drawer-close  { background:rgba(255,255,255,.2); border:none; color:white; width:30px; height:30px; border-radius:6px; cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center; }
    .drawer-close:hover { background:rgba(255,255,255,.35); }
    .drawer-body { padding:24px; flex:1; }
    .dfield { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:13px; }
    .dfield:last-child { border-bottom:none; }
    .dfield .lbl { color:#6b7280; }
    .dfield .val { font-weight:600; text-align:right; max-width:60%; word-break:break-word; }
    .dsec h4 { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin:18px 0 10px; }
    .ord-row { display:flex; justify-content:space-between; padding:8px 12px; background:#f9fafb; border-radius:8px; font-size:12px; margin-bottom:6px; }

    @media(max-width:1000px){ .cust-strip{ grid-template-columns:repeat(2,1fr); } }
    @media(max-width:600px) { .cust-strip{ grid-template-columns:1fr; } }
  </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">

    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
      <div><h1>Customers</h1><p>Registered accounts, walk-in customers and purchase history</p></div>
      <button class="btn btn-primary" onclick="document.getElementById('addWalkinModal').classList.add('open')">
        <i class="fas fa-user-plus"></i> Add Customer
      </button>
    </div>

    <?php if($urlMsg): [$t,$tx] = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $t; ?>" style="margin-bottom:16px;"><?php echo $tx; ?></div>
    <?php endif; ?>

    <?php if($dupCount > 0): ?>
    <div class="alert alert-warning" style="margin-bottom:16px;">
      <i class="fas fa-exclamation-triangle"></i>
      <strong><?php echo $dupCount; ?> possible duplicate customer(s)</strong> detected — same phone number appears in both registered and walk-in records.
      Rows highlighted with a yellow left border.
    </div>
    <?php endif; ?>

    <!-- Summary strip -->
    <div class="cust-strip">
      <div class="cust-card">
        <div class="cust-card-lbl">Total Customers</div>
        <div class="cust-card-val"><?php echo $totalAll; ?></div>
        <div class="cust-card-sub"><?php echo $totalRegistered; ?> registered · <?php echo $totalGuests; ?> walk-in guests</div>
      </div>
      <div class="cust-card">
        <div class="cust-card-lbl">Active Buyers</div>
        <div class="cust-card-val" style="color:var(--primary);"><?php echo $totalPaying; ?></div>
        <div class="cust-card-sub">Have placed at least 1 order</div>
      </div>
      <div class="cust-card">
        <div class="cust-card-lbl">Total Revenue</div>
        <div class="cust-card-val" style="color:var(--primary);"><?php echo formatCurrency($totalRevenue); ?></div>
        <div class="cust-card-sub">All customers combined</div>
      </div>

      <?php if($topCustomer): ?>
      <div class="cust-card" style="border-color:#fcd34d;background:#fffbf0;">
        <div class="cust-card-lbl">⭐ Top Customer</div>
        <div class="cust-card-val" style="font-size:17px;"><?php echo htmlspecialchars(explode(' ',$topCustomer['name'])[0]); ?></div>
        <div class="cust-card-sub"><?php echo formatCurrency($topCustomer['total_spent']); ?> lifetime spend</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Filter bar: instant search + behaviour filters -->
    <div class="filter-bar">
      <input type="text" id="liveSearch" class="fld-ctrl" placeholder="🔍  Search name, phone or email..."
             value="<?php echo htmlspecialchars($search); ?>" style="width:260px;"
             oninput="liveFilter(this.value)">
      <div style="height:24px;width:1px;background:var(--border);flex-shrink:0;"></div>
      <span style="font-size:12px;color:#9ca3af;font-weight:700;">Segment:</span>
      <a class="fchip <?php echo !$filterBehav?'active':''; ?>" href="?tab=<?php echo $filterTab; ?>&sort=<?php echo $sortBy; ?>&dir=<?php echo $sortDir; ?>">All</a>
      <a class="fchip amber <?php echo $filterBehav==='high_value'?'active':''; ?>" href="?behav=high_value&tab=<?php echo $filterTab; ?>">⭐ High Value</a>
      <a class="fchip <?php echo $filterBehav==='repeat'?'active':''; ?>" href="?behav=repeat&tab=<?php echo $filterTab; ?>">🔄 Repeat Buyers</a>
      <a class="fchip warn <?php echo $filterBehav==='inactive'?'active':''; ?>" href="?behav=inactive&tab=<?php echo $filterTab; ?>">💤 Inactive (60+ days)</a>
      <a class="fchip <?php echo $filterBehav==='no_orders'?'active':''; ?>" href="?behav=no_orders&tab=<?php echo $filterTab; ?>">No Orders Yet</a>
      <?php if($search || $filterBehav): ?>
      <a href="customers.php" class="btn btn-secondary btn-sm" style="margin-left:auto;">Clear</a>
      <?php endif; ?>
    </div>

    <!-- Tabs -->
    <div class="ctabs">
      <a class="ctab <?php echo $filterTab==='all'?'active':''; ?>" href="?tab=all&sort=<?php echo $sortBy; ?>&dir=<?php echo $sortDir; ?><?php echo $filterBehav?"&behav=$filterBehav":''; ?>">
        All (<?php echo $totalAll; ?>)
      </a>
      <a class="ctab <?php echo $filterTab==='registered'?'active':''; ?>" href="?tab=registered&sort=<?php echo $sortBy; ?>&dir=<?php echo $sortDir; ?>">
        🌐 Registered (<?php echo $totalRegistered; ?>)
      </a>
      <a class="ctab <?php echo $filterTab==='walkin'?'active':''; ?>" href="?tab=walkin&sort=<?php echo $sortBy; ?>&dir=<?php echo $sortDir; ?>">
        🏪 Walk-in Guests (<?php echo $totalGuests; ?>)
      </a>
    </div>

    <!-- ── Unified customer table ─────────────────────────── -->
    <div class="card" id="custTableWrap">
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:15px;font-weight:700;" id="tableHeading">
          All Customers <span style="font-size:13px;color:#9ca3af;font-weight:400;">(<?php echo $totalAll; ?>)</span>
        </div>
        <span style="font-size:12px;color:#9ca3af;">Click a row to view profile</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="cust-table" id="custTable">
          <thead>
            <tr>
              <th><a href="<?php echo sortUrl('name'); ?>">Customer <?php echo sortIcon('name'); ?></a></th>
              <th>Phone</th>
              <th>Type</th>
              <th><a href="<?php echo sortUrl('order_count'); ?>">Orders <?php echo sortIcon('order_count'); ?></a></th>
              <th><a href="<?php echo sortUrl('total_spent'); ?>">Total Spent <?php echo sortIcon('total_spent'); ?></a></th>
              <th><a href="<?php echo sortUrl('last_order'); ?>">Last Visit <?php echo sortIcon('last_order'); ?></a></th>
              <th>Segment</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>

            <?php
            // Merge display based on active tab
            $showRegistered = in_array($filterTab, ['all','registered']);
            $showGuests     = in_array($filterTab, ['all','walkin']);
            ?>

            <?php if($showRegistered): ?>
            <?php foreach($customers as $c):
              $np     = normalPhone($c['phone']??'');
              $isDup  = $np && in_array($np, $dupPhoneSet);
              $bg     = avatarBg($c['name']);
            ?>
            <tr class="<?php echo $isDup?'dup-row':''; ?> cust-row"
                data-name="<?php echo strtolower(htmlspecialchars($c['name'])); ?>"
                data-phone="<?php echo strtolower($c['phone']??''); ?>"
                data-email="<?php echo strtolower($c['email']??''); ?>"
                onclick="openProfile(<?php echo $c['id']; ?>)">
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div class="av" style="background:<?php echo $bg; ?>;"><?php echo strtoupper(substr($c['name'],0,1)); ?></div>
                  <div>
                    <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                    <?php if($isDup): ?><span title="Possible duplicate" style="cursor:help;margin-left:5px;">⚠️</span><?php endif; ?>
                    <br><small style="color:#9ca3af;"><?php echo htmlspecialchars($c['email']); ?></small>
                  </div>
                </div>
              </td>
              <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($c['phone']??'No phone'); ?></td>
              <td><span style="background:#dbeafe;color:#1d4ed8;padding:3px 9px;border-radius:50px;font-size:11px;font-weight:700;">🌐 Registered</span></td>
              <td><strong><?php echo $c['order_count']; ?></strong></td>
              <td style="font-size:15px;font-weight:700;color:<?php echo $c['total_spent']>0?'var(--primary)':'#9ca3af'; ?>;">
                <?php echo $c['total_spent']>0 ? formatCurrency($c['total_spent']) : '—'; ?>
              </td>
              <td style="font-size:12px;color:#6b7280;">
                <?php echo $c['last_order'] ? date('M d, Y', strtotime($c['last_order'])) : '<span style="color:#9ca3af;">Never visited</span>'; ?>
              </td>
              <td><?php echo tagBadge($c); ?></td>
              <td onclick="event.stopPropagation()">
                <div style="display:flex;gap:5px;justify-content:flex-end;">
                  <button class="act-btn" onclick="openProfile(<?php echo $c['id']; ?>)" title="View profile"><i class="fas fa-eye"></i></button>
                  <button class="act-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($c),ENT_QUOTES); ?>)" title="Edit details"><i class="fas fa-pen"></i></button>
                  <?php if($c['phone']): ?><a class="act-btn" href="tel:<?php echo preg_replace('/\D/','',$c['phone']); ?>" title="Call customer"><i class="fas fa-phone"></i></a><?php endif; ?>
                  <a class="act-btn" href="walkin_order.php" title="Create order"><i class="fas fa-cart-plus"></i></a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if($showGuests): ?>
            <?php foreach($guestCustomers as $g):
              $np     = normalPhone($g['phone']??'');
              $isDup  = $np && in_array($np, $dupPhoneSet);
              $bg     = avatarBg($g['name']??'G');
            ?>
            <tr class="<?php echo $isDup?'dup-row':''; ?> cust-row" style="background:<?php echo $isDup?'':'#fffbf0'; ?>;"
                data-name="<?php echo strtolower(htmlspecialchars($g['name'])); ?>"
                data-phone="<?php echo strtolower($g['phone']??''); ?>"
                data-email="<?php echo strtolower($g['email']??''); ?>">
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div class="av" style="background:<?php echo $bg; ?>;"><?php echo strtoupper(substr($g['name']??'G',0,1)); ?></div>
                  <div>
                    <strong><?php echo htmlspecialchars($g['name']); ?></strong>
                    <?php if($isDup): ?><span title="Possible duplicate" style="cursor:help;margin-left:5px;">⚠️</span><?php endif; ?>
                    <br><small style="color:#9ca3af;"><?php echo $g['email'] ? htmlspecialchars($g['email']) : 'No email'; ?></small>
                  </div>
                </div>
              </td>
              <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($g['phone']??'—'); ?></td>
              <td><span style="background:#dcfce7;color:#15803d;padding:3px 9px;border-radius:50px;font-size:11px;font-weight:700;">🏪 Walk-in</span></td>
              <td><strong><?php echo $g['order_count']; ?></strong></td>
              <td style="font-size:15px;font-weight:700;color:var(--primary);"><?php echo formatCurrency($g['total_spent']); ?></td>
              <td style="font-size:12px;color:#6b7280;">
                <?php echo $g['last_order'] ? date('M d, Y', strtotime($g['last_order'])) : '<span style="color:#9ca3af;">Never visited</span>'; ?>
              </td>
              <td><?php echo tagBadge($g); ?></td>
              <td onclick="event.stopPropagation()">
                <div style="display:flex;gap:5px;justify-content:flex-end;">
                  <?php if($g['phone']): ?><a class="act-btn" href="tel:<?php echo preg_replace('/\D/','',$g['phone']); ?>" title="Call customer"><i class="fas fa-phone"></i></a><?php endif; ?>
                  <a class="act-btn" href="walkin_order.php" title="Create order"><i class="fas fa-cart-plus"></i></a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if(empty($customers) && empty($guestCustomers)): ?>
            <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:48px;">
              <i class="fas fa-users" style="font-size:36px;color:#e5e7eb;margin-bottom:12px;display:block;"></i>
              <strong style="display:block;margin-bottom:4px;">No customers found</strong>
              <a href="customers.php" style="color:var(--primary);font-size:13px;">Clear filters →</a>
            </td></tr>
            <?php endif; ?>

          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- ── Profile Drawer ─────────────────────────────────────── -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeProfile()"></div>
<div class="drawer" id="profileDrawer">
  <div class="drawer-head">
    <h3 id="drawerTitle">Customer Profile</h3>
    <button class="drawer-close" onclick="closeProfile()"><i class="fas fa-times"></i></button>
  </div>
  <div class="drawer-body" id="drawerBody">
    <div style="text-align:center;padding:40px;color:#9ca3af;">
      <i class="fas fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>
      Loading profile...
    </div>
  </div>
</div>

<!-- ── Add Customer Modal ─────────────────────────────────── -->
<div class="modal-overlay" id="addWalkinModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">
      <h3><i class="fas fa-user-plus" style="color:var(--primary);margin-right:8px;"></i>Add Customer</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div style="background:#EDF5E8;border-radius:10px;padding:12px 16px;margin:0 32px 4px;font-size:13px;color:#2D5016;">
      <i class="fas fa-info-circle"></i> Creates an account so you can track their full purchase history online and in-store.
    </div>
    <form method="POST">
      <input type="hidden" name="add_walkin" value="1">
      <div style="padding:16px 32px 0;">
        <div class="form-row">
          <div class="form-group"><label>Full Name *</label><input type="text" name="name" class="form-control" required placeholder="Customer's name"></div>
          <div class="form-group"><label>Phone *</label><input type="tel" name="phone" class="form-control" required placeholder="0244 XXX XXX"></div>
        </div>
        <div class="form-group"><label>Email <small style="color:#9ca3af;">(optional)</small></label><input type="email" name="email" class="form-control" placeholder="customer@email.com"></div>
        <div class="form-group"><label>Location / Area</label><input type="text" name="location" class="form-control" placeholder="e.g. East Legon, Accra"></div>
        <div class="form-group"><label>Notes</label><input type="text" name="notes" class="form-control" placeholder="Any notes about this customer"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Customer</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Customer Modal ────────────────────────────────── -->
<div class="modal-overlay" id="editCustModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <h3><i class="fas fa-pen" style="color:var(--primary);margin-right:8px;"></i>Edit Customer</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="edit_customer" value="1">
      <input type="hidden" name="customer_id" id="editCustId">
      <div style="padding:16px 32px 0;">
        <div class="form-row">
          <div class="form-group"><label>Full Name *</label><input type="text" name="name" id="editCustName" class="form-control" required></div>
          <div class="form-group"><label>Phone *</label><input type="tel" name="phone" id="editCustPhone" class="form-control" required></div>
        </div>
        <div class="form-group"><label>Email</label><input type="email" name="email" id="editCustEmail" class="form-control"></div>
        <div class="form-group"><label>Location</label><input type="text" name="location" id="editCustLocation" class="form-control"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script src="../public/js/admin.js"></script>
<script>
// ── Customer data for profile drawer ─────────────────────────
const custData = <?php
    $drawerData = [];
    foreach ($customers as $c) {
        $ords = $pdo->prepare("SELECT order_number, total_amount, order_status, created_at, COALESCE(order_type,'online') as otype FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 8");
        $ords->execute([$c['id']]);
        $c['_orders'] = $ords->fetchAll();
        $drawerData[$c['id']] = $c;
    }
    echo json_encode($drawerData, JSON_HEX_TAG|JSON_HEX_QUOT);
?>;

function openProfile(id) {
  const c = custData[id];
  if (!c) return;
  document.getElementById('drawerTitle').textContent = c.name;
  const tag = behavTag(c);

  const ordersHtml = (c._orders||[]).map(o =>
    `<div class="ord-row">
       <span style="font-family:monospace;font-size:11px;">${o.order_number}</span>
       <span>${o.otype === 'walkin' ? '🏪' : '🌐'} ${new Date(o.created_at).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})}</span>
       <strong style="color:#4A7C59;">₵${parseFloat(o.total_amount).toFixed(2)}</strong>
     </div>`
  ).join('') || '<div style="color:#9ca3af;font-size:13px;padding:8px 0;">No orders yet</div>';

  const daysSince = c.last_order ? Math.floor((Date.now()-new Date(c.last_order))/86400000) : null;
  const freq = c.order_count > 0 && c.created_at
    ? Math.round((Date.now()-new Date(c.created_at))/86400000 / Math.max(c.order_count,1))
    : null;

  document.getElementById('drawerBody').innerHTML = `
    <div style="text-align:center;padding:16px 0 20px;">
      <div style="width:64px;height:64px;border-radius:50%;background:${randomColor(c.name)};color:white;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;margin:0 auto 10px;">
        ${c.name.charAt(0).toUpperCase()}
      </div>
      <strong style="font-size:18px;">${c.name}</strong><br>
      <span style="font-size:12px;color:#9ca3af;">${c.email||'No email'}</span><br>
      <div style="margin-top:8px;">${tag}</div>
    </div>
    <div class="dsec"><h4>Contact</h4>
      <div class="dfield"><span class="lbl">Phone</span><span class="val"><a href="tel:${c.phone||''}" style="color:var(--primary);">${c.phone||'—'}</a></span></div>
      <div class="dfield"><span class="lbl">Email</span><span class="val">${c.email||'—'}</span></div>
      <div class="dfield"><span class="lbl">Location</span><span class="val">${c.location||'—'}</span></div>
      <div class="dfield"><span class="lbl">Joined</span><span class="val">${new Date(c.created_at).toLocaleDateString('en-GB',{dateStyle:'medium'})}</span></div>
    </div>
    <div class="dsec"><h4>Purchase Summary</h4>
      <div class="dfield"><span class="lbl">Total Orders</span><strong class="val">${c.order_count}</strong></div>
      <div class="dfield"><span class="lbl">Total Spent</span><strong class="val" style="color:var(--primary);">₵${parseFloat(c.total_spent||0).toFixed(2)}</strong></div>
      <div class="dfield"><span class="lbl">Walk-ins</span><span class="val">${c.walkin_count||0}</span></div>
      <div class="dfield"><span class="lbl">Online Orders</span><span class="val">${c.online_count||0}</span></div>
      <div class="dfield"><span class="lbl">Last Visit</span><span class="val">${c.last_order ? new Date(c.last_order).toLocaleDateString('en-GB',{dateStyle:'medium'}) + (daysSince!==null?` (${daysSince}d ago)`:'') : 'Never visited'}</span></div>
      ${freq ? `<div class="dfield"><span class="lbl">Avg. Visit Frequency</span><span class="val">Every ~${freq} days</span></div>` : ''}
    </div>
    <div class="dsec"><h4>Recent Orders</h4>${ordersHtml}</div>
    <div style="display:flex;gap:10px;margin-top:20px;">
      <a href="walkin_order.php" class="btn btn-primary" style="flex:1;justify-content:center;"><i class="fas fa-cart-plus"></i> New Order</a>
      ${c.phone ? `<a href="tel:${c.phone}" class="btn btn-secondary" style="flex:1;justify-content:center;"><i class="fas fa-phone"></i> Call</a>` : ''}
    </div>`;

  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('profileDrawer').classList.add('open');
}

function closeProfile() {
  document.getElementById('drawerOverlay').classList.remove('open');
  document.getElementById('profileDrawer').classList.remove('open');
}

function randomColor(name) {
  const c = ['#4A7C59','#3b82f6','#E87722','#a855f7','#06b6d4','#ec4899'];
  return c[name.charCodeAt(0) % c.length];
}

function behavTag(c) {
  const spent  = parseFloat(c.total_spent||0);
  const orders = parseInt(c.order_count||0);
  const days   = c.last_order ? (Date.now()-new Date(c.last_order))/86400000 : 9999;
  if (orders===0)    return '<span style="background:#f1f5f9;color:#64748b;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">No Orders Yet</span>';
  if (spent>=5000)   return '<span style="background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">⭐ High Value</span>';
  if (orders>=3)     return '<span style="background:#dcfce7;color:#15803d;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">🔄 Repeat Buyer</span>';
  if (days>60)       return '<span style="background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">💤 Inactive</span>';
  return '<span style="background:#dcfce7;color:#15803d;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:700;">✓ Active</span>';
}

function openEditModal(c) {
  document.getElementById('editCustId').value       = c.id;
  document.getElementById('editCustName').value     = c.name;
  document.getElementById('editCustPhone').value    = c.phone||'';
  document.getElementById('editCustEmail').value    = c.email||'';
  document.getElementById('editCustLocation').value = c.location||'';
  document.getElementById('editCustModal').classList.add('open');
}

// ── Live search ───────────────────────────────────────────────
function liveFilter(val) {
  const q = val.toLowerCase().trim();
  document.querySelectorAll('.cust-row').forEach(row => {
    const name  = row.dataset.name  || '';
    const phone = row.dataset.phone || '';
    const email = row.dataset.email || '';
    row.style.display = (!q || name.includes(q) || phone.includes(q) || email.includes(q)) ? '' : 'none';
  });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeProfile(); });
</script>
</body>
</html>
