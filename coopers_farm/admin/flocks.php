<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

// ── POST handlers ─────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_flock'])) {
        $pdo->prepare("INSERT INTO flocks (flock_name,bird_type,quantity,arrival_date,source,housing_unit,notes,created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([sanitize($_POST['flock_name']),$_POST['bird_type'],(int)$_POST['quantity'],
                $_POST['arrival_date'],sanitize($_POST['source']??''),sanitize($_POST['housing_unit']??''),sanitize($_POST['notes']??''),$_SESSION['user_id']]);
        $nid = (int)$pdo->lastInsertId();
        auditLog($pdo,'add_flock','flock',$nid,"Added '".$_POST['flock_name']."' — ".$_POST['bird_type']." — qty:".(int)$_POST['quantity']);
        $msg = 'success:Flock added!';
    }
    if (isset($_POST['edit_flock'])) {
        $fid = (int)$_POST['flock_id'];
        $pdo->prepare("UPDATE flocks SET flock_name=?,bird_type=?,quantity=?,arrival_date=?,source=?,housing_unit=?,notes=? WHERE id=?")
            ->execute([sanitize($_POST['flock_name']),$_POST['bird_type'],(int)$_POST['quantity'],
                $_POST['arrival_date'],sanitize($_POST['source']??''),sanitize($_POST['housing_unit']??''),sanitize($_POST['notes']??''),$fid]);
        auditLog($pdo,'edit_flock','flock',$fid,'Edited: '.sanitize($_POST['flock_name']));
        $msg = 'success:Flock updated!';
    }
    if (isset($_POST['update_status'])) {
        $fid = (int)$_POST['flock_id'];
        $pdo->prepare("UPDATE flocks SET status=? WHERE id=?")->execute([$_POST['status'],$fid]);
        auditLog($pdo,'flock_status','flock',$fid,"Status → ".$_POST['status']);
        $msg = 'success:Status updated!';
    }
    header('Location: flocks.php?msg='.urlencode($msg)); exit;
}

// ── Filters ───────────────────────────────────────────────────
$urlMsg     = $_GET['msg']    ?? '';
$typeFilter = $_GET['type']   ?? '';
$statFilter = $_GET['status'] ?? '';
$search     = $_GET['search'] ?? '';

$where  = "WHERE 1=1";
$params = [];
if ($typeFilter) { $where .= " AND f.bird_type=?";     $params[] = $typeFilter; }
if ($statFilter) { $where .= " AND f.status=?";         $params[] = $statFilter; }
if ($search)     { $where .= " AND f.flock_name LIKE ?"; $params[] = "%$search%"; }

// ── Main flock query ──────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT f.*,
        DATEDIFF(CURDATE(),f.arrival_date) AS age_days,
        (SELECT COUNT(*) FROM vaccinations v WHERE v.flock_id=f.id) AS vacc_count,
        (SELECT COUNT(*) FROM vaccinations v WHERE v.flock_id=f.id AND v.status='overdue') AS vacc_overdue,
        (SELECT COALESCE(SUM(ml.count),0) FROM mortality_logs ml WHERE ml.flock_id=f.id) AS total_mortality,
        (SELECT COALESCE(SUM(ep.quantity),0) FROM egg_production ep WHERE ep.flock_id=f.id) AS total_eggs,
        (SELECT COALESCE(SUM(ep.quantity),0) FROM egg_production ep WHERE ep.flock_id=f.id
            AND ep.production_date=CURDATE()) AS today_eggs,
        (SELECT COALESCE(SUM(ep.quantity),0) FROM egg_production ep WHERE ep.flock_id=f.id
            AND ep.production_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY)) AS yesterday_eggs,
        (SELECT COALESCE(SUM(ep.quantity),0) FROM egg_production ep WHERE ep.flock_id=f.id
            AND ep.production_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)) AS week_eggs,
        (SELECT COALESCE(SUM(ep.quantity),0) FROM egg_production ep WHERE ep.flock_id=f.id
            AND MONTH(ep.production_date)=MONTH(CURDATE()) AND YEAR(ep.production_date)=YEAR(CURDATE())) AS month_eggs,
        (SELECT COUNT(DISTINCT ep.production_date) FROM egg_production ep WHERE ep.flock_id=f.id
            AND ep.production_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)) AS week_days,
        (SELECT COUNT(*) FROM health_records hr WHERE hr.flock_id=f.id AND hr.status='active') AS active_cases
    FROM flocks f $where
    ORDER BY FIELD(f.status,'active','quarantine','sold','depleted'), f.created_at DESC
");
$stmt->execute($params);
$flocks = $stmt->fetchAll();

// ── All flocks (unfiltered) for KPIs ─────────────────────────
$allFlocks = $pdo->query("
    SELECT f.*, f.quantity,
        (SELECT COALESCE(SUM(ml.count),0) FROM mortality_logs ml WHERE ml.flock_id=f.id) AS total_mortality,
        (SELECT COALESCE(SUM(ep.quantity),0) FROM egg_production ep WHERE ep.flock_id=f.id) AS total_eggs,
        (SELECT COALESCE(SUM(ep.quantity),0) FROM egg_production ep WHERE ep.flock_id=f.id AND ep.production_date=CURDATE()) AS today_eggs,
        (SELECT COALESCE(SUM(ep.quantity),0) FROM egg_production ep WHERE ep.flock_id=f.id AND ep.production_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY)) AS yesterday_eggs,
        (SELECT COUNT(*) FROM vaccinations v WHERE v.flock_id=f.id AND v.status='overdue') AS vacc_overdue,
        (SELECT COUNT(DISTINCT ep.production_date) FROM egg_production ep WHERE ep.flock_id=f.id
            AND ep.production_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)) AS week_days,
        (SELECT COUNT(*) FROM health_records hr WHERE hr.flock_id=f.id AND hr.status='active') AS active_cases
    FROM flocks f ORDER BY f.created_at DESC
")->fetchAll();

$activeFlocks  = array_filter($allFlocks, fn($f)=>$f['status']==='active');
$totalBirds    = array_sum(array_column($activeFlocks,'quantity'));
$totalLayers   = array_sum(array_column(array_filter($activeFlocks,fn($f)=>$f['bird_type']==='layer'),'quantity'));
$totalBroilers = array_sum(array_column(array_filter($activeFlocks,fn($f)=>$f['bird_type']==='broiler'),'quantity'));
$totalMort     = array_sum(array_column($allFlocks,'total_mortality'));
$totalEggsAll  = array_sum(array_column($allFlocks,'total_eggs'));
$todayEggsAll  = array_sum(array_column($allFlocks,'today_eggs'));
$yestEggsAll   = array_sum(array_column($allFlocks,'yesterday_eggs'));
$eggChange     = $yestEggsAll > 0 ? round((($todayEggsAll-$yestEggsAll)/$yestEggsAll)*100,1) : 0;
$mortRate      = ($totalBirds+$totalMort)>0 ? round(($totalMort/($totalBirds+$totalMort))*100,2) : 0;
$overdueVacc   = (int)$pdo->query("SELECT COUNT(*) FROM vaccinations WHERE status='overdue'")->fetchColumn();
$expectedEggs  = max(1,$totalLayers*0.8);
$productivity  = $totalLayers>0 ? min(100,round(($todayEggsAll/$expectedEggs)*100,1)) : 0;

// ── Health counts ─────────────────────────────────────────────
function healthSignal(array $f): array {
    $rate = $f['quantity']>0 ? ($f['total_mortality']/$f['quantity'])*100 : 0;
    if ($rate>5 || (int)($f['active_cases']??0)>0)  return ['#ef4444','Critical',  'fa-times-circle'];
    if ($rate>2 || (int)($f['vacc_overdue']??0)>0)  return ['#f59e0b','Attention', 'fa-exclamation-triangle'];
    return ['#22c55e','Healthy','fa-check-circle'];
}
$healthCounts = ['Healthy'=>0,'Attention'=>0,'Critical'=>0];
$alertItems   = [];
foreach ($allFlocks as $f) {
    [,$lbl] = healthSignal($f);
    $healthCounts[$lbl]++;
    $rate = $f['quantity']>0 ? round(($f['total_mortality']/$f['quantity'])*100,1) : 0;
    if ($rate>2)                       $alertItems[] = ['🔴',"<strong>{$f['flock_name']}</strong> — mortality at {$rate}%"];
    if ((int)($f['vacc_overdue']??0)>0) $alertItems[] = ['🟡',"<strong>{$f['flock_name']}</strong> — ".$f['vacc_overdue']." vaccination(s) overdue"];
    if ((int)($f['active_cases']??0)>0) $alertItems[] = ['🔴',"<strong>{$f['flock_name']}</strong> — ".$f['active_cases']." active health case(s)"];
}

// ── Upcoming vaccinations ─────────────────────────────────────
$upcoming = $pdo->query("
    SELECT v.vaccine_name, v.next_due_date, f.flock_name, f.id as flock_id
    FROM vaccinations v JOIN flocks f ON f.id=v.flock_id
    WHERE v.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
    ORDER BY v.next_due_date LIMIT 5
")->fetchAll();

// ── Egg chart data (14 days) ──────────────────────────────────
$eggChart = $pdo->query("
    SELECT DATE(production_date) as d, SUM(quantity) as qty
    FROM egg_production WHERE production_date>=DATE_SUB(CURDATE(),INTERVAL 14 DAY)
    GROUP BY DATE(production_date) ORDER BY d
")->fetchAll();

// ── Housing utilisation ───────────────────────────────────────
$housingRaw = $pdo->query("
    SELECT housing_unit, SUM(quantity) as birds
    FROM flocks WHERE status='active' AND housing_unit IS NOT NULL AND housing_unit!=''
    GROUP BY housing_unit ORDER BY birds DESC
")->fetchAll();

// ── Helpers ───────────────────────────────────────────────────
function ageStr(int $d): string {
    if ($d<0)   return '0d';
    if ($d<7)   return $d.'d';
    if ($d<30)  return round($d/7).'w';
    if ($d<365) return round($d/30).'mo';
    return round($d/365,1).'y';
}
$typeMeta = [
    'layer'    =>['emoji'=>'🐔','color'=>'#15803d','bg'=>'#dcfce7','border'=>'#4ade80'],
    'broiler'  =>['emoji'=>'🍗','color'=>'#9a3412','bg'=>'#ffedd5','border'=>'#fb923c'],
    'cockerel' =>['emoji'=>'🐓','color'=>'#6b21a8','bg'=>'#f3e8ff','border'=>'#c084fc'],
    'turkey'   =>['emoji'=>'🦃','color'=>'#374151','bg'=>'#f1f5f9','border'=>'#94a3b8'],
];
$statusMeta = [
    'active'    =>['bg'=>'#dcfce7','color'=>'#15803d','dot'=>'#22c55e'],
    'quarantine'=>['bg'=>'#fef3c7','color'=>'#92400e','dot'=>'#fbbf24'],
    'sold'      =>['bg'=>'#dbeafe','color'=>'#1e40af','dot'=>'#3b82f6'],
    'depleted'  =>['bg'=>'#fee2e2','color'=>'#991b1b','dot'=>'#ef4444'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Flock Management — Coopers Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../public/css/admin.css?v=8">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
<?php include 'includes/topbar.php'; ?>
<div class="dashboard-content">

<!-- Header + quick-add dropdown -->
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
  <div>
    <h1>Flock Management</h1>
    <p>Monitor bird health, production performance and lifecycle across all flocks</p>
  </div>
  <div style="position:relative;">
    <button class="btn btn-primary" id="addMenuBtn" onclick="toggleAddMenu()" style="gap:8px;">
      <i class="fas fa-plus"></i> Add Flock <i class="fas fa-chevron-down" style="font-size:10px;"></i>
    </button>
    <div id="addMenu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:white;border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12);z-index:200;min-width:200px;overflow:hidden;">
      <button class="add-menu-item" onclick="document.getElementById('addFlockModal').classList.add('open');toggleAddMenu()"><i class="fas fa-kiwi-bird"></i> Add Flock</button>
      <a class="add-menu-item" href="egg_production.php"><i class="fas fa-egg"></i> Record Eggs</a>
      <a class="add-menu-item" href="mortality.php"><i class="fas fa-skull"></i> Record Mortality</a>
      <a class="add-menu-item" href="vaccinations.php"><i class="fas fa-syringe"></i> Record Vaccination</a>
      <a class="add-menu-item" href="health.php"><i class="fas fa-heartbeat"></i> Record Health Check</a>
      <a class="add-menu-item" href="feed.php"><i class="fas fa-seedling"></i> Record Feed</a>
    </div>
  </div>
</div>


<?php if($urlMsg): [$t,$tx]=explode(':',$urlMsg,2); ?>
<div class="alert alert-<?php echo $t; ?>" style="margin-bottom:14px;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($tx); ?></div>
<?php endif; ?>
<?php if($overdueVacc>0): ?>
<div class="alert alert-error" style="margin-bottom:14px;">
  <i class="fas fa-exclamation-circle"></i> <strong><?php echo $overdueVacc; ?> overdue vaccination(s)</strong> — flocks at disease risk.
  <a href="vaccinations.php" style="color:#991b1b;font-weight:700;margin-left:8px;">Fix now →</a>
</div>
<?php endif; ?>

<!-- ── KPI Strip ──────────────────────────────────────────── -->
<div class="kpi-strip">
  <div class="kpi b">
    <i class="fas fa-kiwi-bird kpi-ico"></i>
    <div class="kpi-lbl">Total Birds</div>
    <div class="kpi-val"><?php echo number_format($totalBirds); ?></div>
    <div class="kpi-sub">🐔 <?php echo number_format($totalLayers); ?> layers · 🍗 <?php echo number_format($totalBroilers); ?> broilers</div>
  </div>
  <div class="kpi g">
    <i class="fas fa-layer-group kpi-ico"></i>
    <div class="kpi-lbl">Active Flocks</div>
    <div class="kpi-val"><?php echo count($activeFlocks); ?></div>
    <div class="kpi-sub">
      🟢 <?php echo $healthCounts['Healthy']; ?> healthy ·
      🟡 <?php echo $healthCounts['Attention']; ?> monitoring ·
      🔴 <?php echo $healthCounts['Critical']; ?> critical
    </div>
  </div>
  <div class="kpi y">
    <i class="fas fa-egg kpi-ico"></i>
    <div class="kpi-lbl">Eggs Today</div>
    <div class="kpi-val"><?php echo number_format($todayEggsAll); ?></div>
    <div class="kpi-sub">
      <?php if($yestEggsAll>0): ?>
        <span class="<?php echo $eggChange>=0?'trend-up':'trend-dn'; ?>">
          <?php echo $eggChange>=0?'↑':'↓'; ?><?php echo abs($eggChange); ?>% vs yesterday
        </span>
      <?php else: ?>
        Target: <?php echo number_format(round($expectedEggs)); ?> eggs
      <?php endif; ?>
    </div>
  </div>
  <div class="kpi <?php echo $totalMort>0?'r':'g'; ?>">
    <i class="fas fa-skull kpi-ico"></i>
    <div class="kpi-lbl">Mortality Rate</div>
    <div class="kpi-val" style="color:<?php echo $totalMort>0?'#ef4444':'inherit'; ?>"><?php echo $mortRate; ?>%</div>
    <div class="kpi-sub"><?php echo number_format($totalMort); ?> total deaths · <?php echo $totalMort>0?'monitor closely':'all clear ✓'; ?></div>
  </div>
  <div class="kpi p">
    <i class="fas fa-chart-line kpi-ico"></i>
    <div class="kpi-lbl">Layer Productivity</div>
    <div class="kpi-val" style="color:<?php echo $productivity>=70?'#4A7C59':($productivity>=45?'#f59e0b':'#ef4444'); ?>"><?php echo $productivity; ?>%</div>
    <div class="kpi-sub"><?php echo $productivity>=70?'Excellent 🎯':($productivity>=45?'Fair ⚠':'Low 🔴'); ?> · expected <?php echo number_format(round($expectedEggs)); ?>/day</div>
  </div>
</div>

<!-- ── Middle: Chart (left) + Health & Alerts (right) ─────── -->
<div class="mid-row">

  <!-- Egg production chart with time switcher -->
  <div class="chart-card">
    <div class="chart-title">Egg Production Trend</div>
    <div class="chart-sub">
      <span>Daily eggs collected across all layer flocks</span>
      <div class="chart-switcher">
        <button class="csw-btn active" onclick="switchChart(7,this)">7d</button>
        <button class="csw-btn" onclick="switchChart(14,this)">14d</button>
        <button class="csw-btn" onclick="switchChart(30,this)">30d</button>
      </div>
    </div>
    <canvas id="eggChart" height="110"></canvas>
  </div>

  <!-- Health & Alerts panel -->
  <div class="alerts-card">
    <div class="alerts-title">Health &amp; Alerts</div>

    <!-- Health summary -->
    <div class="health-summary">
      <div class="h-row">
        <div class="h-dot" style="background:#22c55e;box-shadow:0 0 0 3px #22c55e22;"></div>
        <span class="h-label">Healthy</span>
        <span class="h-count" style="background:#dcfce7;color:#15803d;"><?php echo $healthCounts['Healthy']; ?> flock<?php echo $healthCounts['Healthy']!==1?'s':''; ?></span>
      </div>
      <div class="h-row">
        <div class="h-dot" style="background:#f59e0b;box-shadow:0 0 0 3px #f59e0b22;"></div>
        <span class="h-label">Monitoring</span>
        <span class="h-count" style="background:#fef3c7;color:#92400e;"><?php echo $healthCounts['Attention']; ?> flock<?php echo $healthCounts['Attention']!==1?'s':''; ?></span>
      </div>
      <div class="h-row">
        <div class="h-dot" style="background:#ef4444;box-shadow:0 0 0 3px #ef444422;"></div>
        <span class="h-label">Critical</span>
        <span class="h-count" style="background:#fee2e2;color:#991b1b;"><?php echo $healthCounts['Critical']; ?> flock<?php echo $healthCounts['Critical']!==1?'s':''; ?></span>
      </div>
    </div>

    <!-- Alert list -->
    <div class="alert-list">
      <?php if(empty($alertItems) && empty($upcoming)): ?>
      <div class="no-alerts">
        <i class="fas fa-check-circle" style="font-size:24px;color:#4ade80;margin-bottom:6px;display:block;"></i>
        No alerts — all flocks are healthy!
      </div>
      <?php else: ?>
      <?php foreach(array_slice($alertItems,0,5) as $ai): ?>
      <div class="alert-item <?php echo $ai[0]==='🔴'?'critical':'warn'; ?>">
        <span style="font-size:14px;flex-shrink:0;"><?php echo $ai[0]; ?></span>
        <span><?php echo $ai[1]; ?></span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Upcoming vaccinations -->
    <?php if(!empty($upcoming)): ?>
    <div class="upcoming-section">
      <div class="upcoming-title"><i class="fas fa-syringe" style="color:var(--primary);margin-right:4px;"></i>Vaccinations due this week</div>
      <?php foreach($upcoming as $uv): ?>
      <div class="upc-item">
        <span><?php echo htmlspecialchars($uv['flock_name']); ?> — <?php echo htmlspecialchars($uv['vaccine_name']); ?></span>
        <span style="color:var(--primary);font-weight:700;"><?php echo date('M d',strtotime($uv['next_due_date'])); ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php
// ── Housing: per-house insight (drives the clickable house cards) ──
$HOUSE_CAPACITY = 600;   // flocks have no capacity field yet; utilisation is measured against this
$houseNames = $pdo->query("SELECT DISTINCT housing_unit FROM flocks WHERE status='active' AND housing_unit IS NOT NULL AND housing_unit<>'' ORDER BY housing_unit")->fetchAll(PDO::FETCH_COLUMN);
$houseData  = [];
foreach ($houseNames as $hn) {
    $st = $pdo->prepare("SELECT id, flock_name, bird_type, quantity, arrival_date FROM flocks WHERE status='active' AND housing_unit=? ORDER BY flock_name");
    $st->execute([$hn]);
    $flist = $st->fetchAll();
    $ids   = implode(',', array_map('intval', array_column($flist, 'id')));
    $birds = (int)array_sum(array_column($flist, 'quantity'));

    $layEggs = 0; $layDenom = 0; $vaccBirds = 0; $flockRowsH = [];
    foreach ($flist as $fx) {
        $fid = (int)$fx['id']; $qty = (int)$fx['quantity'];
        $eg = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) AS e7, COUNT(DISTINCT production_date) AS d7
            FROM egg_production WHERE flock_id=? AND production_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        $eg->execute([$fid]);
        $eRow = $eg->fetch();
        $vb = $pdo->prepare("SELECT COALESCE(MAX(birds_vaccinated),0) FROM vaccinations WHERE flock_id=? AND status='completed'");
        $vb->execute([$fid]);
        $vaccinated = min($qty, (int)$vb->fetchColumn());
        $vaccBirds += $vaccinated;
        $fLay = null;
        if ($fx['bird_type'] === 'layer' && $qty > 0 && (int)$eRow['d7'] > 0) {
            $fLay      = round(((int)$eRow['e7'] / ($qty * (int)$eRow['d7'])) * 100, 1);
            $layEggs  += (int)$eRow['e7'];
            $layDenom += $qty * (int)$eRow['d7'];
        }
        $d30 = $pdo->prepare("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE flock_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $d30->execute([$fid]);
        $flockRowsH[] = ['id'=>$fid, 'name'=>$fx['flock_name'], 'type'=>$fx['bird_type'], 'qty'=>$qty,
                         'vaccinated'=>$vaccinated, 'deaths30'=>(int)$d30->fetchColumn(), 'lay7'=>$fLay,
                         'eggs7'=>(int)$eRow['e7']];
    }

    $deaths30  = (int)$pdo->query("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE flock_id IN ($ids) AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $eggs30    = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE flock_id IN ($ids) AND production_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $feed30    = (float)$pdo->query("SELECT COALESCE(SUM(quantity_kg),0) FROM feed_logs WHERE flock_id IN ($ids) AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $feedCost  = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM feed_logs WHERE flock_id IN ($ids) AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $hActive   = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(affected_count),0) AS aff FROM health_records WHERE flock_id IN ($ids) AND status IN ('active','monitoring')")->fetch();
    $vOverdue  = (int)$pdo->query("SELECT COUNT(*) FROM vaccinations WHERE flock_id IN ($ids) AND status IN ('scheduled','overdue') AND COALESCE(next_due_date,date_given) < CURDATE()")->fetchColumn();
    $vDue7     = (int)$pdo->query("SELECT COUNT(*) FROM vaccinations WHERE flock_id IN ($ids) AND status IN ('scheduled','overdue') AND COALESCE(next_due_date,date_given) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

    $houseData[] = [
        'name'        => $hn,
        'capacity'    => $HOUSE_CAPACITY,
        'birds'       => $birds,
        'utilisation' => min(100, round(($birds / $HOUSE_CAPACITY) * 100)),
        'flocks'      => count($flist),
        'vaccinated'  => $vaccBirds,
        'coverage'    => $birds > 0 ? round(($vaccBirds / $birds) * 100) : 0,
        'vOverdue'    => $vOverdue,
        'vDue7'       => $vDue7,
        'deaths30'    => $deaths30,
        'mortality'   => ($birds + $deaths30) > 0 ? round(($deaths30 / ($birds + $deaths30)) * 100, 2) : 0,
        'healthCases' => (int)$hActive['n'],
        'affected'    => (int)$hActive['aff'],
        'health'      => $birds > 0 ? round((($birds - (int)$hActive['aff']) / $birds) * 100, 1) : 100,
        'eggs30'      => $eggs30,
        'eggsPerDay'  => round($eggs30 / 30, 1),
        'lay'         => $layDenom > 0 ? round(($layEggs / $layDenom) * 100, 1) : null,
        'feed30'      => round($feed30, 1),
        'feedPerBird' => $birds > 0 ? round($feed30 / $birds, 2) : 0,
        'feedCost30'  => round($feedCost, 2),
        'flockRows'   => $flockRowsH,
    ];
}
?>
<!-- ── Housing: click a house for its full insight ─────────── -->
<div class="house-row">
  <?php if(empty($houseData)): ?>
    <div class="house-empty">No active flocks have a housing unit yet. Add one when you create or edit a flock.</div>
  <?php else: foreach($houseData as $hIdx => $h):
    $utilClr = $h['utilisation']>=90?'#ef4444':($h['utilisation']>=75?'#f59e0b':'#4A7C59'); ?>
  <button type="button" class="house-card" onclick="openHouseDrawer(<?php echo $hIdx; ?>)">
    <div class="house-top">
      <span class="house-name"><i class="fas fa-home"></i> <?php echo htmlspecialchars($h['name']); ?></span>
      <span class="house-flocks"><?php echo $h['flocks']; ?> flock<?php echo $h['flocks']!==1?'s':''; ?></span>
    </div>
    <div class="house-birds"><strong><?php echo number_format($h['birds']); ?></strong> birds · <?php echo $h['utilisation']; ?>% of est. capacity</div>
    <div class="house-bar"><div style="width:<?php echo $h['utilisation']; ?>%;background:<?php echo $utilClr; ?>;"></div></div>
    <div class="house-stats">
      <span>💉 <?php echo $h['coverage']; ?>% vaccinated</span>
      <span>☠ <?php echo $h['mortality']; ?>% mort. 30d</span>
      <span>❤ <?php echo $h['health']; ?>% healthy</span>
      <span>🥚 <?php echo $h['lay']!==null ? $h['lay'].'% lay' : '—'; ?></span>
    </div>
    <?php if($h['vOverdue']>0 || $h['healthCases']>0): ?>
    <div class="house-alert">
      <?php if($h['vOverdue']>0): ?><span>⚠ <?php echo $h['vOverdue']; ?> vaccination overdue</span><?php endif; ?>
      <?php if($h['healthCases']>0): ?><span>🩺 <?php echo $h['healthCases']; ?> open case<?php echo $h['healthCases']>1?'s':''; ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
  </button>
  <?php endforeach; endif; ?>
</div>

<!-- ── Filter bar ────────────────────────────────────────────── -->
<div class="filter-bar">
  <a class="fchip <?php echo !$typeFilter&&!$statFilter&&!$search?'active':''; ?>" href="flocks.php">All</a>
  <a class="fchip <?php echo $typeFilter==='layer'?'active':''; ?>"    href="?type=layer">🐔 Layers</a>
  <a class="fchip <?php echo $typeFilter==='broiler'?'active':''; ?>"  href="?type=broiler">🍗 Broilers</a>
  <a class="fchip <?php echo $typeFilter==='cockerel'?'active':''; ?>" href="?type=cockerel">🐓 Cockerel</a>
  <div style="height:20px;width:1px;background:var(--border);"></div>
  <a class="fchip <?php echo $statFilter==='active'?'active':''; ?>"      href="?status=active">✅ Active</a>
  <a class="fchip amber <?php echo $statFilter==='quarantine'?'active':''; ?>" href="?status=quarantine">⚠️ Quarantine</a>
  <a class="fchip danger <?php echo $statFilter==='depleted'?'active':''; ?>"  href="?status=depleted">❌ Depleted</a>
  <div style="height:20px;width:1px;background:var(--border);"></div>
  <!-- Health filter -->
  <button type="button" class="fchip" id="healthFilterBtn" onclick="toggleHealthFilter()" title="Filter by health">🟢 Healthy only</button>
  <input type="text" class="fsearch" placeholder="🔍 Search flock name..." value="<?php echo htmlspecialchars($search); ?>"
         oninput="liveSearch(this.value)" style="margin-left:auto;width:190px;">
</div>

<!-- ── Flock table ────────────────────────────────────────────── -->
<div class="ftable-wrap">
  <div class="ftable-head">
    <div class="ftable-head-title">
      <?php echo $typeFilter?ucfirst($typeFilter).' Flocks':'All Flocks'; ?>
      <span style="font-size:13px;color:#9ca3af;font-weight:400;margin-left:6px;">(<?php echo count($flocks); ?>)</span>
    </div>
    <span style="font-size:12px;color:#9ca3af;">Click any row to view full profile</span>
  </div>
  <div style="overflow-x:auto;">
  <table class="ftable" id="flockTable">
    <thead>
      <tr>
        <th onclick="sortTable(0)">Flock <?php // sort icon placeholder ?><i class="fas fa-sort" style="font-size:9px;color:#d1d5db;margin-left:3px;"></i></th>
        <th onclick="sortTable(1)">Type</th>
        <th onclick="sortTable(2)" class="right">Birds <i class="fas fa-sort" style="font-size:9px;color:#d1d5db;margin-left:3px;"></i></th>
        <th onclick="sortTable(3)">Age <i class="fas fa-sort" style="font-size:9px;color:#d1d5db;margin-left:3px;"></i></th>
        <th>Housing</th>
        <th>Health</th>
        <th onclick="sortTable(6)">Mortality <i class="fas fa-sort" style="font-size:9px;color:#d1d5db;margin-left:3px;"></i></th>
        <th class="right" onclick="sortTable(7)">Eggs Today <i class="fas fa-sort" style="font-size:9px;color:#d1d5db;margin-left:3px;"></i></th>
        <th>Lay Rate</th>
        <th>Status</th>
        <th class="right">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($flocks as $f):
      [$hColor,$hLabel,$hIcon] = healthSignal($f);
      $tm     = $typeMeta[$f['bird_type']] ?? $typeMeta['layer'];
      $sm     = $statusMeta[$f['status']]  ?? $statusMeta['active'];
      $mortR  = $f['quantity']>0 ? round(($f['total_mortality']/$f['quantity'])*100,1) : 0;
      $layR = ($f['bird_type']==='layer' && $f['quantity']>0 && (int)$f['week_days']>0)
                ? min(100, round(($f['week_eggs'] / ($f['quantity'] * (int)$f['week_days'])) * 100, 1)) : null;
      $eggTrend = $f['yesterday_eggs']>0
                  ? round((($f['today_eggs']-$f['yesterday_eggs'])/$f['yesterday_eggs'])*100,1)
                  : null;
      $rowCls = $hLabel==='Critical'?'row-critical':($hLabel==='Attention'?'row-warning':'');
      $mortBarClr = $mortR>5?'#ef4444':($mortR>2?'#f59e0b':'#22c55e');
    ?>
    <tr class="<?php echo $rowCls; ?> flock-row"
        id="frow_<?php echo $f['id']; ?>"
        data-name="<?php echo strtolower(htmlspecialchars($f['flock_name'])); ?>"
        data-health="<?php echo strtolower($hLabel); ?>"
        onclick="flockOpenDrawer(<?php echo $f['id']; ?>)">

      <!-- Flock name -->
      <td class="flock-name-cell">
        <strong><?php echo htmlspecialchars($f['flock_name']); ?></strong>
        <span>
          <?php if((int)($f['active_cases']??0)>0): ?>
            <span class="vacc-badge" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-heartbeat" style="font-size:9px;"></i> <?php echo $f['active_cases']; ?> case<?php echo $f['active_cases']>1?'s':''; ?></span>
          <?php endif; ?>
          <?php if((int)($f['vacc_overdue']??0)>0): ?>
            <span class="vacc-badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-syringe" style="font-size:9px;"></i> <?php echo $f['vacc_overdue']; ?> overdue</span>
          <?php else: ?>
            <span class="vacc-badge" style="background:#f1f5f9;color:#64748b;"><i class="fas fa-syringe" style="font-size:9px;"></i> Vacc ✓</span>
          <?php endif; ?>
        </span>
      </td>

      <!-- Type -->
      <td>
        <span class="type-badge" style="background:<?php echo $tm['bg']; ?>;color:<?php echo $tm['color']; ?>;border-color:<?php echo $tm['border']; ?>;">
          <?php echo $tm['emoji'].' '.ucfirst($f['bird_type']); ?>
        </span>
      </td>

      <!-- Birds -->
      <td class="right"><strong><?php echo number_format($f['quantity']); ?></strong></td>

      <!-- Age -->
      <td style="color:#6b7280;"><?php echo ageStr($f['age_days']); ?></td>

      <!-- Housing -->
      <td style="font-size:12px;color:#6b7280;white-space:nowrap;">
        <i class="fas fa-home" style="font-size:10px;margin-right:3px;opacity:.5;"></i>
        <?php echo htmlspecialchars($f['housing_unit']??'—'); ?>
      </td>

      <!-- Health signal -->
      <td>
        <span class="health-pill" style="background:<?php echo $hColor; ?>18;color:<?php echo $hColor; ?>;">
          <i class="fas <?php echo $hIcon; ?>" style="font-size:9px;"></i>
          <?php echo $hLabel; ?>
        </span>
      </td>

      <!-- Mortality -->
      <td>
        <span class="pbar-mini"><span class="pbar-mini-fill" style="width:<?php echo min(100,$mortR*10); ?>%;background:<?php echo $mortBarClr; ?>;"></span></span>
        <span style="font-weight:700;color:<?php echo $mortBarClr; ?>;"><?php echo $f['total_mortality']; ?></span>
        <span style="color:#9ca3af;font-size:11px;"> (<?php echo $mortR; ?>%)</span>
      </td>

      <!-- Eggs today -->
      <td class="right">
        <strong style="font-size:14px;"><?php echo $f['today_eggs']>0?number_format($f['today_eggs']):'—'; ?></strong>
        <?php if($eggTrend !== null): ?>
          <br><span class="<?php echo $eggTrend>=0?'trend-up':'trend-dn'; ?>"><?php echo $eggTrend>=0?'↑':'↓'; ?><?php echo abs($eggTrend); ?>%</span>
        <?php endif; ?>
      </td>

      <!-- Lay rate -->
      <td>
        <?php if($layR!==null): ?>
          <span class="pbar-mini"><span class="pbar-mini-fill" style="width:<?php echo min(100,$layR); ?>%;background:<?php echo $layR>=70?'#22c55e':($layR>=45?'#f59e0b':'#ef4444'); ?>;"></span></span>
          <span style="font-weight:700;color:<?php echo $layR>=70?'#22c55e':($layR>=45?'#f59e0b':'#ef4444'); ?>;"><?php echo $layR; ?>%</span>
        <?php else: ?>
          <span style="color:#d1d5db;">—</span>
        <?php endif; ?>
      </td>

      <!-- Status -->
      <td>
        <span class="stat-badge" style="background:<?php echo $sm['bg']; ?>;color:<?php echo $sm['color']; ?>;">
          <span style="width:6px;height:6px;border-radius:50%;background:<?php echo $sm['dot']; ?>;"></span>
          <?php echo ucfirst($f['status']); ?>
        </span>
      </td>

      <!-- Actions -->
      <td class="right" onclick="event.stopPropagation()">
        <div style="display:flex;gap:4px;justify-content:flex-end;">
          <button class="act-btn" onclick="flockOpenEditModal(<?php echo htmlspecialchars(json_encode($f),ENT_QUOTES); ?>)" title="Edit flock"><i class="fas fa-pen"></i></button>
          <button class="act-btn" onclick="openStatusModal(<?php echo $f['id']; ?>,'<?php echo $f['status']; ?>')" title="Change status"><i class="fas fa-toggle-on"></i></button>
          <a class="act-btn" href="egg_production.php?flock_id=<?php echo $f['id']; ?>" title="Log eggs"><i class="fas fa-egg"></i></a>
          <a class="act-btn" href="vaccinations.php?flock_id=<?php echo $f['id']; ?>" title="Vaccinations"><i class="fas fa-syringe"></i></a>
          <a class="act-btn" href="health.php?flock_id=<?php echo $f['id']; ?>" title="Health"><i class="fas fa-heartbeat"></i></a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($flocks)): ?>
    <tr><td colspan="11" style="text-align:center;padding:48px;color:#9ca3af;">
      <i class="fas fa-kiwi-bird" style="font-size:40px;color:#e5e7eb;display:block;margin-bottom:12px;"></i>
      <strong style="display:block;margin-bottom:4px;">No flocks found</strong>
      <a href="flocks.php" style="color:var(--primary);font-size:13px;">Clear filters →</a>
    </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div>
</div>

<!-- ── Flock Detail Drawer ─────────────────────────────────── -->
<div class="drawer-overlay" id="drawerOverlay" onclick="flockCloseDrawer()"></div>
<div class="drawer" id="flockDrawer">
  <div class="dhead-banner">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
      <div>
        <div style="font-size:18px;font-weight:700;font-family:'Playfair Display',serif;margin-bottom:3px;" id="dName">Flock Details</div>
        <div style="font-size:12px;color:#9ca3af;display:flex;gap:6px;flex-wrap:wrap;" id="dSub"></div>
      </div>
      <button style="width:30px;height:30px;border-radius:6px;border:1px solid var(--border);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;color:#6b7280;" onclick="flockCloseDrawer()"><i class="fas fa-times"></i></button>
    </div>
    <div id="dBadges" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
  </div>
  <div class="dbody" id="drawerBody">
    <div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fas fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;"></i>Loading…</div>
  </div>
</div>

<!-- ── Add Flock Modal ──────────────────────────────────────── -->
<div class="modal-overlay" id="addFlockModal">
  <div class="modal" style="max-width:640px;">
    <div class="modal-header">
      <h3><i class="fas fa-plus-circle" style="color:var(--primary);margin-right:8px;"></i>Add New Flock</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_flock" value="1">
      <div style="padding:0 32px;">
        <div style="font-size:12px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin:16px 0 10px;">Basic Information</div>
        <div class="form-row">
          <div class="form-group"><label>Flock Name *</label><input type="text" name="flock_name" class="form-control" required placeholder="e.g. Batch A – Layers 2025"></div>
          <div class="form-group"><label>Bird Type *</label>
            <select name="bird_type" class="form-control" required>
              <option value="layer">🐔 Layer</option><option value="broiler">🍗 Broiler</option>
              <option value="cockerel">🐓 Cockerel</option><option value="turkey">🦃 Turkey</option>
            </select>
          </div>
        </div>
        <div style="font-size:12px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin:14px 0 10px;">Bird Information</div>
        <div class="form-row">
          <div class="form-group"><label>Initial Quantity *</label><input type="number" name="quantity" class="form-control" required min="1" placeholder="Number of birds"></div>
          <div class="form-group"><label>Arrival Date *</label><input type="date" name="arrival_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>"></div>
        </div>
        <div style="font-size:12px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin:14px 0 10px;">Housing &amp; Source</div>
        <div class="form-row">
          <div class="form-group"><label>Source / Supplier</label><input type="text" name="source" class="form-control" placeholder="e.g. Accra Hatchery"></div>
          <div class="form-group"><label>Housing Unit</label><input type="text" name="housing_unit" class="form-control" placeholder="e.g. House 1, Pen A"></div>
        </div>
        <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Additional information…"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Flock</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Flock Modal ─────────────────────────────────────── -->
<div class="modal-overlay" id="editFlockModal">
  <div class="modal" style="max-width:640px;">
    <div class="modal-header">
      <h3><i class="fas fa-pen" style="color:var(--primary);margin-right:8px;"></i>Edit Flock</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="edit_flock" value="1">
      <input type="hidden" name="flock_id" id="eFID">
      <div style="padding:0 32px;">
        <div class="form-row" style="margin-top:16px;">
          <div class="form-group"><label>Flock Name *</label><input type="text" name="flock_name" id="eFN" class="form-control" required></div>
          <div class="form-group"><label>Bird Type *</label>
            <select name="bird_type" id="eBT" class="form-control" required>
              <option value="layer">🐔 Layer</option><option value="broiler">🍗 Broiler</option>
              <option value="cockerel">🐓 Cockerel</option><option value="turkey">🦃 Turkey</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Quantity *</label><input type="number" name="quantity" id="eQT" class="form-control" required min="0"></div>
          <div class="form-group"><label>Arrival Date *</label><input type="date" name="arrival_date" id="eAD" class="form-control" required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Source</label><input type="text" name="source" id="eSR" class="form-control"></div>
          <div class="form-group"><label>Housing Unit</label><input type="text" name="housing_unit" id="eHU" class="form-control"></div>
        </div>
        <div class="form-group"><label>Notes</label><textarea name="notes" id="eNT" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Status Modal ─────────────────────────────────────────── -->
<div class="modal-overlay" id="statusModal">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header">
      <h3><i class="fas fa-toggle-on" style="color:var(--primary);margin-right:8px;"></i>Update Flock Status</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="update_status" value="1">
      <input type="hidden" name="flock_id" id="sSFID">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:16px 32px 20px;">
        <?php foreach(['active'=>'✅ Active','quarantine'=>'⚠️ Quarantine','sold'=>'💰 Sold','depleted'=>'❌ Depleted'] as $v=>$l): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">
          <input type="radio" name="status" value="<?php echo $v; ?>" id="sr_<?php echo $v; ?>" style="accent-color:var(--primary);">
          <?php echo $l; ?>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
</div>

<!-- ── House insight drawer ─────────────────────────────────── -->
<div class="drawer-overlay" id="houseOverlay" onclick="closeHouseDrawer()"></div>
<div class="drawer" id="houseDrawer">
  <div class="drawer-head">
    <h3 id="houseTitle">House</h3>
    <button class="drawer-close" onclick="closeHouseDrawer()"><i class="fas fa-times"></i></button>
  </div>
  <div class="drawer-body" id="houseBody"></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<?php
$e30 = $pdo->query("SELECT DATE(production_date) as d, SUM(quantity) as qty FROM egg_production WHERE production_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY DATE(production_date) ORDER BY d")->fetchAll();
$dm = [];
foreach ($flocks as $ff) {
  $va=$pdo->prepare("SELECT vaccine_name,date_given,status FROM vaccinations WHERE flock_id=? ORDER BY date_given DESC LIMIT 5"); $va->execute([$ff['id']]);
  $hl=$pdo->prepare("SELECT disease_name,record_date,outcome,status FROM health_records WHERE flock_id=? ORDER BY record_date DESC LIMIT 5"); $hl->execute([$ff['id']]);
  $eg=$pdo->prepare("SELECT production_date,quantity FROM egg_production WHERE flock_id=? ORDER BY production_date DESC LIMIT 7"); $eg->execute([$ff['id']]);
  $ff['_vacc']=$va->fetchAll(); $ff['_health']=$hl->fetchAll(); $ff['_eggs']=$eg->fetchAll();
  $dm[$ff['id']] = $ff;
}
$pageData = ['allEggData'=>$e30,'flockData'=>$dm];
?>
<?php $pageData['houses'] = $houseData; ?>
<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
