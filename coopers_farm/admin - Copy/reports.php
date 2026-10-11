<?php
require_once '../config/config.php';
requireAdmin();

// ── Date range filters ──────────────────────────────────────
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');         // start of current month
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');          // today
$report   = $_GET['report']    ?? 'overview';
$export   = $_GET['export']    ?? '';

// ── Helper: safe date range SQL ────────────────────────────
$dr = "DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'";

// ── Fetch all report data ───────────────────────────────────

// OVERVIEW STATS
$stats = [
    'total_revenue'   => $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'total_orders'    => $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'walkin_orders'   => $pdo->query("SELECT COUNT(*) FROM orders WHERE order_type='walkin' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'online_orders'   => $pdo->query("SELECT COUNT(*) FROM orders WHERE order_type='online' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'total_customers' => $pdo->query("SELECT COUNT(DISTINCT user_id) FROM orders WHERE user_id IS NOT NULL AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'new_customers'   => $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'total_eggs'      => $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE production_date BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'eggs_sold'       => $pdo->query("SELECT COALESCE(SUM(sold_eggs),0) FROM egg_production WHERE production_date BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'eggs_broken'     => $pdo->query("SELECT COALESCE(SUM(broken_eggs),0) FROM egg_production WHERE production_date BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'total_mortality' => $pdo->query("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE log_date BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'vaccinations_done' => $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE status='completed' AND date_given BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'active_flocks'   => $pdo->query("SELECT COUNT(*) FROM flocks WHERE status='active'")->fetchColumn(),
    'total_birds'     => $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM flocks WHERE status='active'")->fetchColumn(),
    'feed_cost'       => $pdo->query("SELECT COALESCE(SUM(cost),0) FROM feed_logs WHERE log_date BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
    'health_cases'    => $pdo->query("SELECT COUNT(*) FROM health_records WHERE record_date BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn(),
];

// ORDERS DETAIL
$orders = $pdo->query("
    SELECT o.*, COUNT(oi.id) as item_count
    FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id
    WHERE DATE(o.created_at) BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY o.id ORDER BY o.created_at DESC
")->fetchAll();

// CUSTOMERS
$customers = $pdo->query("
    SELECT u.id, u.name, u.email, u.phone, u.location, u.created_at,
           COUNT(o.id) as order_count,
           COALESCE(SUM(o.total_amount),0) as total_spent,
           MAX(o.created_at) as last_order
    FROM users u LEFT JOIN orders o ON o.user_id=u.id
    WHERE u.role='customer'
    GROUP BY u.id ORDER BY total_spent DESC
")->fetchAll();

// EGG PRODUCTION
$eggData = $pdo->query("
    SELECT ep.*, f.flock_name,
           (ep.quantity - ep.sold_eggs - ep.broken_eggs) as remaining
    FROM egg_production ep JOIN flocks f ON f.id=ep.flock_id
    WHERE ep.production_date BETWEEN '$dateFrom' AND '$dateTo'
    ORDER BY ep.production_date DESC
")->fetchAll();

// MORTALITY
$mortalityData = $pdo->query("
    SELECT ml.*, f.flock_name, f.bird_type, f.quantity as flock_size,
           ROUND((ml.count/f.quantity)*100,2) as mortality_rate
    FROM mortality_logs ml JOIN flocks f ON f.id=ml.flock_id
    WHERE ml.log_date BETWEEN '$dateFrom' AND '$dateTo'
    ORDER BY ml.log_date DESC
")->fetchAll();

// FLOCKS
$flockData = $pdo->query("
    SELECT f.*,
           DATEDIFF(CURDATE(), f.arrival_date) as age_days,
           (SELECT COUNT(*) FROM vaccinations v WHERE v.flock_id=f.id) as vacc_count,
           (SELECT COALESCE(SUM(m.count),0) FROM mortality_logs m WHERE m.flock_id=f.id) as total_deaths,
           (SELECT COALESCE(SUM(e.quantity),0) FROM egg_production e WHERE e.flock_id=f.id) as total_eggs_produced
    FROM flocks f ORDER BY f.created_at DESC
")->fetchAll();

// FEED
$feedData = $pdo->query("
    SELECT fl.*, f.flock_name
    FROM feed_logs fl JOIN flocks f ON f.id=fl.flock_id
    WHERE fl.log_date BETWEEN '$dateFrom' AND '$dateTo'
    ORDER BY fl.log_date DESC
")->fetchAll();

$feedSummary = $pdo->query("
    SELECT feed_type, SUM(quantity_kg) as total_kg, SUM(cost) as total_cost
    FROM feed_logs WHERE log_date BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY feed_type ORDER BY total_cost DESC
")->fetchAll();

// HEALTH
$healthData = $pdo->query("
    SELECT hr.*, f.flock_name
    FROM health_records hr JOIN flocks f ON f.id=hr.flock_id
    WHERE hr.record_date BETWEEN '$dateFrom' AND '$dateTo'
    ORDER BY hr.record_date DESC
")->fetchAll();

// VACCINATIONS
$vaccData = $pdo->query("
    SELECT v.*, f.flock_name, f.bird_type
    FROM vaccinations v JOIN flocks f ON f.id=v.flock_id
    WHERE v.date_given BETWEEN '$dateFrom' AND '$dateTo'
    ORDER BY v.date_given DESC
")->fetchAll();

// REVENUE BY PRODUCT
$revenueByProduct = $pdo->query("
    SELECT oi.product_name, oi.product_id,
           SUM(oi.quantity) as total_qty,
           SUM(oi.subtotal) as total_revenue,
           AVG(oi.unit_price) as avg_price
    FROM order_items oi
    JOIN orders o ON o.id=oi.order_id
    WHERE DATE(o.created_at) BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY oi.product_name, oi.product_id ORDER BY total_revenue DESC
")->fetchAll();

// REVENUE BY PAYMENT METHOD
$revenueByPayment = $pdo->query("
    SELECT payment_method, COUNT(*) as count, SUM(total_amount) as total
    FROM orders WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY payment_method ORDER BY total DESC
")->fetchAll();

// DAILY REVENUE CHART
$dailyRevenue = $pdo->query("
    SELECT sale_date as date, SUM(total_amount) as amount
    FROM sales WHERE sale_date BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY sale_date ORDER BY sale_date
")->fetchAll();

// ── CSV EXPORT ──────────────────────────────────────────────
if ($export) {
    $filename = "coopers_farm_{$export}_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $out = fopen('php://output', 'w');

    switch ($export) {
        case 'orders':
            fputcsv($out, ['Order #','Customer','Phone','Email','Type','Total','Payment','Status','Date']);
            foreach ($orders as $r) {
                fputcsv($out, [$r['order_number'],$r['customer_name'],$r['customer_phone'],$r['customer_email'] ?? '',
                    $r['order_type'] ?? 'online', $r['total_amount'], $r['payment_method'], $r['order_status'],
                    date('Y-m-d H:i', strtotime($r['created_at']))]);
            }
            break;
        case 'customers':
            fputcsv($out, ['Name','Email','Phone','Location','Orders','Total Spent','Last Order','Joined']);
            foreach ($customers as $r) {
                fputcsv($out, [$r['name'],$r['email'],$r['phone']??'',$r['location']??'',$r['order_count'],
                    $r['total_spent'],$r['last_order']??'',date('Y-m-d',strtotime($r['created_at']))]);
            }
            break;
        case 'eggs':
            fputcsv($out, ['Date','Flock','Collected','Broken','Sold','Remaining']);
            foreach ($eggData as $r) {
                fputcsv($out, [$r['production_date'],$r['flock_name'],$r['quantity'],$r['broken_eggs'],$r['sold_eggs'],$r['remaining']]);
            }
            break;
        case 'mortality':
            fputcsv($out, ['Date','Flock','Type','Deaths','Cause','Mortality Rate %','Notes']);
            foreach ($mortalityData as $r) {
                fputcsv($out, [$r['log_date'],$r['flock_name'],$r['bird_type'],$r['count'],$r['cause']??'',$r['mortality_rate'],$r['notes']??'']);
            }
            break;
        case 'flocks':
            fputcsv($out, ['Flock','Type','Quantity','Arrival','Age (days)','Housing','Status','Vaccinations','Deaths','Eggs Produced']);
            foreach ($flockData as $r) {
                fputcsv($out, [$r['flock_name'],$r['bird_type'],$r['quantity'],
                    $r['arrival_date'],$r['age_days'],$r['housing_unit']??'',$r['status'],
                    $r['vacc_count'],$r['total_deaths'],$r['total_eggs_produced']]);
            }
            break;
        case 'feed':
            fputcsv($out, ['Date','Flock','Feed Type','Quantity (kg)','Cost (GHS)','Notes']);
            foreach ($feedData as $r) {
                fputcsv($out, [$r['log_date'],$r['flock_name'],$r['feed_type'],$r['quantity_kg'],$r['cost']??0,$r['notes']??'']);
            }
            break;
        case 'health':
            fputcsv($out, ['Date','Flock','Type','Disease/Issue','Affected','Treatment','Treated By','Outcome','Status']);
            foreach ($healthData as $r) {
                fputcsv($out, [$r['record_date'],$r['flock_name'],$r['type'],$r['disease_name']??'',$r['affected_count'],
                    $r['treatment']??'',$r['treated_by']??'',$r['outcome']??'',$r['status']]);
            }
            break;
        case 'vaccinations':
            fputcsv($out, ['Date Given','Flock','Type','Vaccine','Dosage','Method','Birds Vaccinated','Administered By','Next Due','Status']);
            foreach ($vaccData as $r) {
                fputcsv($out, [$r['date_given'],$r['flock_name'],$r['bird_type'],$r['vaccine_name'],
                    $r['dosage']??'',$r['method'],$r['birds_vaccinated']??'',$r['administered_by']??'',$r['next_due_date']??'',$r['status']]);
            }
            break;
        case 'overview':
            fputcsv($out, ['Metric','Value']);
            foreach ($stats as $k => $v) {
                fputcsv($out, [ucwords(str_replace('_',' ',$k)), $v]);
            }
            break;
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reports — Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css">
  <style>
    .report-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:24px; }
    .report-tab { padding:8px 18px; border-radius:50px; border:1.5px solid var(--border); font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; color:var(--text-mid); transition:all 0.2s; }
    .report-tab:hover, .report-tab.active { background:var(--primary); color:white; border-color:var(--primary); }
    .stat-mini { background:white; border-radius:10px; padding:16px 18px; border:1px solid var(--border); }
    .stat-mini-val { font-size:26px; font-weight:700; font-family:'Playfair Display',serif; }
    .stat-mini-lbl { font-size:12px; color:#888; margin-top:2px; }
    .overview-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; margin-bottom:24px; }
    .export-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 18px; background:#EDF5E8; color:var(--primary); border:1.5px solid var(--primary); border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; transition:all 0.2s; }
    .export-btn:hover { background:var(--primary); color:white; }
    .section-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
    .section-head h3 { font-size:18px; font-weight:700; font-family:'Playfair Display',serif; }
    .date-form { display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:white; padding:14px 20px; border-radius:12px; border:1px solid var(--border); margin-bottom:20px; }
    .date-form label { font-size:13px; font-weight:600; color:var(--text-mid); }
    .date-form input { padding:8px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:13px; font-family:inherit; outline:none; }
    .date-form input:focus { border-color:var(--primary); }
    .chart-wrap { background:white; border-radius:12px; border:1px solid var(--border); padding:20px; margin-bottom:20px; }
    .summary-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
    @media(max-width:900px){ .summary-cards { grid-template-columns:1fr 1fr; } }
    @media(max-width:600px){ .summary-cards { grid-template-columns:1fr; } .report-tabs { gap:4px; } .report-tab { padding:7px 12px; font-size:12px; } }
    @media print {
      .sidebar,.topbar,.report-tabs,.date-form,.export-btn,.btn { display:none !important; }
      .main-content { margin-left:0 !important; }
      .dashboard-content { padding:10px !important; }
    }
  </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">

    <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h1>Reports &amp; Analytics</h1>
        <p>Comprehensive farm performance data · <?php echo date('M d', strtotime($dateFrom)); ?> – <?php echo date('M d, Y', strtotime($dateTo)); ?></p>
      </div>
      <div style="display:flex;gap:10px;">
        <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Print</button>
        <a href="?report=<?php echo $report; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&export=<?php echo $report; ?>" class="export-btn">
          <i class="fas fa-download"></i> Export CSV
        </a>
      </div>
    </div>

    <!-- Date Range Filter -->
    <form method="GET" class="date-form">
      <input type="hidden" name="report" value="<?php echo $report; ?>">
      <label>From:</label>
      <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
      <label>To:</label>
      <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
      <a href="?report=<?php echo $report; ?>&date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary btn-sm">This Month</a>
      <a href="?report=<?php echo $report; ?>&date_from=<?php echo date('Y-01-01'); ?>&date_to=<?php echo date('Y-12-31'); ?>" class="btn btn-secondary btn-sm">This Year</a>
      <a href="?report=<?php echo $report; ?>&date_from=2020-01-01&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary btn-sm">All Time</a>
    </form>

    <!-- Report Navigation Tabs -->
    <div class="report-tabs">
      <?php
      $tabs = [
        'overview'     => ['fas fa-chart-pie',    'Overview'],
        'orders'       => ['fas fa-shopping-cart', 'Orders'],
        'customers'    => ['fas fa-users',         'Customers'],
        'eggs'         => ['fas fa-egg',           'Egg Production'],
        'mortality'    => ['fas fa-skull',         'Mortality'],
        'flocks'       => ['fas fa-kiwi-bird',     'Flocks'],
        'feed'         => ['fas fa-seedling',      'Feed'],
        'health'       => ['fas fa-heartbeat',     'Health'],
        'vaccinations' => ['fas fa-syringe',       'Vaccinations'],
        'revenue'      => ['fas fa-coins',         'Revenue'],
      ];
      foreach ($tabs as $key => [$icon, $label]):
      ?>
      <a href="?report=<?php echo $key; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>"
         class="report-tab <?php echo $report===$key?'active':''; ?>">
        <i class="<?php echo $icon; ?>"></i> <?php echo $label; ?>
      </a>
      <?php endforeach; ?>
    </div>

    <?php // ════════════════════════════════════════════════
          // OVERVIEW
          // ════════════════════════════════════════════════
    if ($report === 'overview'): ?>

    <div class="overview-grid">
      <?php
      $overviewCards = [
        ['Total Revenue',    formatCurrency($stats['total_revenue']),    '#4A7C59', 'fas fa-coins'],
        ['Total Orders',     number_format($stats['total_orders']),      '#3b82f6', 'fas fa-shopping-cart'],
        ['Walk-in Sales',    number_format($stats['walkin_orders']),     '#E87722', 'fas fa-store'],
        ['Online Orders',    number_format($stats['online_orders']),     '#8b5cf6', 'fas fa-globe'],
        ['New Customers',    number_format($stats['new_customers']),     '#06b6d4', 'fas fa-user-plus'],
        ['Eggs Collected',   number_format($stats['total_eggs']),        '#fbbf24', 'fas fa-egg'],
        ['Eggs Sold',        number_format($stats['eggs_sold']),         '#4A7C59', 'fas fa-egg'],
        ['Active Flocks',    number_format($stats['active_flocks']),     '#7EAD6E', 'fas fa-kiwi-bird'],
        ['Total Birds',      number_format($stats['total_birds']),       '#4A7C59', 'fas fa-kiwi-bird'],
        ['Mortality',        number_format($stats['total_mortality']),   '#ef4444', 'fas fa-skull'],
        ['Feed Cost',        formatCurrency($stats['feed_cost']),        '#8B5E3C', 'fas fa-seedling'],
        ['Vaccinations',     number_format($stats['vaccinations_done']), '#60a5fa', 'fas fa-syringe'],
        ['Health Cases',     number_format($stats['health_cases']),      '#f87171', 'fas fa-heartbeat'],
      ];
      foreach ($overviewCards as [$lbl, $val, $color, $icon]):
      ?>
      <div class="stat-mini">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div>
            <div class="stat-mini-val" style="color:<?php echo $color; ?>"><?php echo $val; ?></div>
            <div class="stat-mini-lbl"><?php echo $lbl; ?></div>
          </div>
          <i class="<?php echo $icon; ?>" style="color:<?php echo $color; ?>;opacity:0.3;font-size:24px;margin-top:4px;"></i>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Daily revenue chart -->
    <div class="chart-wrap">
      <div class="section-head" style="margin-bottom:12px;">
        <h3>Daily Revenue</h3>
        <span style="font-size:13px;color:#888;"><?php echo count($dailyRevenue); ?> days with sales</span>
      </div>
      <canvas id="dailyChart" height="70"></canvas>
    </div>

    <!-- Revenue by product -->
    <div class="chart-wrap">
      <div class="section-head"><h3>Top Products by Revenue</h3></div>
      <table class="data-table">
        <thead><tr><th>Product</th><th>Qty Sold</th><th>Avg Price</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php foreach($revenueByProduct as $r): ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($r['product_name']); ?></strong></td>
            <td><?php echo number_format($r['total_qty']); ?></td>
            <td><?php echo formatCurrency($r['avg_price']); ?></td>
            <td><strong style="color:var(--primary);"><?php echo formatCurrency($r['total_revenue']); ?></strong></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($revenueByProduct)): ?><tr><td colspan="4" style="text-align:center;color:#888;padding:24px;">No sales in this period</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Payment methods breakdown -->
    <div class="chart-wrap">
      <div class="section-head"><h3>Revenue by Payment Method</h3></div>
      <table class="data-table">
        <thead><tr><th>Payment Method</th><th>Orders</th><th>Total Revenue</th></tr></thead>
        <tbody>
          <?php foreach($revenueByPayment as $r): ?>
          <tr>
            <td><?php echo ucwords(str_replace(['momo_','_'],['',''],$r['payment_method'])); ?></td>
            <td><?php echo $r['count']; ?></td>
            <td><strong><?php echo formatCurrency($r['total']); ?></strong></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php // ════════════════════════════════════════
          // ORDERS
          // ════════════════════════════════════════
    elseif ($report === 'orders'): ?>

    <div class="summary-cards">
      <div class="stat-mini"><div class="stat-mini-val"><?php echo count($orders); ?></div><div class="stat-mini-lbl">Total Orders</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:var(--primary);"><?php echo formatCurrency(array_sum(array_column($orders,'total_amount'))); ?></div><div class="stat-mini-lbl">Total Revenue</div></div>
      <div class="stat-mini"><div class="stat-mini-val"><?php echo number_format(array_sum(array_column($orders,'total_amount')) / max(1,count($orders)),2); ?></div><div class="stat-mini-lbl">Avg Order Value</div></div>
    </div>

    <div class="card">
      <div class="section-head" style="padding:18px 24px 0;">
        <h3>All Orders (<?php echo count($orders); ?>)</h3>
        <a href="?report=orders&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&export=orders" class="export-btn"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Order #</th><th>Customer</th><th>Phone</th><th>Type</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
            <?php foreach($orders as $o): ?>
            <tr>
              <td><strong><?php echo $o['order_number']; ?></strong></td>
              <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
              <td><?php echo htmlspecialchars($o['customer_phone']??''); ?></td>
              <td><span class="status-badge <?php echo ($o['order_type']??'online')==='walkin'?'badge-confirmed':'badge-scheduled'; ?>">
                <?php echo ($o['order_type']??'online')==='walkin'?'🏪 Walk-in':'🌐 Online'; ?>
              </span></td>
              <td><?php echo $o['item_count']; ?></td>
              <td><strong><?php echo formatCurrency($o['total_amount']); ?></strong></td>
              <td><?php echo ucwords(str_replace(['momo_','_'],['',''],$o['payment_method']??'')); ?></td>
              <td><span class="status-badge badge-<?php echo $o['order_status']; ?>"><?php echo ucwords(str_replace('_',' ',$o['order_status'])); ?></span></td>
              <td><?php echo date('M d, Y', strtotime($o['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($orders)): ?><tr><td colspan="9" style="text-align:center;color:#888;padding:30px;">No orders in this period</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php // ════════════════════════════════════════
          // CUSTOMERS
          // ════════════════════════════════════════
    elseif ($report === 'customers'): ?>

    <div class="summary-cards">
      <div class="stat-mini"><div class="stat-mini-val"><?php echo count($customers); ?></div><div class="stat-mini-lbl">Total Customers</div></div>
      <div class="stat-mini"><div class="stat-mini-val"><?php echo count(array_filter($customers,fn($c)=>$c['order_count']>0)); ?></div><div class="stat-mini-lbl">Paying Customers</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:var(--primary);"><?php echo formatCurrency(array_sum(array_column($customers,'total_spent'))); ?></div><div class="stat-mini-lbl">Total Customer Revenue</div></div>
    </div>

    <div class="card">
      <div class="section-head" style="padding:18px 24px 0;">
        <h3>All Customers (<?php echo count($customers); ?>)</h3>
        <a href="?report=customers&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&export=customers" class="export-btn"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Customer</th><th>Phone</th><th>Location</th><th>Orders</th><th>Total Spent</th><th>Last Order</th><th>Joined</th></tr></thead>
          <tbody>
            <?php foreach($customers as $c): ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;"><?php echo strtoupper(substr($c['name'],0,1)); ?></div>
                  <div><strong><?php echo htmlspecialchars($c['name']); ?></strong><br><small style="color:#888;"><?php echo htmlspecialchars($c['email']); ?></small></div>
                </div>
              </td>
              <td><?php echo htmlspecialchars($c['phone']??'—'); ?></td>
              <td><?php echo htmlspecialchars($c['location']??'—'); ?></td>
              <td><strong><?php echo $c['order_count']; ?></strong></td>
              <td><strong style="color:var(--primary);"><?php echo formatCurrency($c['total_spent']); ?></strong></td>
              <td><?php echo $c['last_order'] ? date('M d, Y',strtotime($c['last_order'])) : '—'; ?></td>
              <td><?php echo date('M d, Y',strtotime($c['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php // ════════════════════════════════════════
          // EGG PRODUCTION
          // ════════════════════════════════════════
    elseif ($report === 'eggs'): ?>

    <div class="summary-cards">
      <div class="stat-mini"><div class="stat-mini-val" style="color:#fbbf24;"><?php echo number_format($stats['total_eggs']); ?></div><div class="stat-mini-lbl">Total Collected</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:var(--primary);"><?php echo number_format($stats['eggs_sold']); ?></div><div class="stat-mini-lbl">Total Sold</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:#ef4444;"><?php echo number_format($stats['eggs_broken']); ?></div><div class="stat-mini-lbl">Broken / Wasted</div></div>
    </div>

    <div class="card">
      <div class="section-head" style="padding:18px 24px 0;">
        <h3>Egg Production Log</h3>
        <a href="?report=eggs&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&export=eggs" class="export-btn"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Flock</th><th>Collected</th><th>Broken</th><th>Sold</th><th>Remaining</th><th>Productivity</th></tr></thead>
          <tbody>
            <?php foreach($eggData as $r):
              $rem = max(0, $r['quantity'] - $r['sold_eggs'] - $r['broken_eggs']);
              $pct = $r['quantity'] > 0 ? round(($r['sold_eggs']/$r['quantity'])*100) : 0;
            ?>
            <tr>
              <td><?php echo date('M d, Y',strtotime($r['production_date'])); ?></td>
              <td><?php echo htmlspecialchars($r['flock_name']); ?></td>
              <td><strong><?php echo number_format($r['quantity']); ?></strong></td>
              <td style="color:#ef4444;"><?php echo $r['broken_eggs']; ?></td>
              <td style="color:var(--primary);font-weight:600;"><?php echo number_format($r['sold_eggs']); ?></td>
              <td style="font-weight:600;"><?php echo number_format($rem); ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px;">
                  <div class="progress-bar" style="width:70px;"><div class="progress-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                  <span style="font-size:12px;"><?php echo $pct; ?>%</span>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($eggData)): ?><tr><td colspan="7" style="text-align:center;color:#888;padding:30px;">No egg production data in this period</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php // ════════════════════════════════════════
          // MORTALITY
          // ════════════════════════════════════════
    elseif ($report === 'mortality'): ?>

    <div class="summary-cards">
      <div class="stat-mini"><div class="stat-mini-val" style="color:#ef4444;"><?php echo number_format($stats['total_mortality']); ?></div><div class="stat-mini-lbl">Total Deaths</div></div>
      <div class="stat-mini"><div class="stat-mini-val"><?php echo count($mortalityData); ?></div><div class="stat-mini-lbl">Log Entries</div></div>
      <div class="stat-mini"><div class="stat-mini-val"><?php echo $stats['total_birds']>0?round(($stats['total_mortality']/$stats['total_birds'])*100,2).'%':'0%'; ?></div><div class="stat-mini-lbl">Mortality Rate</div></div>
    </div>

    <div class="card">
      <div class="section-head" style="padding:18px 24px 0;">
        <h3>Mortality Log</h3>
        <a href="?report=mortality&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&export=mortality" class="export-btn"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Flock</th><th>Type</th><th>Deaths</th><th>Cause</th><th>Rate</th><th>Notes</th></tr></thead>
          <tbody>
            <?php foreach($mortalityData as $r): ?>
            <tr>
              <td><?php echo date('M d, Y',strtotime($r['log_date'])); ?></td>
              <td><strong><?php echo htmlspecialchars($r['flock_name']); ?></strong></td>
              <td><?php echo ucfirst($r['bird_type']); ?></td>
              <td style="color:#ef4444;font-weight:700;"><?php echo number_format($r['count']); ?></td>
              <td><?php echo htmlspecialchars($r['cause']??'Unknown'); ?></td>
              <td><span style="color:<?php echo $r['mortality_rate']>2?'#ef4444':($r['mortality_rate']>1?'#fbbf24':'#4ade80'); ?>;font-weight:700;"><?php echo $r['mortality_rate']; ?>%</span></td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars(substr($r['notes']??'',0,50)); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($mortalityData)): ?><tr><td colspan="7" style="text-align:center;color:#888;padding:30px;">No mortality records in this period</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php // ════════════════════════════════════════
          // FLOCKS
          // ════════════════════════════════════════
    elseif ($report === 'flocks'): ?>

    <div class="summary-cards">
      <div class="stat-mini"><div class="stat-mini-val"><?php echo count($flockData); ?></div><div class="stat-mini-lbl">Total Flocks</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:var(--primary);"><?php echo count(array_filter($flockData,fn($f)=>$f['status']==='active')); ?></div><div class="stat-mini-lbl">Active Flocks</div></div>
      <div class="stat-mini"><div class="stat-mini-val"><?php echo number_format(array_sum(array_column(array_filter($flockData,fn($f)=>$f['status']==='active'),'quantity'))); ?></div><div class="stat-mini-lbl">Total Active Birds</div></div>
    </div>

    <div class="card">
      <div class="section-head" style="padding:18px 24px 0;">
        <h3>All Flocks</h3>
        <a href="?report=flocks&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&export=flocks" class="export-btn"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Flock</th><th>Type</th><th>Birds</th><th>Arrived</th><th>Age</th><th>Housing</th><th>Status</th><th>Vaccinations</th><th>Deaths</th><th>Eggs</th></tr></thead>
          <tbody>
            <?php foreach($flockData as $f):
              $age = $f['age_days'];
              $ageStr = $age < 7 ? "{$age}d" : ($age < 30 ? round($age/7)."w" : round($age/30)."m");
            ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($f['flock_name']); ?></strong></td>
              <td><?php echo ucfirst($f['bird_type']); ?></td>
              <td><strong><?php echo number_format($f['quantity']); ?></strong></td>
              <td><?php echo date('M d, Y',strtotime($f['arrival_date'])); ?></td>
              <td><?php echo $ageStr; ?></td>
              <td><?php echo htmlspecialchars($f['housing_unit']??'—'); ?></td>
              <td><span class="status-badge badge-<?php echo $f['status']; ?>"><?php echo ucfirst($f['status']); ?></span></td>
              <td><?php echo $f['vacc_count']; ?></td>
              <td style="color:#ef4444;font-weight:<?php echo $f['total_deaths']>0?'700':'400'; ?>;"><?php echo number_format($f['total_deaths']); ?></td>
              <td style="color:var(--primary);"><?php echo number_format($f['total_eggs_produced']); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php // ════════════════════════════════════════
          // FEED
          // ════════════════════════════════════════
    elseif ($report === 'feed'): ?>

    <div class="summary-cards">
      <div class="stat-mini"><div class="stat-mini-val"><?php echo number_format(array_sum(array_column($feedData,'quantity_kg')),1); ?>kg</div><div class="stat-mini-lbl">Total Feed Used</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:var(--primary);"><?php echo formatCurrency($stats['feed_cost']); ?></div><div class="stat-mini-lbl">Total Feed Cost</div></div>
      <div class="stat-mini"><div class="stat-mini-val"><?php echo count($feedSummary); ?></div><div class="stat-mini-lbl">Feed Types Used</div></div>
    </div>

    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><h3>Feed Summary by Type</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Feed Type</th><th>Total Used (kg)</th><th>Total Cost</th><th>Cost/kg</th></tr></thead>
          <tbody>
            <?php foreach($feedSummary as $r): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($r['feed_type']); ?></strong></td>
              <td><?php echo number_format($r['total_kg'],1); ?> kg</td>
              <td><strong><?php echo formatCurrency($r['total_cost']); ?></strong></td>
              <td><?php echo $r['total_kg']>0?formatCurrency($r['total_cost']/$r['total_kg']).'/ kg':'—'; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="section-head" style="padding:18px 24px 0;">
        <h3>Detailed Feed Log</h3>
        <a href="?report=feed&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&export=feed" class="export-btn"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Flock</th><th>Feed Type</th><th>Quantity</th><th>Cost</th><th>Notes</th></tr></thead>
          <tbody>
            <?php foreach($feedData as $r): ?>
            <tr>
              <td><?php echo date('M d, Y',strtotime($r['log_date'])); ?></td>
              <td><?php echo htmlspecialchars($r['flock_name']); ?></td>
              <td><?php echo htmlspecialchars($r['feed_type']); ?></td>
              <td><?php echo number_format($r['quantity_kg'],1); ?> kg</td>
              <td><?php echo $r['cost']?formatCurrency($r['cost']):'—'; ?></td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars(substr($r['notes']??'',0,50)); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($feedData)): ?><tr><td colspan="6" style="text-align:center;color:#888;padding:30px;">No feed logs in this period</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php // ════════════════════════════════════════
          // HEALTH
          // ════════════════════════════════════════
    elseif ($report === 'health'): ?>

    <div class="summary-cards">
      <div class="stat-mini"><div class="stat-mini-val"><?php echo count($healthData); ?></div><div class="stat-mini-lbl">Total Cases</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:#4ade80;"><?php echo count(array_filter($healthData,fn($r)=>$r['outcome']==='recovered')); ?></div><div class="stat-mini-lbl">Recovered</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:#ef4444;"><?php echo count(array_filter($healthData,fn($r)=>$r['status']==='active')); ?></div><div class="stat-mini-lbl">Active Cases</div></div>
    </div>

    <div class="card">
      <div class="section-head" style="padding:18px 24px 0;">
        <h3>Health Records</h3>
        <a href="?report=health&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&export=health" class="export-btn"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Flock</th><th>Type</th><th>Issue</th><th>Affected</th><th>Treatment</th><th>Outcome</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach($healthData as $r): ?>
            <tr>
              <td><?php echo date('M d, Y',strtotime($r['record_date'])); ?></td>
              <td><?php echo htmlspecialchars($r['flock_name']); ?></td>
              <td><span class="status-badge badge-confirmed"><?php echo ucfirst($r['type']); ?></span></td>
              <td><?php echo htmlspecialchars($r['disease_name']??$r['description']??'—'); ?></td>
              <td><?php echo number_format($r['affected_count']); ?> birds</td>
              <td style="font-size:12px;max-width:120px;"><?php echo htmlspecialchars(substr($r['treatment']??'',0,50)); ?></td>
              <td><span class="status-badge <?php echo $r['outcome']==='recovered'?'badge-active':($r['outcome']==='died'?'badge-cancelled':'badge-pending'); ?>"><?php echo ucfirst($r['outcome']??'pending'); ?></span></td>
              <td><span class="status-badge badge-<?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($healthData)): ?><tr><td colspan="8" style="text-align:center;color:#888;padding:30px;">No health records in this period</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php // ════════════════════════════════════════
          // VACCINATIONS
          // ════════════════════════════════════════
    elseif ($report === 'vaccinations'): ?>

    <div class="summary-cards">
      <div class="stat-mini"><div class="stat-mini-val"><?php echo count($vaccData); ?></div><div class="stat-mini-lbl">Total Records</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:var(--primary);"><?php echo count(array_filter($vaccData,fn($r)=>$r['status']==='completed')); ?></div><div class="stat-mini-lbl">Completed</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:#ef4444;"><?php echo count(array_filter($vaccData,fn($r)=>$r['status']==='overdue')); ?></div><div class="stat-mini-lbl">Overdue</div></div>
    </div>

    <div class="card">
      <div class="section-head" style="padding:18px 24px 0;">
        <h3>Vaccination Records</h3>
        <a href="?report=vaccinations&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&export=vaccinations" class="export-btn"><i class="fas fa-download"></i> CSV</a>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Flock</th><th>Type</th><th>Vaccine</th><th>Method</th><th>Birds</th><th>By</th><th>Next Due</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach($vaccData as $r): ?>
            <tr>
              <td><?php echo date('M d, Y',strtotime($r['date_given'])); ?></td>
              <td><strong><?php echo htmlspecialchars($r['flock_name']); ?></strong></td>
              <td><?php echo ucfirst($r['bird_type']); ?></td>
              <td><?php echo htmlspecialchars($r['vaccine_name']); ?></td>
              <td><?php echo ucwords(str_replace('_',' ',$r['method'])); ?></td>
              <td><?php echo $r['birds_vaccinated']?number_format($r['birds_vaccinated']):'—'; ?></td>
              <td><?php echo htmlspecialchars($r['administered_by']??'—'); ?></td>
              <td><?php echo $r['next_due_date']?date('M d, Y',strtotime($r['next_due_date'])):'—'; ?></td>
              <td><span class="status-badge badge-<?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($vaccData)): ?><tr><td colspan="9" style="text-align:center;color:#888;padding:30px;">No vaccination records in this period</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php // ════════════════════════════════════════
          // REVENUE BREAKDOWN
          // ════════════════════════════════════════
    elseif ($report === 'revenue'): ?>

    <div class="summary-cards">
      <div class="stat-mini"><div class="stat-mini-val" style="color:var(--primary);"><?php echo formatCurrency($stats['total_revenue']); ?></div><div class="stat-mini-lbl">Total Revenue</div></div>
      <div class="stat-mini"><div class="stat-mini-val"><?php echo formatCurrency($stats['feed_cost']); ?></div><div class="stat-mini-lbl">Feed Costs</div></div>
      <div class="stat-mini"><div class="stat-mini-val" style="color:#4ade80;"><?php echo formatCurrency(max(0,$stats['total_revenue']-$stats['feed_cost'])); ?></div><div class="stat-mini-lbl">Gross Profit</div></div>
    </div>

    <div class="chart-wrap">
      <div class="section-head"><h3>Daily Revenue</h3></div>
      <canvas id="revenueChartBig" height="80"></canvas>
    </div>

    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><h3>Revenue by Product</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Product</th><th>Units Sold</th><th>Avg Price</th><th>Revenue</th><th>% of Total</th></tr></thead>
          <tbody>
            <?php $totalRev = array_sum(array_column($revenueByProduct,'total_revenue')) ?: 1;
            foreach($revenueByProduct as $r):
              $pct = round(($r['total_revenue']/$totalRev)*100,1);
            ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($r['product_name']); ?></strong></td>
              <td><?php echo number_format($r['total_qty']); ?></td>
              <td><?php echo formatCurrency($r['avg_price']); ?></td>
              <td><strong style="color:var(--primary);"><?php echo formatCurrency($r['total_revenue']); ?></strong></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px;">
                  <div class="progress-bar" style="width:80px;"><div class="progress-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                  <span style="font-size:12px;"><?php echo $pct; ?>%</span>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Revenue by Payment Method</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Method</th><th>Transactions</th><th>Revenue</th><th>Avg Transaction</th></tr></thead>
          <tbody>
            <?php foreach($revenueByPayment as $r): ?>
            <tr>
              <td><strong><?php echo ucwords(str_replace(['momo_','_'],['',''],$r['payment_method'])); ?></strong></td>
              <td><?php echo number_format($r['count']); ?></td>
              <td><strong style="color:var(--primary);"><?php echo formatCurrency($r['total']); ?></strong></td>
              <td><?php echo formatCurrency($r['total']/max(1,$r['count'])); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php endif; ?>

  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="../public/js/admin.js"></script>
<script>
const dailyData = <?php echo json_encode($dailyRevenue); ?>;

function makeRevenueChart(canvasId) {
  const ctx = document.getElementById(canvasId);
  if (!ctx || !dailyData.length) return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: dailyData.map(d => new Date(d.date).toLocaleDateString('en-GB',{month:'short',day:'numeric'})),
      datasets: [{
        label: 'Revenue (₵)',
        data: dailyData.map(d => parseFloat(d.amount)),
        backgroundColor: '#4A7C59',
        borderRadius: 6,
        hoverBackgroundColor: '#2D5016'
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false },
        tooltip: { callbacks: { label: c => '₵' + c.parsed.y.toFixed(2) } }
      },
      scales: {
        y: { beginAtZero:true, grid:{ color:'#f3f4f6' }, ticks:{ callback: v => '₵'+v } },
        x: { grid:{ display:false } }
      }
    }
  });
}

makeRevenueChart('dailyChart');
makeRevenueChart('revenueChartBig');
</script>
</body>
</html>
