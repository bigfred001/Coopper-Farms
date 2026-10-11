<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

// ── Inline status update (AJAX) ──────────────────────────────
// ── Order workflow ────────────────────────────────────────────
// Only these moves are allowed; anything else is rejected on the server.
$ORDER_FLOW = [
    'pending'          => ['confirmed','cancelled'],
    'confirmed'        => ['processing','cancelled'],
    'processing'       => ['out_for_delivery','delivered','cancelled'],
    'out_for_delivery' => ['delivered','cancelled'],
    'delivered'        => [],
    'cancelled'        => [],
];
function orderLabel(string $s): string { return ucfirst(str_replace('_',' ',$s)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $oid    = (int)($_POST['order_id'] ?? 0);
    $new    = $_POST['status'] ?? '';
    $reason = sanitize($_POST['reason'] ?? '');
    $os = $pdo->prepare("SELECT * FROM orders WHERE id=?");
    $os->execute([$oid]);
    $ord = $os->fetch();
    $err = null;

    if (!$ord) {
        $err = 'Order not found.';
    } elseif (!in_array($new, $ORDER_FLOW[$ord['order_status']] ?? [], true)) {
        $err = 'Cannot move an order from '.orderLabel($ord['order_status']).' to '.orderLabel($new).'.';
    } elseif ($new === 'confirmed') {
        // Accepting checks that every item is still in stock. Stock is not changed here.
        $it = $pdo->prepare("SELECT oi.product_name, oi.quantity, COALESCE(p.stock_quantity,0) AS have
            FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?");
        $it->execute([$oid]);
        $short = [];
        foreach ($it->fetchAll() as $r) {
            if ((int)$r['have'] < (int)$r['quantity']) {
                $short[] = "{$r['product_name']} (have {$r['have']}, need {$r['quantity']})";
            }
        }
        if ($short) $err = 'Cannot accept — not enough stock: '.implode('; ', $short).'.';
    } elseif ($new === 'cancelled' && $reason === '') {
        $err = 'Please give a reason for cancelling or rejecting this order.';
    }

    if ($err) {
        if (!empty($_POST['ajax'])) { echo json_encode(['ok'=>false,'error'=>$err]); exit; }
        header('Location: orders.php?msg='.urlencode('error:'.$err)); exit;
    }

    $old = $ord['order_status'];
    if ($new === 'delivered' && ($ord['payment_status'] ?? '') !== 'paid') {
        // Pay on delivery: the money is taken at delivery and recorded as a sale
        $pdo->prepare("UPDATE orders SET payment_status='paid', payment_received=total_amount WHERE id=?")->execute([$oid]);
        $pdo->prepare("INSERT INTO sales (order_id, sale_date, total_amount, payment_method) VALUES (?,CURDATE(),?,?)")
            ->execute([$oid, (float)$ord['total_amount'], $ord['payment_method']]);
    }
    $pdo->prepare("UPDATE orders SET order_status=?, updated_at=NOW(), updated_by=? WHERE id=?")
        ->execute([$new, $_SESSION['user_id'], $oid]);
    if ($new === 'cancelled') {
        $pdo->prepare("UPDATE orders SET notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?")
            ->execute(["\n[Cancelled ".date('M d, Y')."]: {$reason}", $oid]);
    }
    auditLog($pdo, 'update_order_status', 'order', $oid,
        "Status: ".orderLabel($old)." → ".orderLabel($new).($reason ? ". Reason: {$reason}" : ''));
    if (!empty($ord['customer_email']) && $old !== $new) {
        $ord['order_status'] = $new;
        $mailer->sendOrderStatusUpdate($ord, $old);
    }
    if (!empty($_POST['ajax'])) { echo json_encode(['ok'=>true,'status'=>$new]); exit; }
    header('Location: orders.php?msg='.urlencode('success:Order '.$ord['order_number'].' is now '.orderLabel($new).'.')); exit;
}

// The buttons offered on each row, based on the order's current stage
function orderActionButtons(array $o): string {
    $s  = $o['order_status'];
    $id = (int)$o['id'];
    $btn = function (string $to, string $label, string $icon, string $cls = '') use ($id) {
        return "<button type=\"button\" class=\"act-btn order-act {$cls}\" title=\"{$label}\" onclick=\"orderAction({$id},'{$to}')\">"
             . "<i class=\"fas {$icon}\"></i> {$label}</button>";
    };
    if ($s === 'pending')            return $btn('confirmed','Accept','fa-check','ok').$btn('cancelled','Reject','fa-ban','bad');
    if ($s === 'confirmed')          return $btn('processing','Start packing','fa-box-open').$btn('cancelled','Cancel','fa-ban','bad');
    if ($s === 'processing')         return $btn('out_for_delivery','Dispatch','fa-truck').$btn('delivered','Delivered','fa-check-double','ok');
    if ($s === 'out_for_delivery')   return $btn('delivered','Delivered','fa-check-double','ok');
    return '';
}

// ── Filters ──────────────────────────────────────────────────
$statusFilter  = $_GET['status']    ?? '';
$typeFilter    = $_GET['type']      ?? '';
$payFilter     = $_GET['pay']       ?? '';
$search        = $_GET['search']    ?? '';
$dateFrom      = $_GET['date_from'] ?? '';
$dateTo        = $_GET['date_to']   ?? '';
$quickFilter   = $_GET['quick']     ?? '';
$page          = max(1,(int)($_GET['page']??1));
$perPage       = 15;
$offset        = ($page-1)*$perPage;

// Apply quick filters
if ($quickFilter === 'today')   { $dateFrom = $dateTo = date('Y-m-d'); }
if ($quickFilter === 'week')    { $dateFrom = date('Y-m-d',strtotime('monday this week')); $dateTo = date('Y-m-d'); }
if ($quickFilter === 'pending') { $statusFilter = 'pending'; }

$where  = "WHERE 1=1";
$params = [];
if ($statusFilter) { $where .= " AND o.order_status=?";                 $params[] = $statusFilter; }
if ($typeFilter)   { $where .= " AND COALESCE(o.order_type,'online')=?"; $params[] = $typeFilter; }
if ($payFilter)    { $where .= " AND o.payment_method=?";                $params[] = $payFilter; }
if ($dateFrom)     { $where .= " AND DATE(o.created_at)>=?";             $params[] = $dateFrom; }
if ($dateTo)       { $where .= " AND DATE(o.created_at)<=?";             $params[] = $dateTo; }
if ($search) {
    $where .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o $where");
$totalStmt->execute($params);
$totalRows  = $totalStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $pdo->prepare("
    SELECT o.*, COALESCE(o.order_type,'online') as otype,
           (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) as item_count
    FROM orders o $where
    ORDER BY o.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// ── Summary metrics (for the filtered period) ────────────────
$sumStmt = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as rev,
    SUM(CASE WHEN order_status='pending' THEN 1 ELSE 0 END) as pending_cnt,
    SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) as delivered_cnt,
    SUM(CASE WHEN payment_method='cash' THEN total_amount ELSE 0 END) as cash_rev,
    SUM(CASE WHEN payment_method LIKE 'momo%' THEN total_amount ELSE 0 END) as momo_rev,
    SUM(CASE WHEN COALESCE(order_type,'online')='walkin' THEN 1 ELSE 0 END) as walkin_cnt,
    SUM(CASE WHEN COALESCE(order_type,'online')='online' THEN 1 ELSE 0 END) as online_cnt
    FROM orders o $where");
$sumStmt->execute($params);
$summary = $sumStmt->fetch();

// ── Order detail for view modal ──────────────────────────────
$viewId = (int)($_GET['view'] ?? 0);
$viewOrder = null; $viewItems = [];
if ($viewId) {
    $vs = $pdo->prepare("SELECT * FROM orders WHERE id=?"); $vs->execute([$viewId]);
    $viewOrder = $vs->fetch();
    $vi = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?"); $vi->execute([$viewId]);
    $viewItems = $vi->fetchAll();
}

$statuses    = ['pending','confirmed','processing','out_for_delivery','delivered','cancelled'];
$payMethods  = $pdo->query("SELECT DISTINCT payment_method FROM orders WHERE payment_method IS NOT NULL ORDER BY payment_method")->fetchAll(PDO::FETCH_COLUMN);

// Phone normaliser: bring everything to 0XXXXXXXXX or +233XXXXXXXXX
function normalisePhone(string $p): string {
    $p = preg_replace('/\D/', '', $p);
    if (strlen($p) === 12 && str_starts_with($p,'233')) return '+233 ' . substr($p,3,2) . ' ' . substr($p,5,3) . ' ' . substr($p,8,4);
    if (strlen($p) === 10 && str_starts_with($p,'0'))   return substr($p,0,4) . ' ' . substr($p,4,3) . ' ' . substr($p,7,3);
    return $p;
}

$statusColors = [
    'pending'         => ['#fbbf24','#78350f','#fef3c7'],
    'confirmed'       => ['#3b82f6','#1e3a8a','#dbeafe'],
    'processing'      => ['#a855f7','#4c1d95','#f3e8ff'],
    'out_for_delivery'=> ['#E87722','#7c2d12','#ffedd5'],
    'delivered'       => ['#4ade80','#14532d','#dcfce7'],
    'cancelled'       => ['#ef4444','#7f1d1d','#fee2e2'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Orders — Coopers Admin</title>
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
      <div>
        <h1>Orders Management</h1>
        <p>Track, update and analyse all customer orders</p>
      </div>
      <div style="display:flex;gap:8px;">
        <a href="?<?php echo http_build_query(array_merge($_GET,['export'=>'csv'])); ?>" class="btn btn-secondary">
          <i class="fas fa-download"></i> Export CSV
        </a>
        <a href="walkin_order.php" class="btn btn-primary">
          <i class="fas fa-store"></i> New Walk-in
        </a>
      </div>
    </div>

    <?php
    // CSV export
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="orders_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['Order #','Customer','Phone','Type','Items','Amount','Payment','Status','Date']);
        $allStmt = $pdo->prepare("SELECT o.*, COALESCE(o.order_type,'online') as otype, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id=o.id) as item_count FROM orders o $where ORDER BY o.created_at DESC");
        $allStmt->execute($params);
        foreach ($allStmt->fetchAll() as $row) {
            fputcsv($out, [$row['order_number'], $row['customer_name'], $row['customer_phone'], $row['otype'], $row['item_count'], $row['total_amount'], $row['payment_method'], $row['order_status'], date('Y-m-d H:i', strtotime($row['created_at']))]);
        }
        fclose($out); exit;
    }
    ?>

    <?php if(!empty($_GET['msg'])): [$__t,$__x]=explode(':',$_GET['msg'],2); ?>
    <div class="alert alert-<?php echo $__t; ?>" style="margin-bottom:14px;"><i class="fas fa-<?php echo $__t==='error'?'exclamation-circle':'check-circle'; ?>"></i> <?php echo htmlspecialchars($__x); ?></div>
    <?php endif; ?>
    <?php if(isset($_GET['updated'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px;"><i class="fas fa-check-circle"></i> Order status updated.</div>
    <?php endif; ?>

    <!-- ── Summary strip ─────────────────────────────────── -->
    <div class="summary-strip">
      <div class="sum-card">
        <div class="sum-card-label">Total Orders</div>
        <div class="sum-card-val"><?php echo number_format($summary['cnt']); ?></div>
        <div class="sum-card-sub"><?php echo $summary['walkin_cnt']; ?> walk-in · <?php echo $summary['online_cnt']; ?> online</div>
      </div>
      <div class="sum-card">
        <div class="sum-card-label">Total Revenue</div>
        <div class="sum-card-val" style="color:var(--primary);"><?php echo formatCurrency($summary['rev']); ?></div>
        <div class="sum-card-sub">Filtered period</div>
      </div>
      <div class="sum-card" style="<?php echo $summary['pending_cnt']>0?'border-color:#fbbf24;background:#fffbf0;':''; ?>">
        <div class="sum-card-label">Pending</div>
        <div class="sum-card-val" style="color:<?php echo $summary['pending_cnt']>0?'#d97706':'inherit'; ?>"><?php echo $summary['pending_cnt']; ?></div>
        <div class="sum-card-sub">Need action</div>
      </div>
      <div class="sum-card">
        <div class="sum-card-label">Cash Revenue</div>
        <div class="sum-card-val"><?php echo formatCurrency($summary['cash_rev']); ?></div>
        <div class="sum-card-sub">vs <?php echo formatCurrency($summary['momo_rev']); ?> MoMo</div>
      </div>
      <div class="sum-card">
        <div class="sum-card-label">Delivered</div>
        <div class="sum-card-val" style="color:#16a34a;"><?php echo $summary['delivered_cnt']; ?></div>
        <div class="sum-card-sub"><?php echo $summary['cnt']>0 ? round(($summary['delivered_cnt']/$summary['cnt'])*100).'%' : '0%'; ?> completion</div>
      </div>
    </div>

    <!-- ── Filters ──────────────────────────────────────── -->
    <div class="filter-bar">
      <!-- Quick chips -->
      <div class="filter-row">
        <span style="font-size:12px;font-weight:700;color:#9ca3af;white-space:nowrap;">Quick:</span>
        <div class="quick-chips">
          <a class="qchip <?php echo $quickFilter===''&&!$statusFilter&&!$typeFilter&&!$search&&!$dateFrom?'active':''; ?>" href="orders.php">All</a>
          <a class="qchip <?php echo $quickFilter==='today'?'active':''; ?>" href="?quick=today">Today</a>
          <a class="qchip <?php echo $quickFilter==='week'?'active':''; ?>"  href="?quick=week">This Week</a>
          <a class="qchip warn <?php echo $quickFilter==='pending'?'active':''; ?>" href="?quick=pending">
            <i class="fas fa-clock" style="font-size:10px;"></i> Pending <?php if($summary['pending_cnt']>0): ?>(<?php echo $summary['pending_cnt']; ?>)<?php endif; ?>
          </a>
          <a class="qchip <?php echo $typeFilter==='walkin'?'active':''; ?>"  href="?type=walkin">🏪 Walk-in</a>
          <a class="qchip <?php echo $typeFilter==='online'?'active':''; ?>"  href="?type=online">🌐 Online</a>
        </div>
      </div>
      <!-- Advanced filters -->
      <form method="GET">
        <div class="filter-row">
          <input type="text" name="search" class="fld-ctrl" placeholder="🔍  Search order, customer, phone..." value="<?php echo htmlspecialchars($search); ?>" style="flex:1;min-width:200px;">
          <select name="status" class="fld-ctrl" style="width:160px;">
            <option value="">All Statuses</option>
            <?php foreach($statuses as $s): ?>
            <option value="<?php echo $s; ?>" <?php echo $statusFilter===$s?'selected':''; ?>><?php echo ucwords(str_replace('_',' ',$s)); ?></option>
            <?php endforeach; ?>
          </select>
          <select name="pay" class="fld-ctrl" style="width:150px;">
            <option value="">All Payments</option>
            <?php foreach($payMethods as $pm): ?>
            <option value="<?php echo $pm; ?>" <?php echo $payFilter===$pm?'selected':''; ?>><?php echo ucwords(str_replace(['momo_','_'],' ',$pm)); ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="date_from" class="fld-ctrl" value="<?php echo $dateFrom; ?>" style="width:140px;" title="From date">
          <input type="date" name="date_to"   class="fld-ctrl" value="<?php echo $dateTo; ?>"   style="width:140px;" title="To date">
          <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
          <a href="orders.php" class="btn btn-secondary">Clear</a>
        </div>
      </form>
    </div>

    <!-- ── Orders table ─────────────────────────────────── -->
    <div class="card" style="overflow:hidden;">
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:15px;font-weight:700;">
          Orders
          <span style="font-size:13px;color:#9ca3af;font-weight:400;margin-left:6px;">
            Showing <?php echo $offset+1; ?>–<?php echo min($offset+$perPage,$totalRows); ?> of <?php echo number_format($totalRows); ?>
          </span>
        </div>
        <div style="font-size:12px;color:#9ca3af;">Click any row to view details · Click status to update inline</div>
      </div>
      <div style="overflow-x:auto;">
        <table class="orders-table">
          <thead>
            <tr>
              <th>Order #</th>
              <th>Customer</th>
              <th>Phone</th>
              <th>Type</th>
              <th>Items</th>
              <th class="right">Amount</th>
              <th>Payment</th>
              <th>Status</th>
              <th>Date</th>
              <th class="right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Track repeat customers
            $custCounts = [];
            foreach ($orders as $o) { $k = strtolower(trim($o['customer_name'])); $custCounts[$k] = ($custCounts[$k]??0)+1; }
            $seenCust   = [];
            foreach($orders as $o):
              $custKey  = strtolower(trim($o['customer_name']));
              $isRepeat = ($custCounts[$custKey] ?? 0) > 1 && isset($seenCust[$custKey]);
              $seenCust[$custKey] = true;
              [$bc,$tc,$bgc] = $statusColors[$o['order_status']] ?? ['#9ca3af','#374151','#f3f4f6'];
              $phone    = normalisePhone($o['customer_phone'] ?? '');
            ?>
            <tr onclick="ordersOpenDrawer(<?php echo $o['id']; ?>)" id="row_<?php echo $o['id']; ?>">
              <td>
                <div class="order-num">
                  <?php echo htmlspecialchars($o['order_number']); ?>
                  <small><?php echo date('M d, Y', strtotime($o['created_at'])); ?></small>
                </div>
              </td>
              <td>
                <div class="customer-cell">
                  <strong>
                    <?php echo htmlspecialchars($o['customer_name']); ?>
                    <?php if($isRepeat): ?><span class="repeat-dot" title="Repeat customer in this view"></span><?php endif; ?>
                  </strong>
                  <small><?php echo htmlspecialchars($o['customer_email'] ?? ''); ?></small>
                </div>
              </td>
              <td class="phone-cell"><?php echo htmlspecialchars($phone); ?></td>
              <td>
                <?php $otype = $o['otype']; ?>
                <span class="type-badge <?php echo $otype==='walkin'?'type-walkin':'type-online'; ?>">
                  <?php echo $otype==='walkin'?'🏪 Walk-in':'🌐 Online'; ?>
                </span>
              </td>
              <td style="text-align:center;">
                <span style="background:#f3f4f6;border-radius:50px;padding:2px 8px;font-size:12px;font-weight:700;">
                  <?php echo $o['item_count']; ?>
                </span>
              </td>
              <td class="amount-cell"><?php echo formatCurrency($o['total_amount']); ?></td>
              <td style="font-size:12px;color:#6b7280;"><?php echo ucwords(str_replace(['momo_','_'],' ',$o['payment_method']??'')); ?></td>
              <td onclick="event.stopPropagation()">
                <!-- Inline status select -->
                <div class="status-select-wrap">
                  <select class="status-select" id="stat_<?php echo $o['id']; ?>"
                          onchange="inlineStatusUpdate(<?php echo $o['id']; ?>, this)"
                          style="background-color:<?php echo $bgc; ?>;color:<?php echo $tc; ?>;border:1.5px solid <?php echo $bc; ?>aa;">
                    <?php foreach($statuses as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $o['order_status']===$s?'selected':''; ?>>
                      <?php echo ucwords(str_replace('_',' ',$s)); ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </td>
              <td style="font-size:12px;color:#9ca3af;white-space:nowrap;"><?php echo date('g:i A', strtotime($o['created_at'])); ?></td>
              <td onclick="event.stopPropagation()">
                <div class="action-btns">
                  <?php echo orderActionButtons($o); ?>
                  <button class="act-btn" onclick="ordersOpenDrawer(<?php echo $o['id']; ?>)" title="View order details">
                    <i class="fas fa-eye"></i>
                  </button>
                  <a class="act-btn" href="tel:<?php echo preg_replace('/\D/','',$o['customer_phone']??''); ?>" title="Call customer">
                    <i class="fas fa-phone"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($orders)): ?>
            <tr>
              <td colspan="10" style="text-align:center;padding:48px;color:#9ca3af;">
                <i class="fas fa-shopping-cart" style="font-size:36px;color:#e5e7eb;margin-bottom:12px;display:block;"></i>
                <strong style="display:block;margin-bottom:4px;">No orders found</strong>
                <span style="font-size:13px;">Try adjusting your filters or <a href="orders.php" style="color:var(--primary);">clear all filters</a></span>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if($totalPages > 1): ?>
      <div class="pag-bar">
        <div class="pag-info">
          Showing <?php echo $offset+1; ?>–<?php echo min($offset+$perPage,$totalRows); ?> of <?php echo number_format($totalRows); ?> orders
        </div>
        <div class="pag-btns">
          <?php
          $qs = http_build_query(array_filter(['status'=>$statusFilter,'type'=>$typeFilter,'pay'=>$payFilter,'search'=>$search,'date_from'=>$dateFrom,'date_to'=>$dateTo,'quick'=>$quickFilter]));
          if($page > 1): ?><a class="pag-btn" href="?page=<?php echo $page-1; ?>&<?php echo $qs; ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
          <?php
          $start = max(1,$page-2); $end = min($totalPages,$page+2);
          if($start>1) echo '<span class="pag-btn" style="cursor:default;color:#ccc;">…</span>';
          for($i=$start;$i<=$end;$i++): ?>
          <a class="pag-btn <?php echo $i==$page?'active':''; ?>" href="?page=<?php echo $i; ?>&<?php echo $qs; ?>"><?php echo $i; ?></a>
          <?php endfor;
          if($end<$totalPages) echo '<span class="pag-btn" style="cursor:default;color:#ccc;">…</span>';
          if($page<$totalPages): ?><a class="pag-btn" href="?page=<?php echo $page+1; ?>&<?php echo $qs; ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- ── Order detail drawer ─────────────────────────────────── -->
<div class="drawer-overlay" id="drawerOverlay" onclick="ordersCloseDrawer()"></div>
<div class="drawer" id="orderDrawer">
  <div class="drawer-head">
    <h3 id="drawerTitle">Order Details</h3>
    <button class="drawer-close" onclick="ordersCloseDrawer()"><i class="fas fa-times"></i></button>
  </div>
  <div class="drawer-body" id="drawerBody">
    <div style="text-align:center;padding:40px;color:#9ca3af;">
      <i class="fas fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>
      Loading order...
    </div>
  </div>
</div>

<?php
$drawerOrders = [];
foreach ($orders as $o) {
  $its = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?"); $its->execute([$o['id']]);
  $o['_items'] = $its->fetchAll();
  $o['_phone_fmt'] = normalisePhone($o['customer_phone']??'');
  $drawerOrders[$o['id']] = $o;
}
$pageData = ['ordersData'=>$drawerOrders,'statusColors'=>$statusColors,'statuses'=>$statuses,'openId'=>($viewId && isset($drawerOrders[$viewId])) ? (int)$viewId : 0];
?>
<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
