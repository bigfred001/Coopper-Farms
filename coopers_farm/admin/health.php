<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

// Status follows the outcome: a death or a recovery closes the case (completed).
function healthStatusFor(string $outcome): string {
    if ($outcome === 'died' || $outcome === 'recovered') return 'completed';
    if ($outcome === 'improving') return 'monitoring';
    return 'active';                       // pending / ongoing
}
function healthFlock(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT id, flock_name, quantity, bird_type FROM flocks WHERE id=?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}
function outcomeLabel(string $o): string { return ucfirst($o); }

// ── POST handlers ─────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_record'])) {
        $flockId  = (int)($_POST['flock_id'] ?? 0);
        $outcome  = $_POST['outcome'] ?? 'pending';
        $affected = max(0, (int)($_POST['affected_count'] ?? 0));
        $date     = $_POST['record_date'] ?? date('Y-m-d');
        $disease  = sanitize($_POST['disease_name'] ?? '');
        $type     = $_POST['type'] ?? 'disease';
        $flock    = healthFlock($pdo, $flockId);

        if (!$flock)                        $msg = 'error:Please choose a valid flock.';
        elseif ($affected > (int)$flock['quantity'])
            $msg = "error:{$flock['flock_name']} has only {$flock['quantity']} birds. You cannot record {$affected} affected.";
        elseif ($outcome === 'died' && $affected <= 0)
            $msg = 'error:Enter how many birds died.';
        else {
            $status = healthStatusFor($outcome);
            $pdo->prepare("INSERT INTO health_records
                (flock_id, record_date, type, disease_name, symptoms, treatment, treated_by,
                 outcome, affected_count, status, description, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$flockId, $date, $type, $disease,
                    sanitize($_POST['symptoms'] ?? ''), sanitize($_POST['treatment'] ?? ''),
                    sanitize($_POST['treated_by'] ?? ''), $outcome, $affected, $status,
                    sanitize($_POST['description'] ?? ''), sanitize($_POST['notes'] ?? ''),
                    $_SESSION['user_id']]);
            $newId = (int)$pdo->lastInsertId();
            auditLog($pdo, 'add_health_record', 'health', $newId,
                "Added {$type} for {$flock['flock_name']}: {$disease}. Outcome: {$outcome}. Affected: {$affected}");
            $msg = 'success:Health record added!';

            if ($outcome === 'died') {
                $pdo->prepare("INSERT INTO mortality_logs (flock_id, log_date, count, cause, notes, recorded_by)
                    VALUES (?,?,?,?,?,?)")
                    ->execute([$flockId, $date, $affected, $disease ?: 'Health issue',
                        "Auto-logged from health record #{$newId}", $_SESSION['user_id']]);
                $pdo->prepare("UPDATE flocks SET quantity = quantity - ? WHERE id=?")->execute([$affected, $flockId]);
                $pdo->prepare("UPDATE flocks SET status='depleted' WHERE id=? AND quantity<=0")->execute([$flockId]);
                $msg = "success:Record saved — {$affected} death(s) logged to mortality and deducted from {$flock['flock_name']}.";
            }
        }
    }

    if (isset($_POST['update_record'])) {
        $rid        = (int)($_POST['record_id'] ?? 0);
        $newOutcome = $_POST['outcome'] ?? 'pending';
        $notes      = sanitize($_POST['notes'] ?? '');

        $rs = $pdo->prepare("SELECT hr.*, f.flock_name, f.quantity AS flock_qty FROM health_records hr JOIN flocks f ON f.id=hr.flock_id WHERE hr.id=?");
        $rs->execute([$rid]);
        $rec = $rs->fetch();

        if (!$rec) {
            $msg = 'error:Record not found.';
        } elseif ($newOutcome === 'died' && (int)$rec['affected_count'] <= 0) {
            $msg = 'error:Set the affected bird count on this record before marking it as died.';
        } elseif ($newOutcome === 'died' && $rec['outcome'] !== 'died' && (int)$rec['affected_count'] > (int)$rec['flock_qty']) {
            $msg = "error:{$rec['flock_name']} has only {$rec['flock_qty']} birds. You cannot log {$rec['affected_count']} deaths.";
        } else {
            $newStatus = healthStatusFor($newOutcome);
            $pdo->prepare("UPDATE health_records SET outcome=?, status=?, notes=CONCAT(COALESCE(notes,''), ?), updated_by=? WHERE id=?")
                ->execute([$newOutcome, $newStatus,
                    $notes !== '' ? "\n[Update ".date('M d, Y')."]: {$notes}" : '',
                    $_SESSION['user_id'], $rid]);
            auditLog($pdo, 'update_health_record', 'health', $rid, "Outcome → {$newOutcome}, status → {$newStatus}");
            $msg = 'success:Record updated!';

            if ($newOutcome === 'died' && $rec['outcome'] !== 'died') {   // log deaths once only
                $n = (int)$rec['affected_count'];
                $pdo->prepare("INSERT INTO mortality_logs (flock_id, log_date, count, cause, notes, recorded_by)
                    VALUES (?,?,?,?,?,?)")
                    ->execute([$rec['flock_id'], $rec['record_date'], $n, $rec['disease_name'] ?: 'Health issue',
                        "Auto-logged from health record #{$rid}", $_SESSION['user_id']]);
                $pdo->prepare("UPDATE flocks SET quantity = quantity - ? WHERE id=?")->execute([$n, $rec['flock_id']]);
                $pdo->prepare("UPDATE flocks SET status='depleted' WHERE id=? AND quantity<=0")->execute([$rec['flock_id']]);
                $msg = "success:Updated — {$n} death(s) logged to mortality and deducted from {$rec['flock_name']}.";
            }
        }
    }

    header('Location: health.php?msg=' . urlencode($msg)); exit;
}

// ── Filters ───────────────────────────────────────────────────
$urlMsg        = $_GET['msg']     ?? '';
$flockFilter   = (int)($_GET['flock_id'] ?? 0);
$statFilter    = $_GET['status']  ?? '';
$typeFilter    = $_GET['type']    ?? '';
$outcomeFilter = $_GET['outcome'] ?? '';
$search        = $_GET['search']  ?? '';

$where  = "WHERE 1=1";
$params = [];
if ($flockFilter)   { $where .= " AND hr.flock_id=?"; $params[] = $flockFilter; }
if ($statFilter)    { $where .= " AND hr.status=?";   $params[] = $statFilter; }
if ($typeFilter)    { $where .= " AND hr.type=?";     $params[] = $typeFilter; }
if ($outcomeFilter) { $where .= " AND hr.outcome=?";  $params[] = $outcomeFilter; }
if ($search)        { $where .= " AND (hr.disease_name LIKE ? OR f.flock_name LIKE ? OR hr.treatment LIKE ?)";
                      $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

$recStmt = $pdo->prepare("
    SELECT hr.*, f.flock_name, f.bird_type, f.quantity AS flock_qty
    FROM health_records hr
    JOIN flocks f ON hr.flock_id=f.id
    $where
    ORDER BY CASE hr.status WHEN 'active' THEN 1 WHEN 'monitoring' THEN 2 ELSE 3 END, hr.record_date DESC
    LIMIT 200");
$recStmt->execute($params);
$records = $recStmt->fetchAll();

// ── KPIs & insight ────────────────────────────────────────────
$openCount     = (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE status IN ('active','monitoring')")->fetchColumn();
$activeCount   = (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE status='active'")->fetchColumn();
$monitorCount  = (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE status='monitoring'")->fetchColumn();
$completedCount= (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE status='completed'")->fetchColumn();
$highImpact    = (int)$pdo->query("SELECT COUNT(*) FROM health_records hr JOIN flocks f ON f.id=hr.flock_id
                    WHERE hr.status IN ('active','monitoring') AND hr.affected_count >= GREATEST(5, f.quantity*0.05)")->fetchColumn();
$birdsAtRisk   = (int)$pdo->query("SELECT COALESCE(SUM(affected_count),0) FROM health_records WHERE status IN ('active','monitoring')")->fetchColumn();
$recoveredCount= (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE outcome='recovered'")->fetchColumn();
$diedCount     = (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE outcome='died'")->fetchColumn();
$deathsFromHealth = (int)$pdo->query("SELECT COALESCE(SUM(affected_count),0) FROM health_records WHERE outcome='died'")->fetchColumn();
$thisMonthCases= (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE MONTH(record_date)=MONTH(CURDATE()) AND YEAR(record_date)=YEAR(CURDATE())")->fetchColumn();
$recoveryRate  = ($recoveredCount + $diedCount) > 0 ? round(($recoveredCount / ($recoveredCount + $diedCount)) * 100) : null;
$outcomeRows   = $pdo->query("SELECT outcome, COUNT(*) AS cnt FROM health_records GROUP BY outcome")->fetchAll(PDO::FETCH_KEY_PAIR);
$outcomeTotal  = array_sum($outcomeRows);
$topDisease    = $pdo->query("SELECT disease_name, COUNT(*) AS cnt FROM health_records WHERE disease_name IS NOT NULL AND disease_name<>'' GROUP BY disease_name ORDER BY cnt DESC LIMIT 1")->fetch();

// ── Mid-row data ──────────────────────────────────────────────
$criticalCount = $highImpact;
$alertList = $pdo->query("
    SELECT hr.*, f.flock_name, f.bird_type, DATEDIFF(CURDATE(), hr.record_date) AS days_ago
    FROM health_records hr JOIN flocks f ON f.id=hr.flock_id
    WHERE hr.status IN ('active','monitoring')
    ORDER BY hr.record_date DESC LIMIT 6")->fetchAll();

$trendData = $pdo->query("
    SELECT DATE(record_date) AS d, COUNT(*) AS cnt
    FROM health_records
    WHERE record_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(record_date) ORDER BY d")->fetchAll();

$diseaseBreakdown = $pdo->query("
    SELECT disease_name, COUNT(*) AS cnt, SUM(affected_count) AS birds
    FROM health_records
    WHERE disease_name IS NOT NULL AND disease_name<>''
    GROUP BY disease_name ORDER BY cnt DESC LIMIT 8")->fetchAll();

$flockHealth = $pdo->query("
    SELECT f.flock_name, f.quantity, f.bird_type, f.id AS flock_id,
           COUNT(hr.id) AS total_cases,
           SUM(CASE WHEN hr.status IN ('active','monitoring') THEN 1 ELSE 0 END) AS active_cases,
           SUM(CASE WHEN hr.outcome='died' THEN hr.affected_count ELSE 0 END) AS deaths,
           SUM(CASE WHEN hr.status IN ('active','monitoring') THEN hr.affected_count ELSE 0 END) AS currently_affected
    FROM flocks f
    LEFT JOIN health_records hr ON hr.flock_id=f.id
    WHERE f.status='active'
    GROUP BY f.id
    ORDER BY active_cases DESC, f.flock_name")->fetchAll();

$flocks = $pdo->query("SELECT * FROM flocks WHERE status='active' ORDER BY flock_name")->fetchAll();

// ── Badges ────────────────────────────────────────────────────
function outcomeBadge(string $o): string {
    $m = ['recovered'=>['#dcfce7','#15803d','fa-check-circle'],
          'died'     =>['#fee2e2','#991b1b','fa-skull'],
          'pending'  =>['#fef3c7','#92400e','fa-clock'],
          'ongoing'  =>['#dbeafe','#1e40af','fa-spinner'],
          'improving'=>['#f3e8ff','#6b21a8','fa-arrow-up']];
    [$bg,$c,$icon] = $m[$o] ?? ['#f1f5f9','#475569','fa-circle'];
    return "<span style='display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:50px;font-size:11px;font-weight:700;background:{$bg};color:{$c};'><i class='fas {$icon}' style='font-size:9px;'></i>".ucfirst($o)."</span>";
}
function statusBadge(string $s): string {
    if ($s === 'closed') $s = 'completed';
    $m = ['active'    =>['#fee2e2','#991b1b'],
          'monitoring'=>['#fef3c7','#92400e'],
          'completed' =>['#dcfce7','#15803d']];
    [$bg,$c] = $m[$s] ?? ['#f1f5f9','#475569'];
    return "<span style='display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:50px;font-size:11px;font-weight:700;background:{$bg};color:{$c};'>".ucfirst($s)."</span>";
}
function typeBadge(string $t): string {
    $m = ['disease'=>['#fee2e2','#991b1b','🦠'],'injury'=>['#ffedd5','#9a3412','🩹'],
          'checkup'=>['#dbeafe','#1e40af','🩺'],'treatment'=>['#f3e8ff','#6b21a8','💊'],
          'vaccination'=>['#dcfce7','#15803d','💉']];
    [$bg,$c,$ico] = $m[$t] ?? ['#f1f5f9','#475569','📋'];
    return "<span style='display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:50px;font-size:11px;font-weight:700;background:{$bg};color:{$c};'>{$ico} ".ucfirst($t)."</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Health Records — Coopers Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../public/css/admin.css?v=8">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
<?php include 'includes/topbar.php'; ?>
<div class="dashboard-content">

<!-- Header -->
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
  <div>
    <h1>Health Records</h1>
    <p>Disease tracking, treatment monitoring and flock health intelligence</p>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('addHealthModal').classList.add('open')">
    <i class="fas fa-plus"></i> Add Health Record
  </button>
</div>

<?php if($urlMsg): [$t,$tx]=explode(':',$urlMsg,2); ?>
<div class="alert alert-<?php echo $t; ?>" style="margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($tx); ?></div>
<?php endif; ?>

<!-- ── KPI Strip (every card is a filter) ───────────────── -->
<div class="kpi-strip health-kpis">
  <a class="kpi kpi-red" href="?status=active">
    <i class="fas fa-heartbeat kpi-ico"></i>
    <div class="kpi-lbl">Active Cases</div>
    <div class="kpi-val" style="color:<?php echo $activeCount>0?'#ef4444':'#22c55e'; ?>"><?php echo $activeCount; ?></div>
    <div class="kpi-sub"><?php echo $monitorCount; ?> under monitoring</div>
  </a>
  <a class="kpi kpi-orange" href="?status=active&outcome=pending">
    <i class="fas fa-fire kpi-ico"></i>
    <div class="kpi-lbl">High-Impact</div>
    <div class="kpi-val" style="color:<?php echo $highImpact>0?'#f59e0b':'#22c55e'; ?>"><?php echo $highImpact; ?></div>
    <div class="kpi-sub">5+ birds or 5% of a flock</div>
  </a>
  <a class="kpi kpi-blue" href="?status=completed">
    <i class="fas fa-check-circle kpi-ico"></i>
    <div class="kpi-lbl">Completed</div>
    <div class="kpi-val"><?php echo $completedCount; ?></div>
    <div class="kpi-sub"><?php echo $recoveryRate!==null ? $recoveryRate.'% recovery rate' : 'No outcomes yet'; ?></div>
  </a>
  <a class="kpi kpi-purple" href="?type=disease">
    <i class="fas fa-kiwi-bird kpi-ico"></i>
    <div class="kpi-lbl">Birds At Risk</div>
    <div class="kpi-val"><?php echo number_format($birdsAtRisk); ?></div>
    <div class="kpi-sub"><?php echo number_format($deathsFromHealth); ?> deaths from health cases</div>
  </a>
  <a class="kpi kpi-green" href="?">
    <i class="fas fa-calendar kpi-ico"></i>
    <div class="kpi-lbl">This Month</div>
    <div class="kpi-val"><?php echo $thisMonthCases; ?></div>
    <div class="kpi-sub">New records in <?php echo date('M Y'); ?></div>
  </a>
</div>

<!-- ── Insight strip: outcome mix (click a segment to filter) ── -->
<div class="health-insight">
  <div class="hi-card hi-wide">
    <div class="hi-title">Outcome mix <span class="hi-sub"><?php echo $outcomeTotal; ?> records</span></div>
    <?php if($outcomeTotal > 0):
      $outcomeColors = ['pending'=>'#f59e0b','ongoing'=>'#3b82f6','improving'=>'#a855f7','recovered'=>'#22c55e','died'=>'#ef4444'];
    ?>
    <div class="outcome-bar">
      <?php foreach($outcomeColors as $oc => $clr):
        $cnt = (int)($outcomeRows[$oc] ?? 0);
        if ($cnt === 0) continue;
        $w = round(($cnt / $outcomeTotal) * 100, 1);
      ?>
      <a class="outcome-seg" href="?outcome=<?php echo $oc; ?>" style="width:<?php echo $w; ?>%;background:<?php echo $clr; ?>;" title="<?php echo ucfirst($oc); ?>: <?php echo $cnt; ?>"></a>
      <?php endforeach; ?>
    </div>
    <div class="outcome-legend">
      <?php foreach($outcomeColors as $oc => $clr): $cnt=(int)($outcomeRows[$oc] ?? 0); ?>
      <a href="?outcome=<?php echo $oc; ?>"><span class="dot" style="background:<?php echo $clr; ?>;"></span><?php echo ucfirst($oc); ?> <strong><?php echo $cnt; ?></strong></a>
      <?php endforeach; ?>
    </div>
    <?php else: ?><div class="hi-empty">No health records yet.</div><?php endif; ?>
  </div>
  <div class="hi-card">
    <div class="hi-title">Top disease</div>
    <?php if($topDisease): ?>
      <div class="hi-big"><?php echo htmlspecialchars($topDisease['disease_name']); ?></div>
      <div class="hi-sub"><?php echo (int)$topDisease['cnt']; ?> case<?php echo $topDisease['cnt']>1?'s':''; ?> recorded</div>
    <?php else: ?><div class="hi-empty">Record a disease to see trends.</div><?php endif; ?>
  </div>
  <div class="hi-card">
    <div class="hi-title">Recovery vs deaths</div>
    <div class="hi-big" style="color:<?php echo ($recoveryRate ?? 0)>=80?'#15803d':'#b45309'; ?>"><?php echo $recoveryRate!==null ? $recoveryRate.'%' : '—'; ?></div>
    <div class="hi-sub"><?php echo $recoveredCount; ?> recovered · <?php echo $diedCount; ?> died</div>
  </div>
</div>

<!-- ── Mid row: Alerts + Chart ────────────────── -->
<div class="mid-row">

  <!-- Health Alerts panel -->
  <div class="alerts-card">
    <div class="alerts-head <?php echo empty($alertList)?'clean':''; ?>">
      <h3>
        <?php if(empty($alertList)): ?>
          <i class="fas fa-shield-alt" style="color:#22c55e;"></i> All Flocks Healthy
        <?php else: ?>
          <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i>
          Active Cases
          <span class="alert-count"><?php echo count($alertList); ?></span>
        <?php endif; ?>
      </h3>
      <?php if(!empty($alertList)): ?>
      <a href="?status=active" class="view-all-link">View all →</a>
      <?php endif; ?>
    </div>

    <?php if(empty($alertList)): ?>
    <div class="no-alerts">
      <i class="fas fa-check-circle" style="font-size:32px;color:#4ade80;margin-bottom:8px;display:block;"></i>
      No active health cases right now.
    </div>
    <?php else: ?>
    <?php foreach($alertList as $a):
      $daysAgo = (int)$a['days_ago'];
      $dot = $a['outcome']==='died' ? '#ef4444' : ($daysAgo<=1 ? '#ef4444' : ($daysAgo<=3 ? '#f59e0b' : '#3b82f6'));
      $typeEmoji = ['disease'=>'🦠','injury'=>'🩹','checkup'=>'🩺','treatment'=>'💊'][$a['type']]??'📋';
    ?>
    <div class="alert-row">
      <div class="alert-dot" style="background:<?php echo $dot; ?>;box-shadow:0 0 0 3px <?php echo $dot; ?>22;"></div>
      <div class="alert-info">
        <div class="alert-name"><?php echo htmlspecialchars($a['flock_name']); ?></div>
        <div class="alert-sub"><?php echo $typeEmoji; ?> <?php echo htmlspecialchars($a['disease_name']??'Health issue'); ?> · <?php echo number_format($a['affected_count']); ?> birds<?php echo $daysAgo===0?' · Today':($daysAgo===1?' · Yesterday':" · {$daysAgo}d ago"); ?></div>
      </div>
      <div class="alert-actions" onclick="event.stopPropagation()">
        <button class="act-btn" style="background:var(--primary);color:white;border-color:var(--primary);"
          onclick="openUpdateModal(<?php echo $a['id']; ?>,'<?php echo $a['outcome']; ?>','<?php echo $a['status']; ?>')"
          title="Update record"><i class="fas fa-edit"></i></button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Disease trend chart -->
  <div class="chart-card">
    <div class="chart-card-title">Disease Cases — Last 30 Days</div>
    <div class="chart-card-sub">Daily health records logged</div>
    <canvas id="trendChart" height="130"></canvas>
  </div>

</div>

<!-- ── Flock health summary + Disease breakdown ─ -->
<div class="bottom-row">

  <!-- Flock health rate table -->
  <div class="flock-health-card">
    <div class="fh-title">Health by Flock</div>
    <?php foreach($flockHealth as $fh):
      $affected  = (int)$fh['currently_affected'];
      $healthRate= $fh['quantity']>0 ? max(0, round((($fh['quantity']-$affected)/$fh['quantity'])*100,1)) : 100;
      $barClr    = $healthRate>=95?'#22c55e':($healthRate>=80?'#f59e0b':'#ef4444');
      $typeEmoji = ['layer'=>'🐔','broiler'=>'🍗','cockerel'=>'🐓','turkey'=>'🦃'][$fh['bird_type']]??'🐦';
    ?>
    <div class="fh-row">
      <div class="fh-name">
        <?php echo $typeEmoji; ?> <?php echo htmlspecialchars($fh['flock_name']); ?>
        <?php if($fh['active_cases']>0): ?><span class="fh-badge"><?php echo $fh['active_cases']; ?> active</span><?php endif; ?>
      </div>
      <div class="fh-bar-wrap">
        <div class="fh-bar"><div class="fh-fill" style="width:<?php echo $healthRate; ?>%;background:<?php echo $barClr; ?>;"></div></div>
        <span class="fh-pct" style="color:<?php echo $barClr; ?>;"><?php echo $healthRate; ?>%</span>
      </div>
      <div class="fh-meta">
        <?php echo number_format($fh['quantity']); ?> birds ·
        <?php echo $affected>0 ? number_format($affected).' affected' : 'All healthy'; ?>
        <?php if($fh['deaths']>0): ?> · <span style="color:#ef4444;"><?php echo number_format($fh['deaths']); ?> deaths</span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($flockHealth)): ?>
    <div style="text-align:center;color:#9ca3af;padding:20px;font-size:13px;">No active flocks</div>
    <?php endif; ?>
  </div>

  <!-- Disease breakdown -->
  <div class="disease-card">
    <div class="fh-title">Cases by Disease</div>
    <?php if(empty($diseaseBreakdown)): ?>
    <div style="text-align:center;color:#9ca3af;padding:20px;font-size:13px;">No disease data</div>
    <?php else:
      $maxCnt = max(array_column($diseaseBreakdown,'cnt'));
      foreach($diseaseBreakdown as $db):
        $pct = $maxCnt > 0 ? round(($db['cnt']/$maxCnt)*100) : 0;
    ?>
    <div class="db-row">
      <div class="db-name"><?php echo htmlspecialchars($db['disease_name']); ?></div>
      <div class="db-bar-wrap">
        <div class="db-bar"><div class="db-fill" style="width:<?php echo $pct; ?>%;"></div></div>
        <span class="db-cnt"><?php echo $db['cnt']; ?></span>
      </div>
      <div class="db-birds"><?php echo number_format($db['birds']); ?> birds</div>
    </div>
    <?php endforeach; endif; ?>
  </div>

</div>

<!-- ── Filter bar ─────────────────────────────── -->
<div class="filter-bar">
  <a class="fchip <?php echo !$statFilter&&!$flockFilter&&!$typeFilter&&!$search?'active':''; ?>" href="health.php">All</a>
  <a class="fchip danger <?php echo $statFilter==='active'?'active':''; ?>"     href="?status=active">🔴 Active<?php if($activeCount>0): ?> (<?php echo $activeCount; ?>)<?php endif; ?></a>
  <a class="fchip amber <?php echo $statFilter==='monitoring'?'active':''; ?>"  href="?status=monitoring">🟡 Monitoring</a>
  <a class="fchip <?php echo $statFilter==='completed'?'active':''; ?>"            href="?status=completed">✅ Completed</a>
  <div class="fchip-divider"></div>
  <a class="fchip <?php echo $typeFilter==='disease'?'active':''; ?>"   href="?type=disease">🦠 Disease</a>
  <a class="fchip <?php echo $typeFilter==='injury'?'active':''; ?>"    href="?type=injury">🩹 Injury</a>
  <a class="fchip <?php echo $typeFilter==='checkup'?'active':''; ?>"   href="?type=checkup">🩺 Checkup</a>
  <a class="fchip <?php echo $typeFilter==='treatment'?'active':''; ?>" href="?type=treatment">💊 Treatment</a>
  <div class="fchip-divider"></div>
  <select class="fld-ctrl" onchange="window.location='?flock_id='+this.value" style="width:170px;">
    <option value="">All Flocks</option>
    <?php foreach($flocks as $fl): ?>
    <option value="<?php echo $fl['id']; ?>" <?php echo $flockFilter==$fl['id']?'selected':''; ?>>
      <?php echo htmlspecialchars($fl['flock_name']); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="text" class="fld-ctrl" placeholder="🔍 Search disease, flock, treatment..."
         oninput="healthLiveFilter(this.value)" style="margin-left:auto;width:230px;">
  <?php if($statFilter||$flockFilter||$typeFilter||$search): ?>
  <a href="health.php" class="btn btn-secondary btn-sm">Clear</a>
  <?php endif; ?>
</div>

<!-- ── Records table ──────────────────────────── -->
<div class="card" style="overflow:hidden;">
  <div style="padding:13px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
    <div style="font-size:15px;font-weight:700;">
      Health Records
      <span style="font-size:13px;color:#9ca3af;font-weight:400;margin-left:6px;">(<?php echo count($records); ?>)</span>
    </div>
    <span style="font-size:12px;color:#9ca3af;">Click row to view full details</span>
  </div>
  <div style="overflow-x:auto;">
  <table class="health-table" id="healthTable">
    <thead>
      <tr>
        <th>Date</th>
        <th>Flock</th>
        <th>Type</th>
        <th>Disease / Issue</th>
        <th>Affected</th>
        <th>Health Rate</th>
        <th>Treatment</th>
        <th>Treated By</th>
        <th>Outcome</th>
        <th>Status</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($records as $r):
      $rowCls = $r['status']==='active' ? 'row-active' : ($r['status']==='monitoring'?'row-monitoring':'');
      $affected = (int)$r['affected_count'];
      $healthRate = $r['flock_qty']>0 ? max(0,round((($r['flock_qty']-$affected)/$r['flock_qty'])*100,1)) : null;
      $typeEmoji = ['layer'=>'🐔','broiler'=>'🍗','cockerel'=>'🐓','turkey'=>'🦃'][$r['bird_type']]??'🐦';
    ?>
    <tr class="<?php echo $rowCls; ?> health-row"
        data-search="<?php echo strtolower(htmlspecialchars($r['flock_name'].' '.($r['disease_name']??'').' '.($r['treatment']??''))); ?>"
        onclick="openDetailDrawer(<?php echo htmlspecialchars(json_encode($r),ENT_QUOTES); ?>)">

      <td style="font-size:12px;color:#6b7280;white-space:nowrap;"><?php echo date('M d, Y',strtotime($r['record_date'])); ?></td>

      <td>
        <strong><?php echo htmlspecialchars($r['flock_name']); ?></strong>
        <br><span style="font-size:11px;color:#9ca3af;"><?php echo $typeEmoji; ?> <?php echo ucfirst($r['bird_type']); ?></span>
      </td>

      <td><?php echo typeBadge($r['type']); ?></td>

      <td style="font-weight:700;max-width:160px;">
        <?php echo htmlspecialchars($r['disease_name']?:($r['description']??'—')); ?>
      </td>

      <td style="text-align:center;font-weight:700;color:<?php echo $affected>0?'#ef4444':'#22c55e'; ?>;">
        <?php echo $affected>0?number_format($affected):'—'; ?>
      </td>

      <td>
        <?php if($healthRate!==null): ?>
        <div style="display:flex;align-items:center;gap:5px;">
          <div class="pbar-mini">
            <div class="pbar-mini-fill" style="width:<?php echo $healthRate; ?>%;background:<?php echo $healthRate>=90?'#22c55e':($healthRate>=70?'#f59e0b':'#ef4444'); ?>;"></div>
          </div>
          <span style="font-size:11px;font-weight:700;color:<?php echo $healthRate>=90?'#22c55e':($healthRate>=70?'#f59e0b':'#ef4444'); ?>"><?php echo $healthRate; ?>%</span>
        </div>
        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
      </td>

      <td style="font-size:12px;color:#6b7280;max-width:140px;">
        <?php echo htmlspecialchars(substr($r['treatment']??'',0,55)); ?>
        <?php if(strlen($r['treatment']??'')>55): ?>…<?php endif; ?>
      </td>

      <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($r['treated_by']??'—'); ?></td>

      <td>
        <?php echo outcomeBadge($r['outcome']??'pending'); ?>
        <?php if($r['outcome']==='died'): ?>
        <br><span style="font-size:10px;color:#ef4444;font-weight:700;"><i class="fas fa-skull"></i> Mortality logged</span>
        <?php endif; ?>
      </td>

      <td><?php echo statusBadge($r['status']??'active'); ?></td>

      <td onclick="event.stopPropagation()" style="text-align:right;">
        <button class="act-btn" style="background:var(--primary);color:white;border-color:var(--primary);"
          onclick="openUpdateModal(<?php echo $r['id']; ?>,'<?php echo $r['outcome']; ?>','<?php echo $r['status']; ?>')"
          title="Update record"><i class="fas fa-edit"></i></button>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($records)): ?>
    <tr><td colspan="11" style="text-align:center;padding:48px;color:#9ca3af;">
      <i class="fas fa-heartbeat" style="font-size:40px;color:#e5e7eb;display:block;margin-bottom:12px;"></i>
      <strong style="display:block;margin-bottom:4px;">No health records found</strong>
      <a href="health.php" style="color:var(--primary);font-size:13px;">Clear filters →</a>
    </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div>
</div>

<!-- ── Detail Drawer ──────────────────────────── -->
<div class="drawer-overlay" id="detailOverlay" onclick="healthCloseDrawer()"></div>
<div class="drawer" id="detailDrawer">
  <div class="dhead" style="background:var(--primary);color:white;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
    <h3 style="font-size:15px;font-weight:700;margin:0;color:white;" id="drawerTitle">Health Record</h3>
    <button style="background:rgba(255,255,255,.2);border:none;color:white;width:30px;height:30px;border-radius:6px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;" onclick="healthCloseDrawer()"><i class="fas fa-times"></i></button>
  </div>
  <div style="padding:20px 22px;flex:1;overflow-y:auto;" id="drawerBody">
    <div style="text-align:center;padding:40px;color:#9ca3af;"><i class="fas fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;"></i>Loading…</div>
  </div>
</div>

<!-- ── Add Health Record Modal ────────────────── -->
<div class="modal-overlay" id="addHealthModal">
  <div class="modal" style="max-width:700px;">
    <div class="modal-header">
      <h3><i class="fas fa-heartbeat" style="color:#ef4444;margin-right:8px;"></i>Add Health Record</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_record" value="1">
      <div style="padding:16px 32px 0;">
        <div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">Flock &amp; Case</div>
        <div class="form-row">
          <div class="form-group">
            <label>Flock *</label>
            <select name="flock_id" class="form-control" required>
              <option value="">Select flock…</option>
              <?php foreach($flocks as $fl): ?>
              <option value="<?php echo $fl['id']; ?>"><?php echo htmlspecialchars($fl['flock_name']); ?> (<?php echo ucfirst($fl['bird_type']); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Record Date *</label>
            <input type="date" name="record_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Type *</label>
            <select name="type" class="form-control" required>
              <option value="disease">🦠 Disease</option>
              <option value="injury">🩹 Injury</option>
              <option value="checkup">🩺 Checkup</option>
              <option value="treatment">💊 Treatment</option>
            </select>
          </div>
          <div class="form-group">
            <label>Disease / Issue *</label>
            <input type="text" name="disease_name" class="form-control" required
                   placeholder="e.g. Newcastle, Coccidiosis" list="diseaseList">
            <datalist id="diseaseList">
              <option value="Newcastle Disease"><option value="Gumboro (IBD)">
              <option value="Fowl Pox"><option value="Coccidiosis">
              <option value="Respiratory Infection"><option value="Salmonellosis">
              <option value="Avian Influenza"><option value="Marek's Disease">
              <option value="Heat Stress"><option value="Worm Infestation">
            </datalist>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Birds Affected</label>
            <input type="number" name="affected_count" class="form-control" min="0" value="0">
          </div>
          <div class="form-group">
            <label>Severity</label>
            <select name="severity" class="form-control" id="addSeverity">
              <option value="low">🟡 Low</option>
              <option value="moderate" selected>🟠 Moderate</option>
              <option value="high">🔴 High</option>
              <option value="critical">🚨 Critical</option>
            </select>
          </div>
        </div>
        <div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin:14px 0 10px;">Clinical Details</div>
        <div class="form-group">
          <label>Symptoms Observed</label>
          <textarea name="symptoms" class="form-control" rows="2" placeholder="Describe visible symptoms…"></textarea>
        </div>
        <div class="form-group">
          <label>Treatment Given</label>
          <textarea name="treatment" class="form-control" rows="2" placeholder="Medications, dosage, procedure…"></textarea>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Treated By</label>
            <input type="text" name="treated_by" class="form-control" placeholder="Vet or farm worker name">
          </div>
          <div class="form-group">
            <label>Outcome</label>
            <select name="outcome" class="form-control" id="addOutcome">
              <option value="pending">⏳ Pending</option>
              <option value="ongoing">🔄 Ongoing</option>
              <option value="improving">📈 Improving</option>
              <option value="recovered">✅ Recovered</option>
              <option value="died">💀 Died</option>
            </select>
          </div>
        </div>
        <div id="addMortalityWarning" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#991b1b;margin-bottom:10px;">
          <i class="fas fa-skull"></i> <strong>Auto-logs to Mortality!</strong> Deaths will be recorded and flock count deducted automatically.
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Additional observations, follow-up plan…"></textarea>
        </div>
        <input type="hidden" name="description" value="">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Health Record</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Update Record Modal ────────────────────── -->
<div class="modal-overlay" id="updateModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header">
      <h3><i class="fas fa-edit" style="color:var(--primary);margin-right:8px;"></i>Update Health Record</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="update_record" value="1">
      <input type="hidden" name="record_id" id="uRecordId">
      <div style="padding:0 32px;">
        <div class="form-row" style="margin-top:16px;">
          <div class="form-group">
            <label>Outcome</label>
            <select name="outcome" id="uOutcome" class="form-control" onchange="checkDiedOutcome(this)">
              <option value="pending">⏳ Pending</option>
              <option value="ongoing">🔄 Ongoing</option>
              <option value="improving">📈 Improving</option>
              <option value="recovered">✅ Recovered</option>
              <option value="died">💀 Died</option>
            </select>
          </div>
          <div class="form-group">
            <label>Status <small style="color:#9ca3af;text-transform:none;">(set from outcome)</small></label>
            <select name="status" id="uStatus" class="form-control">
              <option value="active">🔴 Active</option>
              <option value="monitoring">🟡 Monitoring</option>
              <option value="completed">✅ Completed</option>
            </select>
          </div>
        </div>
        <div id="updateMortWarning" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#991b1b;margin-bottom:10px;">
          <i class="fas fa-skull"></i> <strong>Mortality will be auto-logged!</strong> Birds will be deducted from flock count.
        </div>
        <div class="form-group">
          <label>Update Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="What changed, next steps…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Record</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<?php

$pageData = ['trendData'=>$trendData];
?>
<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="../public/js/admin.js?v=8"></script>

</body>
</html>
