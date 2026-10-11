<?php
require_once '../config/config.php';
requireAdmin();

// ── Inline status update (AJAX) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $oid    = (int)$_POST['order_id'];
    $status = sanitize($_POST['status']);
    $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id=?");
    $orderStmt->execute([$oid]);
    $orderRow  = $orderStmt->fetch();
    $oldStatus = $orderRow['order_status'] ?? 'pending';
    $pdo->prepare("UPDATE orders SET order_status=?, updated_at=NOW(), updated_by=? WHERE id=?")
        ->execute([$status, $_SESSION['user_id'], $oid]);
    auditLog($pdo, 'update_order_status', 'order', $oid, "Status: '{$oldStatus}' → '{$status}'");
    if ($orderRow && $orderRow['customer_email'] && $oldStatus !== $status) {
        $orderRow['order_status'] = $status;
        $mailer->sendOrderStatusUpdate($orderRow, $oldStatus);
    }
    if (!empty($_POST['ajax'])) {
        echo json_encode(['ok' => true, 'status' => $status]);
        exit;
    }
    header('Location: orders.php?updated=1&page=' . ($_GET['page']??1) . '&status=' . ($_GET['status']??'') . '&type=' . ($_GET['type']??'') . '&search=' . urlencode($_GET['search']??''));
    exit;
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
  <link rel="stylesheet" href="../public/css/admin.css">
  <style>
    /* ── Summary cards ─────────────────────────────────────── */
    .summary-strip { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:22px; }
    .sum-card { background:white; border:1px solid var(--border); border-radius:12px; padding:16px 18px; }
    .sum-card-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
    .sum-card-val   { font-size:24px; font-weight:700; font-family:'Playfair Display',serif; }
    .sum-card-sub   { font-size:11px; color:#9ca3af; margin-top:3px; }

    /* ── Filter bar ────────────────────────────────────────── */
    .filter-bar { background:white; border:1px solid var(--border); border-radius:12px; padding:16px 20px; margin-bottom:16px; }
    .filter-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
    .filter-row:last-child { margin-bottom:0; }
    .quick-chips { display:flex; gap:6px; flex-wrap:wrap; }
    .qchip { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; border-radius:50px; font-size:12px; font-weight:700; cursor:pointer; border:1.5px solid var(--border); background:white; color:#6b7280; text-decoration:none; transition:all 0.15s; }
    .qchip:hover, .qchip.active { background:var(--primary); color:white; border-color:var(--primary); }
    .qchip.warn { border-color:#fbbf24; color:#92400e; background:#fef3c7; }
    .qchip.warn:hover { background:#fbbf24; color:white; border-color:#fbbf24; }
    .fld-ctrl { padding:8px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:13px; font-family:inherit; outline:none; transition:border-color .15s; background:white; }
    .fld-ctrl:focus { border-color:var(--primary); }

    /* ── Table enhancements ────────────────────────────────── */
    .orders-table { width:100%; border-collapse:collapse; }
    .orders-table thead th { padding:10px 14px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; border-bottom:2px solid var(--border); text-align:left; white-space:nowrap; }
    .orders-table thead th.right { text-align:right; }
    .orders-table tbody tr { border-bottom:1px solid #f3f4f6; transition:background 0.12s; cursor:pointer; }
    .orders-table tbody tr:last-child { border-bottom:none; }
    .orders-table tbody tr:hover { background:#f8fffe; }
    .orders-table td { padding:12px 14px; font-size:13px; vertical-align:middle; }
    .orders-table td.right { text-align:right; }
    .order-num { font-weight:700; font-size:13px; font-family:monospace; color:#1a1a1a; }
    .order-num small { display:block; font-size:11px; color:#9ca3af; font-family:inherit; font-weight:400; }
    .customer-cell strong { display:block; font-size:13px; }
    .customer-cell small  { font-size:11px; color:#9ca3af; }
    .amount-cell { font-size:16px; font-weight:800; color:#1a1a1a; text-align:right; }
    .phone-cell  { font-size:12px; color:#6b7280; white-space:nowrap; }
    .repeat-dot  { display:inline-block; width:7px; height:7px; background:#3b82f6; border-radius:50%; margin-left:5px; vertical-align:middle; title:attr(title); }

    /* Inline status pill select */
    .status-select { border:none; background:none; font-size:12px; font-weight:700; cursor:pointer; border-radius:50px; padding:3px 8px; outline:none; appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23888'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 4px center; padding-right:18px; }

    /* Type badge */
    .type-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:50px; font-size:11px; font-weight:700; }
    .type-walkin { background:#dcfce7; color:#15803d; }
    .type-online { background:#dbeafe; color:#1d4ed8; }

    /* Actions */
    .action-btns { display:flex; gap:5px; justify-content:flex-end; }
    .act-btn { width:30px; height:30px; border-radius:7px; border:1px solid var(--border); background:white; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:13px; color:#6b7280; transition:all 0.15s; text-decoration:none; }
    .act-btn:hover { border-color:var(--primary); color:var(--primary); background:#F0F9F4; }
    .act-btn.danger:hover { border-color:#ef4444; color:#ef4444; background:#fee2e2; }

    /* ── Pagination ─────────────────────────────────────────── */
    .pag-bar { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-top:1px solid var(--border); flex-wrap:wrap; gap:10px; }
    .pag-info { font-size:13px; color:#6b7280; }
    .pag-btns { display:flex; gap:4px; }
    .pag-btn  { min-width:32px; height:32px; border-radius:6px; border:1px solid var(--border); background:white; cursor:pointer; font-size:13px; font-weight:600; display:flex; align-items:center; justify-content:center; color:#6b7280; text-decoration:none; transition:all 0.15s; padding:0 8px; }
    .pag-btn:hover, .pag-btn.active { background:var(--primary); color:white; border-color:var(--primary); }
    .pag-btn:disabled { opacity:.4; cursor:not-allowed; }

    /* ── Detail drawer ──────────────────────────────────────── */
    .drawer-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:900; }
    .drawer-overlay.open { display:block; }
    .drawer { position:fixed; top:0; right:-520px; width:500px; max-width:100vw; height:100vh; background:white; z-index:901; box-shadow:-8px 0 40px rgba(0,0,0,.15); transition:right .3s ease; overflow-y:auto; display:flex; flex-direction:column; }
    .drawer.open { right:0; }
    .drawer-head { padding:20px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--primary); color:white; flex-shrink:0; }
    .drawer-head h3 { font-size:16px; font-weight:700; margin:0; color:white; }
    .drawer-close { background:rgba(255,255,255,.2); border:none; color:white; width:30px; height:30px; border-radius:6px; cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center; transition:background .15s; }
    .drawer-close:hover { background:rgba(255,255,255,.35); }
    .drawer-body { padding:24px; flex:1; }
    .drawer-section { margin-bottom:22px; }
    .drawer-section h4 { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:10px; }
    .drawer-field { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:13px; }
    .drawer-field:last-child { border-bottom:none; }
    .drawer-field .lbl { color:#6b7280; }
    .drawer-field .val { font-weight:600; text-align:right; }
    .ditem-row { display:flex; justify-content:space-between; padding:8px 12px; background:#f9fafb; border-radius:8px; font-size:13px; margin-bottom:6px; }

    /* Status dropdown color update */
    .status-select-wrap { display:inline-block; }

    @media(max-width:1100px) { .summary-strip { grid-template-columns:repeat(3,1fr); } }
    @media(max-width:700px)  { .summary-strip { grid-template-columns:repeat(2,1fr); } }
  </style>
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
            <tr onclick="openDrawer(<?php echo $o['id']; ?>)" id="row_<?php echo $o['id']; ?>">
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
                  <button class="act-btn" onclick="openDrawer(<?php echo $o['id']; ?>)" title="View order details">
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
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="orderDrawer">
  <div class="drawer-head">
    <h3 id="drawerTitle">Order Details</h3>
    <button class="drawer-close" onclick="closeDrawer()"><i class="fas fa-times"></i></button>
  </div>
  <div class="drawer-body" id="drawerBody">
    <div style="text-align:center;padding:40px;color:#9ca3af;">
      <i class="fas fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>
      Loading order...
    </div>
  </div>
</div>

<script src="../public/js/admin.js"></script>
<script>
// ── Order data for drawer ─────────────────────────────────────
const ordersData = <?php
  // Encode all orders on page with their items for client-side drawer
  $drawerOrders = [];
  foreach ($orders as $o) {
      $its = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?"); $its->execute([$o['id']]); $itRows = $its->fetchAll();
      $o['_items'] = $itRows;
      $o['_phone_fmt'] = normalisePhone($o['customer_phone']??'');
      $drawerOrders[$o['id']] = $o;
  }
  echo json_encode($drawerOrders, JSON_HEX_TAG|JSON_HEX_QUOT);
?>;

const statusColors = <?php echo json_encode($statusColors); ?>;
const statuses     = <?php echo json_encode($statuses); ?>;

function formatCur(v) { return '₵' + parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
function fmtStatus(s) { return s.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()); }

// ── Inline status update ──────────────────────────────────────
function inlineStatusUpdate(id, sel) {
  const newStatus = sel.value;
  const fd = new FormData();
  fd.append('update_status','1'); fd.append('order_id',id); fd.append('status',newStatus); fd.append('ajax','1');
  fetch('orders.php', { method:'POST', body:fd })
    .then(r=>r.json()).then(data=>{
      if (data.ok) {
        const [bc,tc,bgc] = statusColors[newStatus] || ['#9ca3af','#374151','#f3f4f6'];
        sel.style.backgroundColor = bgc;
        sel.style.color           = tc;
        sel.style.borderColor     = bc + 'aa';
        showToast('Status updated to: ' + fmtStatus(newStatus));
        // Update drawer if open for this order
        if (currentDrawerId == id && ordersData[id]) { ordersData[id].order_status = newStatus; renderDrawer(id); }
      }
    }).catch(()=>{ showToast('Update failed','error'); sel.value = sel.dataset.prev; });
  sel.dataset.prev = newStatus;
}

// ── Drawer ────────────────────────────────────────────────────
let currentDrawerId = null;
function openDrawer(id) {
  currentDrawerId = id;
  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('orderDrawer').classList.add('open');
  renderDrawer(id);
}
function closeDrawer() {
  document.getElementById('drawerOverlay').classList.remove('open');
  document.getElementById('orderDrawer').classList.remove('open');
  currentDrawerId = null;
}

function renderDrawer(id) {
  const o = ordersData[id];
  if (!o) return;
  document.getElementById('drawerTitle').textContent = 'Order ' + o.order_number;
  const [bc,tc,bgc] = statusColors[o.order_status] || ['#9ca3af','#374151','#f3f4f6'];

  let itemsHtml = (o._items||[]).map(it => `
    <div class="ditem-row">
      <span>${it.product_name} <span style="color:#9ca3af;">×${it.quantity}</span></span>
      <strong>${formatCur(it.subtotal)}</strong>
    </div>`).join('') || '<div style="color:#9ca3af;font-size:13px;padding:8px 0;">No items found</div>';

  const received  = parseFloat(o.payment_received||0);
  const change    = received > 0 ? Math.max(0, received - parseFloat(o.total_amount)) : 0;

  document.getElementById('drawerBody').innerHTML = `
    <div class="drawer-section">
      <h4>Order Info</h4>
      <div class="drawer-field"><span class="lbl">Order #</span><span class="val" style="font-family:monospace;">${o.order_number}</span></div>
      <div class="drawer-field"><span class="lbl">Date</span><span class="val">${new Date(o.created_at).toLocaleString('en-GB',{dateStyle:'medium',timeStyle:'short'})}</span></div>
      <div class="drawer-field"><span class="lbl">Type</span><span class="val">${o.otype==='walkin'?'🏪 Walk-in':'🌐 Online'}</span></div>
      <div class="drawer-field"><span class="lbl">Status</span>
        <span class="val" style="background:${bgc};color:${tc};padding:3px 10px;border-radius:50px;font-size:12px;">${fmtStatus(o.order_status)}</span>
      </div>
    </div>
    <div class="drawer-section">
      <h4>Customer</h4>
      <div class="drawer-field"><span class="lbl">Name</span><span class="val">${o.customer_name}</span></div>
      <div class="drawer-field"><span class="lbl">Phone</span>
        <span class="val"><a href="tel:${o.customer_phone}" style="color:var(--primary);">${o._phone_fmt}</a></span>
      </div>
      ${o.customer_email?`<div class="drawer-field"><span class="lbl">Email</span><span class="val">${o.customer_email}</span></div>`:''}
      ${o.delivery_address&&o.delivery_address!=='Farm Counter'?`<div class="drawer-field"><span class="lbl">Address</span><span class="val">${o.delivery_address}</span></div>`:''}
    </div>
    <div class="drawer-section">
      <h4>Items</h4>
      ${itemsHtml}
    </div>
    <div class="drawer-section">
      <h4>Payment</h4>
      <div class="drawer-field"><span class="lbl">Method</span><span class="val">${o.payment_method?.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())||'—'}</span></div>
      <div class="drawer-field"><span class="lbl">Total</span><span class="val" style="font-size:18px;color:var(--primary);">${formatCur(o.total_amount)}</span></div>
      ${received>0?`<div class="drawer-field"><span class="lbl">Received</span><span class="val">${formatCur(received)}</span></div>`:''}
      ${change>0?`<div class="drawer-field"><span class="lbl">Change</span><span class="val" style="color:#16a34a;">${formatCur(change)}</span></div>`:''}
      <div class="drawer-field"><span class="lbl">Payment status</span><span class="val">${o.payment_status||'—'}</span></div>
    </div>
    <div class="drawer-section">
      <h4>Update Status</h4>
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;">
        ${statuses.map(s=>{
          const [bc2,tc2,bgc2] = statusColors[s]||['#9ca3af','#374151','#f3f4f6'];
          const active = o.order_status===s;
          return `<button onclick="inlineStatusUpdate(${id},Object.assign(document.getElementById('stat_${id}')||{},{value:'${s}',dataset:{}}));document.getElementById('stat_${id}')&&(document.getElementById('stat_${id}').value='${s}');ordersData[${id}].order_status='${s}';renderDrawer(${id});"
            style="padding:8px 10px;border-radius:8px;border:2px solid ${active?bc2:'#e5e7eb'};background:${active?bgc2:'white'};color:${active?tc2:'#6b7280'};font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;">
            ${fmtStatus(s)}</button>`;
        }).join('')}
      </div>
    </div>
    ${o.notes?`<div class="drawer-section"><h4>Notes</h4><div style="background:#f9fafb;border-radius:8px;padding:10px 12px;font-size:13px;">${o.notes}</div></div>`:''}
  `;
}

document.addEventListener('keydown', e => { if(e.key==='Escape') closeDrawer(); });

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type='success') {
  const d = document.createElement('div');
  const c = type==='error'?'#ef4444':'#4A7C59';
  d.style.cssText=`position:fixed;bottom:24px;right:24px;z-index:9999;background:white;padding:12px 20px;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.15);font-size:13px;font-weight:600;border-left:4px solid ${c};`;
  d.textContent=msg; document.body.appendChild(d);
  setTimeout(()=>d.remove(),2500);
}

// Initialise prev values for selects
document.querySelectorAll('.status-select').forEach(s => s.dataset.prev = s.value);

<?php if($viewId && isset($drawerOrders[$viewId])): ?>
window.addEventListener('DOMContentLoaded',()=>openDrawer(<?php echo $viewId; ?>));
<?php endif; ?>
</script>
</body>
</html>
