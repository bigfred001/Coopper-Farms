<?php
require_once '../config/config.php';
requireAdmin();

// ── Targets (adjust to your farm) ─────────────────────────────
define('LAY_TARGET', 0.80);       // share of live layers expected to lay each day
define('MORT_TARGET_30D', 1.0);   // acceptable deaths as % of flock over 30 days
define('FEED_ALERT_DAYS', 7);     // warn when feed stock lasts fewer days than this

$q = fn(string $sql) => $pdo->query($sql)->fetchColumn();

// ── Flocks ────────────────────────────────────────────────────
$flocksActive = $pdo->query("SELECT f.*, DATEDIFF(CURDATE(), f.arrival_date) AS age_days
    FROM flocks f WHERE f.status='active' ORDER BY f.flock_name")->fetchAll();
$liveBirds    = (int)array_sum(array_column($flocksActive, 'quantity'));
$layerBirds   = (int)array_sum(array_map(fn($f) => $f['bird_type']==='layer' ? $f['quantity'] : 0, $flocksActive));
$broilerBirds = (int)array_sum(array_map(fn($f) => $f['bird_type']==='broiler' ? $f['quantity'] : 0, $flocksActive));

// ── Mortality ─────────────────────────────────────────────────
$mortToday = (int)$q("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE log_date=CURDATE()");
$mortYest  = (int)$q("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE log_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY)");
$mort30    = (int)$q("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE log_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
$mortRate30 = ($liveBirds + $mort30) > 0 ? round(($mort30 / ($liveBirds + $mort30)) * 100, 2) : 0;
$mortExpectedToday = round($liveBirds * (MORT_TARGET_30D / 100) / 30, 1);

// ── Health ────────────────────────────────────────────────────
$openCases = (int)$q("SELECT COUNT(*) FROM health_records WHERE status IN ('active','monitoring')");
$sickBirds = (int)$q("SELECT COALESCE(SUM(affected_count),0) FROM health_records WHERE status IN ('active','monitoring')");

// ── Eggs ──────────────────────────────────────────────────────
$eggsToday    = (int)$q("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE production_date=CURDATE()");
$eggsYest     = (int)$q("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE production_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY)");
$eggsExpected = (int)round($layerBirds * LAY_TARGET);
$eggsVar      = $eggsExpected > 0 ? round((($eggsToday - $eggsExpected) / $eggsExpected) * 100, 1) : null;
$layRateToday = $layerBirds > 0 ? round(($eggsToday / $layerBirds) * 100, 1) : null;

// ── Feed ──────────────────────────────────────────────────────
$feedStock   = (float)$q("SELECT COALESCE(SUM(quantity_kg),0) FROM feed_stock");
$feedReorder = (int)$q("SELECT COUNT(*) FROM feed_stock WHERE quantity_kg <= reorder_level");
$feed14      = (float)$q("SELECT COALESCE(SUM(quantity_kg),0) FROM feed_logs WHERE log_date>=DATE_SUB(CURDATE(),INTERVAL 14 DAY)");
$feedAvgDay  = round($feed14 / 14, 1);
$feedDays    = $feedAvgDay > 0 ? round($feedStock / $feedAvgDay, 1) : null;
$feedToday   = (float)$q("SELECT COALESCE(SUM(quantity_kg),0) FROM feed_logs WHERE log_date=CURDATE()");
$feedVar     = $feedAvgDay > 0 ? round((($feedToday - $feedAvgDay) / $feedAvgDay) * 100, 1) : null;
$feedCostMonth = (float)$q("SELECT COALESCE(SUM(cost),0) FROM feed_logs WHERE MONTH(log_date)=MONTH(CURDATE()) AND YEAR(log_date)=YEAR(CURDATE())");

// ── Revenue (delivered orders: walk-in and online) ───────────
$revToday = (float)$q("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE order_status='delivered' AND DATE(created_at)=CURDATE()");
$rev7     = (float)$q("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE order_status='delivered' AND DATE(created_at) BETWEEN DATE_SUB(CURDATE(),INTERVAL 7 DAY) AND DATE_SUB(CURDATE(),INTERVAL 1 DAY)");
$revAvgDay = round($rev7 / 7, 2);
$revVar   = $revAvgDay > 0 ? round((($revToday - $revAvgDay) / $revAvgDay) * 100, 1) : null;
$revMonth = (float)$q("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE order_status='delivered' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
$grossMonth = $revMonth - $feedCostMonth;
$marginPct  = $revMonth > 0 ? round(($grossMonth / $revMonth) * 100, 1) : null;
$eggsMonth  = (int)$q("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE MONTH(production_date)=MONTH(CURDATE()) AND YEAR(production_date)=YEAR(CURDATE())");
$feedPerEgg = $eggsMonth > 0 ? round($feedCostMonth / $eggsMonth, 2) : null;

// ── Orders & stock ────────────────────────────────────────────
$pendingOrders = (int)$q("SELECT COUNT(*) FROM orders WHERE order_status='pending'");
$ordersToday   = (int)$q("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()");
$stockOut      = (int)$q("SELECT COUNT(*) FROM products WHERE is_active=1 AND stock_quantity<=0");
$stockLow      = (int)$q("SELECT COUNT(*) FROM products WHERE is_active=1 AND stock_quantity>0 AND stock_quantity<=10");

// ── Vaccinations (open doses use their due date) ─────────────
$vOverdue = (int)$q("SELECT COUNT(*) FROM vaccinations WHERE status IN ('scheduled','overdue') AND COALESCE(next_due_date,date_given) < CURDATE()");
$vDue7    = (int)$q("SELECT COUNT(*) FROM vaccinations WHERE status IN ('scheduled','overdue') AND COALESCE(next_due_date,date_given) BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)");
$vGivenToday = (int)$q("SELECT COUNT(*) FROM vaccinations WHERE status='completed' AND date_given=CURDATE()");

// ── Per-flock performance ─────────────────────────────────────
$flockRows = [];
$stEggs = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) AS e7, COUNT(DISTINCT production_date) AS d7
    FROM egg_production WHERE flock_id=? AND production_date>=DATE_SUB(CURDATE(),INTERVAL 7 DAY)");
$stMort = $pdo->prepare("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE flock_id=? AND log_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
$stCase = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE flock_id=? AND status IN ('active','monitoring')");
$stVac  = $pdo->prepare("SELECT COUNT(*) FROM vaccinations WHERE flock_id=? AND status IN ('scheduled','overdue') AND COALESCE(next_due_date,date_given)<CURDATE()");
foreach ($flocksActive as $f) {
    $fid = (int)$f['id']; $qty = (int)$f['quantity'];
    $stEggs->execute([$fid]); $e = $stEggs->fetch();
    $stMort->execute([$fid]); $m30 = (int)$stMort->fetchColumn();
    $stCase->execute([$fid]); $cases = (int)$stCase->fetchColumn();
    $stVac->execute([$fid]);  $vo = (int)$stVac->fetchColumn();
    $rate = ($qty + $m30) > 0 ? round(($m30 / ($qty + $m30)) * 100, 2) : 0;
    $lay  = ($f['bird_type']==='layer' && $qty>0 && (int)$e['d7']>0)
            ? min(100, round(((int)$e['e7'] / ($qty * (int)$e['d7'])) * 100, 1)) : null;
    $level = ($rate > 5 || $cases > 0) ? 'critical' : (($rate > 2 || $vo > 0) ? 'watch' : 'healthy');
    $flockRows[] = ['id'=>$fid, 'name'=>$f['flock_name'], 'type'=>$f['bird_type'], 'qty'=>$qty,
                    'age'=>(int)$f['age_days'], 'lay'=>$lay, 'rate'=>$rate, 'cases'=>$cases,
                    'vOverdue'=>$vo, 'level'=>$level];
}

// ── Alerts & actions (most urgent first) ─────────────────────
$alerts = [];
if ($vOverdue > 0)  $alerts[] = ['red',   "$vOverdue vaccination dose(s) overdue", 'vaccinations.php?status=overdue'];
if ($vDue7 > 0)     $alerts[] = ['amber', "$vDue7 vaccination(s) due in the next 7 days", 'vaccinations.php?status=due7'];
foreach ($flockRows as $fr) {
    if ($fr['level']==='critical' && $fr['rate']>5)
        $alerts[] = ['red', "{$fr['name']}: {$fr['rate']}% mortality in 30 days", "mortality.php?flock_id={$fr['id']}"];
    elseif ($fr['level']==='critical')
        $alerts[] = ['red', "{$fr['name']}: open health case(s)", "health.php?flock_id={$fr['id']}"];
    elseif ($fr['level']==='watch')
        $alerts[] = ['amber', "{$fr['name']}: needs attention", "flocks.php"];
}
if ($openCases > 0) $alerts[] = [$sickBirds > 0 ? 'amber' : 'amber', "$openCases open health case(s), $sickBirds bird(s) affected", 'health.php?status=active'];
if ($feedDays !== null && $feedDays < FEED_ALERT_DAYS)
    $alerts[] = ['red', "Feed lasts about {$feedDays} day(s)", 'feed.php'];
elseif ($feedReorder > 0)
    $alerts[] = ['amber', "$feedReorder feed type(s) at or below reorder level", 'feed.php'];
if ($stockOut > 0) $alerts[] = ['red',   "$stockOut product(s) out of stock", 'products.php?stock=out'];
elseif ($stockLow > 0) $alerts[] = ['amber', "$stockLow product(s) running low", 'products.php?stock=low'];
if ($pendingOrders > 0) $alerts[] = ['amber', "$pendingOrders order(s) waiting to be accepted", 'orders.php?status=pending'];
if ($eggsVar !== null && $eggsVar <= -10) $alerts[] = ['amber', "Egg output " . abs($eggsVar) . "% below expected today", 'egg_production.php'];
usort($alerts, fn($a, $b) => ($a[0]==='red' ? 0 : 1) <=> ($b[0]==='red' ? 0 : 1));

// ── Charts ────────────────────────────────────────────────────
$salesChart = $pdo->query("SELECT DATE(created_at) AS date, SUM(total_amount) AS amount
    FROM orders WHERE order_status='delivered' AND created_at >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)
    GROUP BY DATE(created_at) ORDER BY date")->fetchAll();
$eggChart = $pdo->query("SELECT DATE(production_date) AS date, SUM(quantity) AS qty
    FROM egg_production WHERE production_date >= DATE_SUB(CURDATE(),INTERVAL 14 DAY)
    GROUP BY DATE(production_date) ORDER BY date")->fetchAll();

// ── Upcoming vaccinations (open doses: overdue or due within 7 days) ─
$upcomingVacc = $pdo->query("SELECT v.id, v.vaccine_name, f.flock_name, f.bird_type,
        COALESCE(v.next_due_date, v.date_given) AS due_date,
        DATEDIFF(COALESCE(v.next_due_date, v.date_given), CURDATE()) AS days_until
    FROM vaccinations v JOIN flocks f ON f.id=v.flock_id
    WHERE v.status IN ('scheduled','overdue')
      AND COALESCE(v.next_due_date, v.date_given) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY COALESCE(v.next_due_date, v.date_given) ASC LIMIT 8")->fetchAll();

// ── Recent orders ─────────────────────────────────────────────
$recentOrders = $pdo->query("SELECT o.*, COALESCE(o.order_type,'online') AS otype FROM orders o
    ORDER BY o.created_at DESC LIMIT 6")->fetchAll();

$pageData = ['salesChart'=>$salesChart, 'eggChart'=>$eggChart];

function varClass(?float $v, bool $higherIsBad = false): string {
    if ($v === null) return 'neutral';
    if (abs($v) < 5) return 'ok';
    $bad = $higherIsBad ? $v > 0 : $v < 0;
    return $bad ? 'bad' : 'good';
}
function fmtVar(?float $v): string { return $v === null ? '—' : (($v > 0 ? '+' : '') . $v . '%'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Dashboard — Coopers Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../public/css/admin.css?v=8">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
<?php include 'includes/topbar.php'; ?>
<div class="dashboard-content dash">

<!-- Header + quick actions -->
<div class="dash-head">
  <div>
    <h1>Farm Dashboard</h1>
    <p><?php echo date('l, F j, Y'); ?> · how the farm is doing and what needs you</p>
  </div>
  <div class="dash-quick">
    <a href="egg_production.php" class="dq-btn"><i class="fas fa-egg"></i> Eggs</a>
    <a href="mortality.php" class="dq-btn"><i class="fas fa-skull"></i> Mortality</a>
    <a href="feed.php" class="dq-btn"><i class="fas fa-seedling"></i> Feed</a>
    <a href="vaccinations.php" class="dq-btn"><i class="fas fa-syringe"></i> Vaccinate</a>
    <a href="walkin_order.php" class="dq-btn dq-primary"><i class="fas fa-store"></i> Walk-in sale</a>
  </div>
</div>

<!-- Farm health -->
<section class="dash-health">
  <div class="dh-tile">
    <span class="dh-lbl">Live birds</span>
    <strong><?php echo number_format($liveBirds); ?></strong>
    <small><?php echo number_format($layerBirds); ?> layers · <?php echo number_format($broilerBirds); ?> broilers</small>
  </div>
  <div class="dh-tile <?php echo $mortToday > $mortExpectedToday ? 'warn' : ''; ?>">
    <span class="dh-lbl">Deaths today</span>
    <strong><?php echo $mortToday; ?></strong>
    <small><?php
      $dy = $mortToday - $mortYest;
      echo $mortYest === 0 && $mortToday === 0 ? 'none logged' : ($dy === 0 ? 'same as yesterday' : (($dy > 0 ? '▲ ' : '▼ ') . abs($dy) . ' vs yesterday'));
    ?></small>
  </div>
  <div class="dh-tile <?php echo $mortRate30 > MORT_TARGET_30D ? 'warn' : 'good'; ?>">
    <span class="dh-lbl">Mortality · 30 days</span>
    <strong><?php echo $mortRate30; ?>%</strong>
    <small>target &lt; <?php echo MORT_TARGET_30D; ?>%</small>
  </div>
  <div class="dh-tile <?php echo $sickBirds > 0 ? 'warn' : ''; ?>">
    <span class="dh-lbl">Sick birds</span>
    <strong><?php echo number_format($sickBirds); ?></strong>
    <small><?php echo $openCases; ?> open case<?php echo $openCases===1?'':'s'; ?></small>
  </div>
  <div class="dh-tile <?php echo ($feedDays!==null && $feedDays < FEED_ALERT_DAYS) ? 'bad' : ''; ?>">
    <span class="dh-lbl">Feed stock</span>
    <strong><?php echo number_format($feedStock); ?> kg</strong>
    <small><?php echo $feedDays !== null ? "≈ {$feedDays} days left" : 'no usage recorded yet'; ?></small>
  </div>
</section>

<!-- Upcoming vaccinations -->
<section class="card dash-card">
  <div class="dc-head">
    <h3>Upcoming vaccinations</h3>
    <div>
      <span class="dc-pill <?php echo count($upcomingVacc) > 0 ? 'red' : 'green'; ?>"><?php echo count($upcomingVacc); ?> in the next 7 days</span>
      <a href="vaccinations.php" class="dc-link" style="margin-left:10px;">Open vaccinations →</a>
    </div>
  </div>
  <?php if(empty($upcomingVacc)): ?>
    <div class="dc-empty ok"><i class="fas fa-check-circle"></i> No vaccinations due in the next 7 days.</div>
  <?php else: ?>
  <table class="dash-table">
    <thead><tr><th>Due</th><th>Flock</th><th>Vaccine</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach($upcomingVacc as $uv):
      $d = (int)$uv['days_until'];
      if ($d < 0)      { $when = 'Overdue by '.abs($d).' day'.(abs($d)===1?'':'s'); $cls = 'red'; }
      elseif ($d === 0){ $when = 'Due today'; $cls = 'red'; }
      else             { $when = 'In '.$d.' day'.($d===1?'':'s'); $cls = 'amber'; }
    ?>
      <tr>
        <td><strong><?php echo date('M d', strtotime($uv['due_date'])); ?></strong></td>
        <td><?php echo htmlspecialchars($uv['flock_name']); ?><br><small><?php echo ucfirst($uv['bird_type']); ?></small></td>
        <td><?php echo htmlspecialchars($uv['vaccine_name']); ?></td>
        <td><span class="status-dot <?php echo $cls; ?>"></span><?php echo $when; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<!-- Today vs expected -->
<section class="card dash-card">
  <div class="dc-head"><h3>Today vs expected</h3><span class="dc-hint">Expected is based on your targets and the last 7–14 days</span></div>
  <table class="dash-table">
    <thead><tr><th>Metric</th><th>Actual</th><th>Expected</th><th>Variance</th></tr></thead>
    <tbody>
      <tr>
        <td>Eggs (layers at <?php echo (int)(LAY_TARGET*100); ?>%)</td>
        <td><strong><?php echo number_format($eggsToday); ?></strong></td>
        <td><?php echo number_format($eggsExpected); ?></td>
        <td><span class="var <?php echo varClass($eggsVar); ?>"><?php echo fmtVar($eggsVar); ?></span></td>
      </tr>
      <tr>
        <td>Deaths</td>
        <td><strong><?php echo $mortToday; ?></strong></td>
        <td><?php echo $mortExpectedToday; ?></td>
        <td><span class="var <?php echo varClass($mortExpectedToday > 0 ? round((($mortToday-$mortExpectedToday)/$mortExpectedToday)*100,1) : null, true); ?>"><?php echo $mortExpectedToday > 0 ? fmtVar(round((($mortToday-$mortExpectedToday)/$mortExpectedToday)*100,1)) : '—'; ?></span></td>
      </tr>
      <tr>
        <td>Feed used (kg)</td>
        <td><strong><?php echo number_format($feedToday, 1); ?></strong></td>
        <td><?php echo number_format($feedAvgDay, 1); ?></td>
        <td><span class="var <?php echo varClass($feedVar, true); ?>"><?php echo fmtVar($feedVar); ?></span></td>
      </tr>
      <tr>
        <td>Revenue</td>
        <td><strong><?php echo formatCurrency($revToday); ?></strong></td>
        <td><?php echo formatCurrency($revAvgDay); ?></td>
        <td><span class="var <?php echo varClass($revVar); ?>"><?php echo fmtVar($revVar); ?></span></td>
      </tr>
    </tbody>
  </table>
</section>

<!-- This month's money -->
<section class="fin-strip">
  <div class="fin-tile"><span>Revenue · this month</span><strong><?php echo formatCurrency($revMonth); ?></strong></div>
  <div class="fin-tile"><span>Feed cost · this month</span><strong><?php echo formatCurrency($feedCostMonth); ?></strong></div>
  <div class="fin-tile <?php echo $grossMonth < 0 ? 'bad' : 'good'; ?>"><span>Gross margin</span><strong><?php echo formatCurrency($grossMonth); ?></strong><small><?php echo $marginPct !== null ? $marginPct.'% of revenue' : 'no revenue yet'; ?></small></div>
  <div class="fin-tile"><span>Feed cost per egg</span><strong><?php echo $feedPerEgg !== null ? formatCurrency($feedPerEgg) : '—'; ?></strong><small>other costs not tracked yet</small></div>
</section>

<!-- Charts -->
<section class="dash-grid-2">
  <div class="card dash-card">
    <div class="dc-head">
      <h3>Sales revenue</h3>
      <div class="period-switch">
        <button class="period-btn active" data-days="30">30d</button>
        <button class="period-btn" data-days="14">14d</button>
        <button class="period-btn" data-days="7">7d</button>
      </div>
    </div>
    <div class="dc-hint">Delivered orders, walk-in and online · last 30 days</div>
    <canvas id="salesChart" height="110"></canvas>
  </div>
  <div class="card dash-card">
    <div class="dc-head"><h3>Egg production</h3><span class="dc-pill"><?php echo number_format($eggsToday); ?> today</span></div>
    <div class="dc-hint">Eggs collected per day · last 14 days</div>
    <canvas id="eggChart" height="110"></canvas>
  </div>
</section>

<!-- Flocks + alerts -->
<section class="dash-grid-2">
  <div class="card dash-card">
    <div class="dc-head"><h3>Flock performance</h3><a href="flocks.php" class="dc-link">All flocks →</a></div>
    <?php if(empty($flockRows)): ?>
      <div class="dc-empty">No active flocks yet. <a href="flocks.php">Add a flock</a></div>
    <?php else: ?>
    <table class="dash-table">
      <thead><tr><th>Flock</th><th>Age</th><th>Birds</th><th>Lay 7d</th><th>Deaths 30d</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($flockRows as $fr):
        $dot = $fr['level']==='critical' ? 'red' : ($fr['level']==='watch' ? 'amber' : 'green');
        $label = ['critical'=>'Critical','watch'=>'Watch','healthy'=>'Healthy'][$fr['level']];
        $weeks = $fr['age'] >= 14 ? round($fr['age']/7).' wk' : $fr['age'].' d';
      ?>
        <tr>
          <td><a href="flocks.php" class="flock-name"><?php echo htmlspecialchars($fr['name']); ?></a><br><small><?php echo ucfirst($fr['type']); ?></small></td>
          <td><?php echo $weeks; ?></td>
          <td><?php echo number_format($fr['qty']); ?></td>
          <td><?php echo $fr['lay'] !== null ? $fr['lay'].'%' : '—'; ?></td>
          <td><?php echo $fr['rate']; ?>%</td>
          <td><span class="status-dot <?php echo $dot; ?>"></span> <?php echo $label; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="card dash-card">
    <div class="dc-head">
      <h3>Alerts &amp; actions</h3>
      <span class="dc-pill <?php echo empty($alerts) ? 'green' : 'red'; ?>"><?php echo empty($alerts) ? 'All clear' : count($alerts).' to review'; ?></span>
    </div>
    <?php if(empty($alerts)): ?>
      <div class="dc-empty ok"><i class="fas fa-check-circle"></i> Nothing needs attention right now.</div>
    <?php else: ?>
      <ul class="alert-list">
      <?php foreach($alerts as $al): ?>
        <li class="alert-row <?php echo $al[0]; ?>"><a href="<?php echo htmlspecialchars($al[2]); ?>">
          <span class="alert-dot"></span><span class="alert-text"><?php echo htmlspecialchars($al[1]); ?></span><i class="fas fa-chevron-right"></i>
        </a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- Activity + recent orders -->
<section class="dash-grid-2">
  <div class="card dash-card">
    <div class="dc-head"><h3>Recent orders</h3><a href="orders.php" class="dc-link">View all →</a></div>
    <table class="dash-table">
      <thead><tr><th>Order</th><th>Customer</th><th>Amount</th><th>Status</th></tr></thead>
      <tbody>
      <?php if(empty($recentOrders)): ?>
        <tr><td colspan="4" class="dc-empty">No orders yet.</td></tr>
      <?php else: foreach($recentOrders as $o): ?>
        <tr>
          <td><span class="mono"><?php echo htmlspecialchars($o['order_number']); ?></span><br><small><?php echo date('M d', strtotime($o['created_at'])); ?></small></td>
          <td><?php echo htmlspecialchars(($o['customer_name'] ?? '') !== '' ? $o['customer_name'] : 'Walk-in guest'); ?></td>
          <td><strong><?php echo formatCurrency($o['total_amount']); ?></strong></td>
          <td><span class="order-status s-<?php echo $o['order_status']; ?>"><?php echo ucfirst(str_replace('_',' ',$o['order_status'])); ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card dash-card">
    <div class="dc-head"><h3>Today's activity</h3></div>
    <ul class="activity-list">
      <li><i class="fas fa-egg"></i><span>Eggs collected</span><strong><?php echo number_format($eggsToday); ?></strong></li>
      <li><i class="fas fa-seedling"></i><span>Feed logged</span><strong><?php echo number_format($feedToday, 1); ?> kg</strong></li>
      <li><i class="fas fa-skull"></i><span>Deaths recorded</span><strong><?php echo $mortToday; ?></strong></li>
      <li><i class="fas fa-syringe"></i><span>Vaccinations given</span><strong><?php echo $vGivenToday; ?></strong></li>
      <li><i class="fas fa-shopping-cart"></i><span>Orders placed</span><strong><?php echo $ordersToday; ?></strong></li>
      <li><i class="fas fa-coins"></i><span>Revenue (delivered)</span><strong><?php echo formatCurrency($revToday); ?></strong></li>
    </ul>
  </div>
</section>

</div>
</div>

<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
