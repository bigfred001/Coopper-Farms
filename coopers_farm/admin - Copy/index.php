<?php
require_once '../config/config.php';
requireAdmin();

// ── Core stats ───────────────────────────────────────────────
$activeFlocks = $pdo->query("SELECT COUNT(*) FROM flocks WHERE status='active'")->fetchColumn();
$totalBirds   = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM flocks WHERE status='active'")->fetchColumn();

// Eggs — today, yesterday, week, month
$todayEggs     = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE production_date=CURDATE()")->fetchColumn();
$yesterdayEggs = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE production_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY)")->fetchColumn();
$weekEggs      = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE YEARWEEK(production_date)=YEARWEEK(CURDATE())")->fetchColumn();
$monthEggs     = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE MONTH(production_date)=MONTH(CURDATE()) AND YEAR(production_date)=YEAR(CURDATE())")->fetchColumn();
$lastMonthEggs = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE MONTH(production_date)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(production_date)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))")->fetchColumn();

// Sales — today, yesterday, week
$todaySales     = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=CURDATE()")->fetchColumn();
$yesterdaySales = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY)")->fetchColumn();
$weeklySales    = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE YEARWEEK(sale_date)=YEARWEEK(CURDATE())")->fetchColumn();
$lastWeekSales  = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE YEARWEEK(sale_date)=YEARWEEK(DATE_SUB(CURDATE(),INTERVAL 7 DAY))")->fetchColumn();
$monthSales     = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE())")->fetchColumn();

// Orders
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status='pending'")->fetchColumn();
$todayOrders   = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// Feed
$lowFeedStock = $pdo->query("SELECT COUNT(*) FROM feed_stock WHERE quantity_kg < reorder_level")->fetchColumn();
$feedStock    = $pdo->query("SELECT * FROM feed_stock ORDER BY feed_type")->fetchAll();

// Vaccinations
$overdueVacc  = $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE status='overdue'")->fetchColumn();
$upcomingVacc = $pdo->query("
    SELECT v.*, f.flock_name, f.bird_type
    FROM vaccinations v JOIN flocks f ON f.id=v.flock_id
    WHERE v.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
      AND v.status IN ('scheduled','overdue')
    ORDER BY v.next_due_date ASC LIMIT 5
")->fetchAll();
if (empty($upcomingVacc) && $overdueVacc > 0) {
    $upcomingVacc = $pdo->query("
        SELECT v.*, f.flock_name, f.bird_type
        FROM vaccinations v JOIN flocks f ON f.id=v.flock_id
        WHERE v.status='overdue' ORDER BY v.next_due_date ASC LIMIT 5
    ")->fetchAll();
}

// Recent orders
$recentOrders = $pdo->query("
    SELECT o.*, COALESCE(o.order_type,'online') as otype
    FROM orders o ORDER BY o.created_at DESC LIMIT 5
")->fetchAll();

// Charts
$salesChart = $pdo->query("
    SELECT DATE(sale_date) as date, SUM(total_amount) as amount
    FROM sales WHERE sale_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)
    GROUP BY DATE(sale_date) ORDER BY date
")->fetchAll();
$eggChart = $pdo->query("
    SELECT DATE(production_date) as date, SUM(quantity) as qty
    FROM egg_production WHERE production_date >= DATE_SUB(CURDATE(),INTERVAL 14 DAY)
    GROUP BY DATE(production_date) ORDER BY date
")->fetchAll();

// Productivity
$activeLayers       = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM flocks WHERE status='active' AND bird_type='layer'")->fetchColumn();
$expectedEggs       = max(1, $activeLayers * 0.8);
$productivity       = $activeLayers > 0 ? min(100, round(($todayEggs / $expectedEggs) * 100, 1)) : 0;
$productivityRating = $productivity >= 80 ? 'Excellent' : ($productivity >= 65 ? 'Good' : ($productivity >= 45 ? 'Fair' : 'Low'));
$productivityColor  = $productivity >= 80 ? '#4ade80' : ($productivity >= 65 ? '#4A7C59' : ($productivity >= 45 ? '#fbbf24' : '#ef4444'));

// Trend helpers
function trendPct($now, $prev): array {
    if ($prev == 0) return $now > 0 ? ['+∞', 'up'] : ['—', 'neutral'];
    $pct = round((($now - $prev) / $prev) * 100, 1);
    return [($pct >= 0 ? '+' : '') . $pct . '%', $pct >= 0 ? 'up' : 'down'];
}
[$eggTrend,  $eggDir]  = trendPct($todayEggs,  $yesterdayEggs);
[$saleTrend, $saleDir] = trendPct($todaySales, $yesterdaySales);
[$wSaleTrend,$wSaleDir]= trendPct($weeklySales,$lastWeekSales);
[$eggMTrend, $eggMDir] = trendPct($monthEggs,  $lastMonthEggs);

// Mortality this month
$mortalityMonth = $pdo->query("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE MONTH(log_date)=MONTH(CURDATE())")->fetchColumn();
// Open stress alerts
$openAlerts = 0;
try { $openAlerts = $pdo->query("SELECT COUNT(*) FROM stress_alerts WHERE resolved=0")->fetchColumn(); } catch(Exception $e){}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard — Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css">
  <style>
    /* ── KPI cards ─────────────────────────────────────────── */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 18px;
      margin-bottom: 24px;
    }
    .kpi-card {
      background: white;
      border-radius: 14px;
      padding: 22px 24px;
      border: 1px solid var(--border);
      position: relative;
      overflow: hidden;
      transition: box-shadow 0.2s, transform 0.2s;
      cursor: default;
    }
    .kpi-card:hover {
      box-shadow: 0 8px 32px rgba(0,0,0,0.09);
      transform: translateY(-2px);
    }
    .kpi-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      border-radius: 14px 14px 0 0;
    }
    .kpi-card.green::before  { background: #4A7C59; }
    .kpi-card.blue::before   { background: #3b82f6; }
    .kpi-card.orange::before { background: #E87722; }
    .kpi-card.purple::before { background: #a855f7; }
    .kpi-card.red::before    { background: #ef4444; }
    .kpi-label {
      font-size: 12px;
      font-weight: 600;
      color: #9ca3af;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .kpi-value {
      font-size: 30px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      line-height: 1.1;
      margin-bottom: 6px;
    }
    .kpi-sub {
      font-size: 12px;
      color: #888;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .kpi-trend {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      font-size: 12px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 50px;
    }
    .kpi-trend.up   { background: #dcfce7; color: #16a34a; }
    .kpi-trend.down { background: #fee2e2; color: #dc2626; }
    .kpi-trend.neutral { background: #f3f4f6; color: #6b7280; }
    .kpi-icon {
      position: absolute;
      top: 20px; right: 20px;
      width: 42px; height: 42px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
    }
    .kpi-icon.green  { background: #dcfce7; color: #4A7C59; }
    .kpi-icon.blue   { background: #dbeafe; color: #3b82f6; }
    .kpi-icon.orange { background: #ffedd5; color: #E87722; }
    .kpi-icon.purple { background: #f3e8ff; color: #a855f7; }
    .kpi-icon.red    { background: #fee2e2; color: #ef4444; }

    /* ── Quick alerts ──────────────────────────────────────── */
    .quick-stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; margin-bottom: 24px; }
    .quick-stat-card {
      display: flex; align-items: center; gap: 16px;
      background: white; border-radius: 12px; padding: 16px 20px;
      border: 1px solid var(--border);
      text-decoration: none; color: inherit;
      transition: box-shadow 0.2s, transform 0.2s;
    }
    .quick-stat-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); transform: translateY(-1px); }
    .quick-stat-card .stat-icon {
      width: 44px; height: 44px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
    }
    .quick-stat-card.green .stat-icon { background: #dcfce7; color: #4A7C59; }
    .quick-stat-card.orange .stat-icon{ background: #ffedd5; color: #E87722; }
    .quick-stat-card.red .stat-icon   { background: #fee2e2; color: #ef4444; }
    .quick-stat-card .stat-info h3 { font-size: 15px; font-weight: 700; margin-bottom: 2px; }
    .quick-stat-card .stat-info p  { font-size: 12px; color: #888; }

    /* ── Charts ────────────────────────────────────────────── */
    .charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px; }
    .chart-card { background: white; border-radius: 14px; border: 1px solid var(--border); padding: 24px; }
    .chart-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
    .chart-card-title { font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; }
    .chart-card-sub { font-size: 12px; color: #9ca3af; margin-bottom: 16px; }
    .chart-period-btn {
      display: flex; gap: 4px;
    }
    .period-btn {
      padding: 4px 10px; border-radius: 6px; border: 1px solid var(--border);
      font-size: 12px; font-weight: 600; cursor: pointer; background: white; color: #6b7280;
      transition: all 0.15s;
    }
    .period-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

    /* ── Vaccination cards ─────────────────────────────────── */
    .vacc-item {
      display: flex; align-items: center; gap: 12px;
      padding: 11px 0; border-bottom: 1px solid var(--border);
    }
    .vacc-item:last-child { border-bottom: none; }
    .vacc-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .vacc-dot.overdue  { background: #ef4444; box-shadow: 0 0 0 3px #fee2e2; }
    .vacc-dot.today    { background: #E87722; box-shadow: 0 0 0 3px #ffedd5; }
    .vacc-dot.upcoming { background: #4A7C59; box-shadow: 0 0 0 3px #dcfce7; }
    .vacc-info { flex: 1; min-width: 0; }
    .vacc-name { font-size: 13px; font-weight: 700; }
    .vacc-meta { font-size: 11px; color: #888; margin-top: 1px; }
    .vacc-badge {
      font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 50px; flex-shrink: 0;
    }
    .vacc-badge.overdue  { background: #fee2e2; color: #dc2626; }
    .vacc-badge.today    { background: #ffedd5; color: #c2410c; }
    .vacc-badge.upcoming { background: #dcfce7; color: #15803d; }

    /* ── Feed stock ────────────────────────────────────────── */
    .feed-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
    .feed-item {
      background: white; border-radius: 10px; border: 1px solid var(--border); padding: 14px 16px;
      transition: box-shadow 0.2s;
    }
    .feed-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.07); }
    .feed-item.critical { border-color: #fca5a5; background: #fff5f5; }
    .feed-name { font-size: 13px; font-weight: 700; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
    .feed-qty  { font-size: 11px; color: #888; }
    .feed-ok   { color: #4ade80; }.feed-warn { color: #fbbf24; }.feed-crit { color: #ef4444; }

    /* ── Bottom row ────────────────────────────────────────── */
    .bottom-grid { display: grid; grid-template-columns: 1fr 1.6fr; gap: 20px; margin-bottom: 24px; }

    /* ── Empty state ───────────────────────────────────────── */
    .empty-state { text-align: center; padding: 32px 20px; color: #9ca3af; }
    .empty-state i { font-size: 36px; color: #e5e7eb; display: block; margin-bottom: 10px; }
    .empty-state strong { display: block; font-size: 14px; color: #6b7280; margin-bottom: 4px; }
    .empty-state small  { font-size: 12px; }

    /* Responsive */
    @media(max-width:1200px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
    @media(max-width:900px)  { .charts-grid, .bottom-grid { grid-template-columns: 1fr; } }
    @media(max-width:600px)  { .kpi-grid { grid-template-columns: 1fr; } .quick-stats { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">

    <!-- Page header -->
    <div class="page-header">
      <h1>Farm Dashboard</h1>
      <p>Here's what's happening at Coopers Farm right now — <?php echo date('l, F j, Y'); ?></p>
    </div>

    <!-- Alert banners -->
    <?php if($overdueVacc > 0): ?>
    <div class="alert alert-error" style="margin-bottom:12px;">
      <i class="fas fa-skull-crossbones"></i>
      <strong><?php echo $overdueVacc; ?> vaccination(s) overdue!</strong> Flocks are at risk — administer immediately or reschedule.
      <a href="vaccinations.php" style="margin-left:12px;color:#991b1b;font-weight:700;">Go to Vaccinations →</a>
    </div>
    <?php endif; ?>
    <?php if($lowFeedStock > 0): ?>
    <div class="alert alert-warning" style="margin-bottom:12px;">
      <i class="fas fa-exclamation-triangle"></i>
      <strong><?php echo $lowFeedStock; ?> feed type(s)</strong> below reorder level.
      <a href="feed.php" style="margin-left:12px;color:#92400e;font-weight:700;">Restock now →</a>
    </div>
    <?php endif; ?>
    <?php if($openAlerts > 0): ?>
    <div class="alert alert-warning" style="margin-bottom:20px;">
      <i class="fas fa-bell"></i>
      <strong><?php echo $openAlerts; ?> unresolved stress alert(s)</strong> need attention.
      <a href="stress_alerts.php" style="margin-left:12px;color:#92400e;font-weight:700;">View alerts →</a>
    </div>
    <?php endif; ?>

    <!-- ── Quick Action Strip ──────────────────────────────── -->
    <div class="quick-stats">
      <a href="egg_production.php" class="quick-stat-card green">
        <div class="stat-icon"><i class="fas fa-egg"></i></div>
        <div class="stat-info">
          <h3><?php echo number_format($todayEggs); ?> eggs today</h3>
          <p><?php echo number_format($weekEggs); ?> this week · <span style="color:<?php echo $eggDir==='up'?'#16a34a':'#dc2626'; ?>"><?php echo $eggTrend; ?> vs yesterday</span></p>
        </div>
      </a>
      <a href="vaccinations.php" class="quick-stat-card <?php echo $overdueVacc > 0 ? 'red' : 'orange'; ?>">
        <div class="stat-icon"><i class="fas fa-syringe"></i></div>
        <div class="stat-info">
          <h3><?php echo $overdueVacc > 0 ? "$overdueVacc overdue vaccination(s)" : count($upcomingVacc)." upcoming vaccination(s)"; ?></h3>
          <p><?php echo $overdueVacc > 0 ? "Immediate action required" : "In the next 7 days"; ?></p>
        </div>
      </a>
      <a href="orders.php" class="quick-stat-card <?php echo $pendingOrders > 0 ? 'red' : 'green'; ?>">
        <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
        <div class="stat-info">
          <h3><?php echo $pendingOrders; ?> pending orders</h3>
          <p><?php echo $todayOrders; ?> orders today · <?php echo $pendingOrders > 0 ? 'Need processing' : 'All caught up ✓'; ?></p>
        </div>
      </a>
    </div>

    <!-- ── KPI Cards ──────────────────────────────────────── -->
    <div class="kpi-grid">

      <!-- Total Birds -->
      <div class="kpi-card green">
        <div class="kpi-icon green"><i class="fas fa-kiwi-bird"></i></div>
        <div class="kpi-label"><i class="fas fa-kiwi-bird"></i> Total Birds</div>
        <div class="kpi-value"><?php echo number_format($totalBirds); ?></div>
        <div class="kpi-sub">
          <?php echo $activeFlocks; ?> active flocks
          <span class="kpi-trend up"><i class="fas fa-check"></i> Active</span>
        </div>
      </div>

      <!-- Today's Revenue -->
      <div class="kpi-card blue">
        <div class="kpi-icon blue"><i class="fas fa-coins"></i></div>
        <div class="kpi-label"><i class="fas fa-coins"></i> Today's Revenue</div>
        <div class="kpi-value"><?php echo formatCurrency($todaySales); ?></div>
        <div class="kpi-sub">
          Week: <?php echo formatCurrency($weeklySales); ?>
          <span class="kpi-trend <?php echo $saleDir; ?>">
            <i class="fas fa-arrow-<?php echo $saleDir === 'up' ? 'up' : 'down'; ?>"></i>
            <?php echo $saleTrend; ?> vs yesterday
          </span>
        </div>
      </div>

      <!-- Monthly Eggs -->
      <div class="kpi-card orange">
        <div class="kpi-icon orange"><i class="fas fa-egg"></i></div>
        <div class="kpi-label"><i class="fas fa-egg"></i> Month Eggs</div>
        <div class="kpi-value"><?php echo number_format($monthEggs); ?></div>
        <div class="kpi-sub">
          <?php echo round($monthEggs / max(1, date('j'))); ?>/day avg
          <span class="kpi-trend <?php echo $eggMDir; ?>">
            <i class="fas fa-arrow-<?php echo $eggMDir === 'up' ? 'up' : 'down'; ?>"></i>
            <?php echo $eggMTrend; ?> vs last month
          </span>
        </div>
      </div>

      <!-- Layer Productivity -->
      <div class="kpi-card purple">
        <div class="kpi-icon purple"><i class="fas fa-chart-line"></i></div>
        <div class="kpi-label">
          <i class="fas fa-chart-line"></i> Layer Productivity
          <span title="Eggs today ÷ (Layers × 80% expected lay rate) × 100. Industry standard: healthy layers produce 5–6 eggs/week = ~80% lay rate." style="cursor:help; color:#d1d5db;">
            <i class="fas fa-info-circle"></i>
          </span>
        </div>
        <div class="kpi-value" style="color:<?php echo $productivityColor; ?>"><?php echo $productivity; ?>%</div>
        <div class="kpi-sub">
          <?php echo $productivityRating; ?> · <?php echo number_format($activeLayers); ?> layers
          <span class="kpi-trend <?php echo $productivity >= 65 ? 'up' : 'down'; ?>">
            <i class="fas fa-check"></i> Expected: <?php echo number_format(round($expectedEggs)); ?>
          </span>
        </div>
      </div>

    </div>

    <!-- ── Charts ─────────────────────────────────────────── -->
    <div class="charts-grid">

      <!-- Revenue chart -->
      <div class="chart-card">
        <div class="chart-card-header">
          <div>
            <div class="chart-card-title">Sales Revenue</div>
            <div class="chart-card-sub">Daily revenue in GHS — hover for details</div>
          </div>
          <div class="chart-period-btn">
            <button class="period-btn active" onclick="switchPeriod(this,30)">30d</button>
            <button class="period-btn" onclick="switchPeriod(this,14)">14d</button>
            <button class="period-btn" onclick="switchPeriod(this,7)">7d</button>
          </div>
        </div>
        <canvas id="salesChart" height="80"></canvas>
      </div>

      <!-- Egg production chart -->
      <div class="chart-card">
        <div class="chart-card-header">
          <div>
            <div class="chart-card-title">Egg Production</div>
            <div class="chart-card-sub">Last 14 days</div>
          </div>
          <span style="font-size:12px;font-weight:600;background:#dcfce7;color:#15803d;padding:4px 10px;border-radius:50px;">
            <?php echo number_format($todayEggs); ?> today
          </span>
        </div>
        <canvas id="eggChart" height="130"></canvas>
      </div>

    </div>

    <!-- ── Vaccinations + Recent Orders ───────────────────── -->
    <div class="bottom-grid">

      <!-- Upcoming Vaccinations -->
      <div class="card">
        <div class="card-header">
          <h3><i class="fas fa-syringe" style="color:var(--primary);margin-right:8px;"></i>Upcoming Vaccinations</h3>
          <a href="vaccinations.php" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <div style="padding:0 20px 16px;">
          <?php if(empty($upcomingVacc)): ?>
          <div class="empty-state">
            <i class="fas fa-syringe"></i>
            <strong>All flocks up to date 🎉</strong>
            <small>No vaccinations due in the next 7 days</small>
            <a href="vaccinations.php" class="btn btn-sm btn-primary" style="margin-top:14px;display:inline-flex;">
              <i class="fas fa-plus"></i> Schedule Vaccination
            </a>
          </div>
          <?php else: ?>
            <?php foreach($upcomingVacc as $v):
              $days = (strtotime($v['next_due_date']) - strtotime(date('Y-m-d'))) / 86400;
              $cls  = $days < 0 ? 'overdue' : ($days == 0 ? 'today' : 'upcoming');
              $lbl  = $days < 0 ? abs((int)$days).'d overdue' : ($days == 0 ? 'Today' : 'In '.(int)$days.'d');
            ?>
            <div class="vacc-item">
              <div class="vacc-dot <?php echo $cls; ?>"></div>
              <div class="vacc-info">
                <div class="vacc-name"><?php echo htmlspecialchars($v['vaccine_name']); ?></div>
                <div class="vacc-meta">
                  <i class="fas fa-kiwi-bird" style="font-size:10px;"></i>
                  <?php echo htmlspecialchars($v['flock_name']); ?> ·
                  <?php echo ucfirst($v['bird_type']); ?> ·
                  <?php echo date('M d', strtotime($v['next_due_date'])); ?>
                </div>
              </div>
              <span class="vacc-badge <?php echo $cls; ?>"><?php echo $lbl; ?></span>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent Orders -->
      <div class="card">
        <div class="card-header">
          <h3>Recent Orders</h3>
          <div style="display:flex;gap:8px;align-items:center;">
            <?php if($pendingOrders > 0): ?>
            <span style="background:#fee2e2;color:#dc2626;font-size:11px;font-weight:700;padding:3px 8px;border-radius:50px;">
              <?php echo $pendingOrders; ?> pending
            </span>
            <?php endif; ?>
            <a href="orders.php" class="btn btn-sm btn-secondary">View All</a>
          </div>
        </div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Order #</th>
              <th>Customer</th>
              <th>Type</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($recentOrders)): ?>
            <tr><td colspan="6" style="text-align:center;color:#888;padding:24px;">No orders yet</td></tr>
            <?php else: ?>
            <?php foreach($recentOrders as $o): ?>
            <tr>
              <td><strong style="font-size:12px;"><?php echo $o['order_number']; ?></strong></td>
              <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
              <td>
                <span style="font-size:11px;"><?php echo ($o['otype']==='walkin') ? '🏪' : '🌐'; ?></span>
              </td>
              <td><strong><?php echo formatCurrency($o['total_amount']); ?></strong></td>
              <td><span class="status-badge badge-<?php echo $o['order_status']; ?>"><?php echo ucwords(str_replace('_',' ',$o['order_status'])); ?></span></td>
              <td style="font-size:12px;color:#888;"><?php echo date('M d', strtotime($o['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>

    <!-- ── Feed Stock ─────────────────────────────────────── -->
    <div class="card" style="margin-bottom:24px;">
      <div class="card-header">
        <h3><i class="fas fa-seedling" style="color:var(--primary);margin-right:8px;"></i>Feed Stock Levels</h3>
        <a href="feed.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Manage Feed</a>
      </div>
      <?php if(empty($feedStock)): ?>
      <div class="empty-state">
        <i class="fas fa-boxes"></i>
        <strong>No feed stock added yet</strong>
        <a href="feed.php" class="btn btn-sm btn-primary" style="margin-top:12px;display:inline-flex;">Add Feed Stock</a>
      </div>
      <?php else: ?>
      <div class="card-body">
        <div class="feed-grid">
          <?php foreach($feedStock as $f):
            $pct      = min(100, ($f['quantity_kg'] / max($f['reorder_level'] * 2, 1)) * 100);
            $isCrit   = $f['quantity_kg'] < $f['reorder_level'];
            $isWarn   = !$isCrit && $pct < 60;
            $fClass   = $isCrit ? 'crit' : ($isWarn ? 'warn' : 'ok');
            $barColor = $isCrit ? '#ef4444' : ($isWarn ? '#fbbf24' : '#4ade80');
          ?>
          <div class="feed-item <?php echo $isCrit ? 'critical' : ''; ?>">
            <div class="feed-name">
              <span><?php echo htmlspecialchars($f['feed_type']); ?></span>
              <?php if($isCrit): ?><i class="fas fa-exclamation-triangle" style="color:#ef4444;font-size:12px;" title="Below reorder level"></i><?php endif; ?>
            </div>
            <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:12px;">
              <span class="feed-<?php echo $fClass; ?>" style="font-weight:700;"><?php echo number_format($f['quantity_kg'],1); ?>kg</span>
              <span style="color:#9ca3af;">min: <?php echo number_format($f['reorder_level'],0); ?>kg</span>
            </div>
            <div style="height:5px;border-radius:5px;background:#e5e7eb;overflow:hidden;">
              <div style="height:100%;border-radius:5px;background:<?php echo $barColor; ?>;width:<?php echo $pct; ?>%;transition:width 0.4s;"></div>
            </div>
            <?php if($isCrit): ?>
            <div style="margin-top:5px;font-size:10px;color:#ef4444;font-weight:700;">⚠ Restock needed</div>
            <?php endif; ?>
            <div style="margin-top:6px;font-size:11px;color:#9ca3af;"><?php echo formatCurrency($f['unit_cost']); ?>/kg</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Bottom metrics strip ───────────────────────────── -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
      <div class="card" style="padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div>
            <div style="font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:6px;">Mortality (This Month)</div>
            <div style="font-size:28px;font-weight:700;font-family:'Playfair Display',serif;color:<?php echo $mortalityMonth>0?'#ef4444':'#4ade80'; ?>"><?php echo number_format($mortalityMonth); ?></div>
            <div style="font-size:12px;color:#888;margin-top:4px;">Total deaths recorded</div>
          </div>
          <div style="width:40px;height:40px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;color:#ef4444;">
            <i class="fas fa-skull"></i>
          </div>
        </div>
        <a href="mortality.php" style="font-size:12px;color:var(--primary);font-weight:600;margin-top:10px;display:block;">View mortality log →</a>
      </div>
      <div class="card" style="padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div>
            <div style="font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:6px;">Monthly Revenue</div>
            <div style="font-size:28px;font-weight:700;font-family:'Playfair Display',serif;color:var(--primary);"><?php echo formatCurrency($monthSales); ?></div>
            <div style="font-size:12px;color:#888;margin-top:4px;">
              <span class="kpi-trend <?php echo $wSaleDir; ?>" style="font-size:11px;">
                <i class="fas fa-arrow-<?php echo $wSaleDir==='up'?'up':'down'; ?>"></i> <?php echo $wSaleTrend; ?> week-on-week
              </span>
            </div>
          </div>
          <div style="width:40px;height:40px;border-radius:10px;background:#dcfce7;display:flex;align-items:center;justify-content:center;color:#4A7C59;">
            <i class="fas fa-chart-bar"></i>
          </div>
        </div>
        <a href="reports.php" style="font-size:12px;color:var(--primary);font-weight:600;margin-top:10px;display:block;">Full report →</a>
      </div>
      <div class="card" style="padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div>
            <div style="font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:6px;">Quick Actions</div>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px;">
              <a href="walkin_order.php" class="btn btn-sm btn-primary" style="justify-content:flex-start;">
                <i class="fas fa-store"></i> New Walk-in Sale
              </a>
              <a href="egg_production.php" class="btn btn-sm btn-secondary" style="justify-content:flex-start;">
                <i class="fas fa-egg"></i> Log Egg Production
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /dashboard-content -->
</div><!-- /main-content -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="../public/js/admin.js"></script>
<script>
const allSalesData = <?php echo json_encode($salesChart); ?>;
const eggData      = <?php echo json_encode($eggChart); ?>;
let salesChartObj  = null;

function formatDate(str, opts) {
  return new Date(str).toLocaleDateString('en-GB', opts || {month:'short',day:'numeric'});
}

function buildSalesChart(days) {
  const cutoff  = new Date();
  cutoff.setDate(cutoff.getDate() - days);
  const filtered = allSalesData.filter(d => new Date(d.date) >= cutoff);

  const labels = filtered.map(d => formatDate(d.date));
  const values = filtered.map(d => parseFloat(d.amount));

  if (salesChartObj) { salesChartObj.destroy(); }

  const ctx = document.getElementById('salesChart');
  if (!ctx) return;
  salesChartObj = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Revenue (₵)',
        data: values,
        borderColor: '#4A7C59',
        backgroundColor: 'rgba(74,124,89,0.08)',
        fill: true,
        tension: 0.4,
        pointRadius: 3,
        pointHoverRadius: 6,
        pointBackgroundColor: '#4A7C59',
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1f2937',
          padding: 10,
          callbacks: {
            label: ctx => ' ₵' + parseFloat(ctx.parsed.y).toFixed(2),
            title: t  => t[0].label
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: '#f3f4f6' },
          ticks: { callback: v => '₵' + (v >= 1000 ? (v/1000).toFixed(1)+'k' : v) }
        },
        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }
      }
    }
  });
}

function switchPeriod(btn, days) {
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  buildSalesChart(days);
}

// Build egg chart
const eCtx = document.getElementById('eggChart');
if (eCtx && eggData.length > 0) {
  new Chart(eCtx, {
    type: 'bar',
    data: {
      labels: eggData.map(d => formatDate(d.date, {month:'short',day:'numeric'})),
      datasets: [{
        data: eggData.map(d => parseInt(d.qty)),
        backgroundColor: eggData.map((d,i) => i === eggData.length-1 ? '#4A7C59' : '#7EAD6E'),
        borderRadius: 6,
        borderSkipped: false
      }]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1f2937',
          padding: 10,
          callbacks: { label: c => ' ' + parseInt(c.parsed.y).toLocaleString() + ' eggs' }
        }
      },
      scales: {
        y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
        x: { grid: { display: false } }
      }
    }
  });
}

// Initialise sales chart on load
buildSalesChart(30);
</script>
</body>
</html>
