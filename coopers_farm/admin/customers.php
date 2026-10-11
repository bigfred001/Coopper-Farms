<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

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
  <link rel="stylesheet" href="../public/css/admin.css?v=8">
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
             oninput="custLiveFilter(this.value)">
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
                  <button class="act-btn" onclick="custOpenEditModal(<?php echo htmlspecialchars(json_encode($c),ENT_QUOTES); ?>)" title="Edit details"><i class="fas fa-pen"></i></button>
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

<?php
$drawerData = [];
foreach ($customers as $c) {
  $ords = $pdo->prepare("SELECT order_number, total_amount, order_status, created_at, COALESCE(order_type,'online') as otype FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 8");
  $ords->execute([$c['id']]);
  $c['_orders'] = $ords->fetchAll();
  $drawerData[$c['id']] = $c;
}
$pageData = ['custData'=>$drawerData];
?>
<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
