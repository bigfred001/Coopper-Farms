<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

$CAUSES = ['Disease','Heat stress','Predator attack','Injury / trauma','Starvation / dehydration','Unknown','Other'];

// ── POST: log deaths ──────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_mortality'])) {
    $flockId   = (int)($_POST['flock_id'] ?? 0);
    $count     = (int)($_POST['count'] ?? 0);
    $logDate   = $_POST['log_date'] ?? date('Y-m-d');
    $causeSel  = $_POST['cause'] ?? 'Unknown';
    $causeText = sanitize($_POST['cause_other'] ?? '');
    $cause     = ($causeSel === 'Other' && $causeText !== '') ? $causeText : $causeSel;
    $notes     = sanitize($_POST['notes'] ?? '');

    $fs = $pdo->prepare("SELECT id, flock_name, quantity, arrival_date, status FROM flocks WHERE id=?");
    $fs->execute([$flockId]);
    $flock = $fs->fetch();

    if (!$flock)                                  $msg = 'error:Please choose a valid flock.';
    elseif (!in_array($flock['status'], ['active','quarantine'], true))
                                                  $msg = "error:{$flock['flock_name']} is {$flock['status']} — deaths can only be logged for live flocks.";
    elseif ($count < 1)                           $msg = 'error:Enter at least 1 death.';
    elseif ($count > (int)$flock['quantity'])     $msg = "error:Only {$flock['quantity']} birds are alive in {$flock['flock_name']}. You cannot log {$count} deaths.";
    elseif ($logDate > date('Y-m-d'))             $msg = 'error:The date cannot be in the future.';
    elseif ($logDate < $flock['arrival_date'])    $msg = 'error:The date is before this flock arrived on '.date('M d, Y', strtotime($flock['arrival_date'])).'.';
    else {
        // Atomic: only deduct if enough live birds remain at the moment of writing
        $pdo->beginTransaction();
        $dec = $pdo->prepare("UPDATE flocks SET quantity = quantity - ? WHERE id=? AND quantity >= ?");
        $dec->execute([$count, $flockId, $count]);
        if ($dec->rowCount() === 0) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "error:The live bird count changed. Only the remaining birds can be logged — please refresh and try again.";
        } else {
            $pdo->prepare("INSERT INTO mortality_logs (flock_id, log_date, count, cause, notes, recorded_by) VALUES (?,?,?,?,?,?)")
                ->execute([$flockId, $logDate, $count, $cause, $notes, $_SESSION['user_id']]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE flocks SET status='depleted' WHERE id=? AND quantity<=0")->execute([$flockId]);
            $pdo->commit();
            auditLog($pdo, 'log_mortality', 'mortality', $newId,
                "Logged {$count} death(s) in {$flock['flock_name']} on {$logDate}. Cause: {$cause}");
            if ($count >= MORTALITY_ALERT_THRESHOLD) {
                $mailer->sendAdminMortalityAlert($flock['flock_name'], $count, $cause);
            }
            $left = (int)$flock['quantity'] - $count;
            $msg = "success:{$count} death(s) logged. {$flock['flock_name']} now has {$left} live bird(s).";
        }
    }
    header('Location: mortality.php?msg=' . urlencode($msg)); exit;
}

// ── Filters for the log table ─────────────────────────────────
$urlMsg      = $_GET['msg']    ?? '';
$flockFilter = (int)($_GET['flock_id'] ?? 0);
$causeFilter = $_GET['cause']  ?? '';
$rangeFilter = $_GET['range']  ?? '';   // 7 | 30 | 90 | ''

$where = "WHERE 1=1"; $params = [];
if ($flockFilter) { $where .= " AND ml.flock_id=?"; $params[] = $flockFilter; }
if ($causeFilter) { $where .= " AND ml.cause=?";    $params[] = $causeFilter; }
if (in_array($rangeFilter, ['7','30','90'], true)) {
    $where .= " AND ml.log_date >= DATE_SUB(CURDATE(), INTERVAL ".(int)$rangeFilter." DAY)";
}

$logStmt = $pdo->prepare("
    SELECT ml.*, f.flock_name, f.bird_type, u.name AS recorded_name
    FROM mortality_logs ml
    JOIN flocks f ON f.id = ml.flock_id
    LEFT JOIN users u ON u.id = ml.recorded_by
    $where
    ORDER BY ml.log_date DESC, ml.id DESC
    LIMIT 200");
$logStmt->execute($params);
$logs = $logStmt->fetchAll();

// ── Live flocks (available birds) ─────────────────────────────
$liveFlocks = $pdo->query("SELECT id, flock_name, bird_type, quantity, arrival_date, status, housing_unit
    FROM flocks WHERE status IN ('active','quarantine') ORDER BY flock_name")->fetchAll();
$birdsAlive = array_sum(array_column($liveFlocks, 'quantity'));

// ── KPIs ──────────────────────────────────────────────────────
$deaths30    = (int)$pdo->query("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$deathsMonth = (int)$pdo->query("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE MONTH(log_date)=MONTH(CURDATE()) AND YEAR(log_date)=YEAR(CURDATE())")->fetchColumn();
$deathsAll   = (int)$pdo->query("SELECT COALESCE(SUM(count),0) FROM mortality_logs")->fetchColumn();
$rate30      = ($birdsAlive + $deaths30) > 0 ? round(($deaths30 / ($birdsAlive + $deaths30)) * 100, 2) : 0;
$causes      = $pdo->query("SELECT COALESCE(NULLIF(cause,''),'Unknown') AS cause, SUM(count) AS total, COUNT(*) AS events
    FROM mortality_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY cause ORDER BY total DESC")->fetchAll();
$topCause    = $causes[0] ?? null;

// Per-flock mortality (30-day rate vs what is alive now)
$flockRows = $pdo->query("
    SELECT f.id, f.flock_name, f.bird_type, f.quantity, f.status,
           COALESCE(SUM(CASE WHEN ml.log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN ml.count END), 0) AS d30,
           COALESCE(SUM(ml.count), 0) AS dall,
           MAX(ml.log_date) AS last_date
    FROM flocks f
    LEFT JOIN mortality_logs ml ON ml.flock_id = f.id
    WHERE f.status IN ('active','quarantine')
    GROUP BY f.id
    ORDER BY d30 DESC, f.flock_name")->fetchAll();
foreach ($flockRows as &$fr) {
    $base = (int)$fr['quantity'] + (int)$fr['d30'];
    $fr['rate30'] = $base > 0 ? round(((int)$fr['d30'] / $base) * 100, 2) : 0;
    $fr['level']  = $fr['rate30'] > 5 ? 'critical' : ($fr['rate30'] > 2 ? 'watch' : 'ok');
}
unset($fr);
$flaggedCount = count(array_filter($flockRows, fn($r) => $r['level'] !== 'ok'));

// 30-day daily trend (zero-filled)
$trendRaw = $pdo->query("SELECT log_date AS d, SUM(count) AS c FROM mortality_logs
    WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY log_date")->fetchAll(PDO::FETCH_KEY_PAIR);
$trend = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trend[] = ['d' => $d, 'c' => (int)($trendRaw[$d] ?? 0)];
}
$maxCause = $topCause ? (int)$topCause['total'] : 0;

// Shared data for the page script (live form checks + chart)
$pageData = [
    'flocks' => array_map(fn($f) => ['id'=>(int)$f['id'], 'name'=>$f['flock_name'], 'qty'=>(int)$f['quantity'], 'arrival'=>$f['arrival_date']], $liveFlocks),
    'trend'  => $trend,
    'threshold' => (int)MORTALITY_ALERT_THRESHOLD,
];

function levelBadge(string $level): string {
    $m = ['critical'=>['#fee2e2','#991b1b','Critical'], 'watch'=>['#fef3c7','#92400e','Watch'], 'ok'=>['#dcfce7','#15803d','On track']];
    [$bg,$c,$l] = $m[$level] ?? $m['ok'];
    return "<span class='mort-pill' style='background:{$bg};color:{$c};'>{$l}</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Mortality — Coopers Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../public/css/admin.css?v=8">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
<?php include 'includes/topbar.php'; ?>
<div class="dashboard-content">

<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
  <div>
    <h1>Mortality</h1>
    <p>Track deaths against live bird counts, spot flocks that are losing birds faster than normal</p>
  </div>
  <button class="btn btn-primary" onclick="openMortModal()"><i class="fas fa-skull"></i> Log Deaths</button>
</div>

<?php if($urlMsg): [$t,$tx]=explode(':',$urlMsg,2); ?>
<div class="alert alert-<?php echo $t; ?>" style="margin-bottom:16px;"><i class="fas fa-<?php echo $t==='error'?'exclamation-circle':'check-circle'; ?>"></i> <?php echo htmlspecialchars($tx); ?></div>
<?php endif; ?>

<!-- KPI strip -->
<div class="kpi-strip mort-kpis">
  <div class="kpi kpi-red">
    <i class="fas fa-skull kpi-ico"></i>
    <div class="kpi-lbl">Deaths · 30 days</div>
    <div class="kpi-val" style="color:<?php echo $deaths30>0?'#ef4444':'#22c55e'; ?>"><?php echo number_format($deaths30); ?></div>
    <div class="kpi-sub"><?php echo $rate30; ?>% of flock over 30 days</div>
  </div>
  <div class="kpi kpi-orange">
    <i class="fas fa-calendar kpi-ico"></i>
    <div class="kpi-lbl">Deaths · this month</div>
    <div class="kpi-val"><?php echo number_format($deathsMonth); ?></div>
    <div class="kpi-sub"><?php echo date('F Y'); ?></div>
  </div>
  <div class="kpi kpi-green">
    <i class="fas fa-kiwi-bird kpi-ico"></i>
    <div class="kpi-lbl">Birds alive</div>
    <div class="kpi-val"><?php echo number_format($birdsAlive); ?></div>
    <div class="kpi-sub"><?php echo count($liveFlocks); ?> live flock<?php echo count($liveFlocks)!==1?'s':''; ?> · <?php echo number_format($deathsAll); ?> deaths all-time</div>
  </div>
  <div class="kpi kpi-purple">
    <i class="fas fa-notes-medical kpi-ico"></i>
    <div class="kpi-lbl">Top cause · 90d</div>
    <div class="kpi-val" style="font-size:20px;"><?php echo $topCause ? htmlspecialchars($topCause['cause']) : '—'; ?></div>
    <div class="kpi-sub"><?php echo $topCause ? number_format($topCause['total']).' birds' : 'No deaths logged'; ?></div>
  </div>
  <div class="kpi <?php echo $flaggedCount>0?'kpi-orange':'kpi-green'; ?>">
    <i class="fas fa-flag kpi-ico"></i>
    <div class="kpi-lbl">Flocks flagged</div>
    <div class="kpi-val" style="color:<?php echo $flaggedCount>0?'#f59e0b':'#22c55e'; ?>"><?php echo $flaggedCount; ?></div>
    <div class="kpi-sub">Above 2% (watch) or 5% (critical) in 30 days</div>
  </div>
</div>

<!-- Trend + causes -->
<div class="mort-mid">
  <div class="chart-card">
    <div class="chart-card-title">Daily deaths · last 30 days</div>
    <div class="chart-card-sub">Spikes above the alert threshold (<?php echo MORTALITY_ALERT_THRESHOLD; ?>) are sent to admins by email</div>
    <canvas id="mortTrend" height="120"></canvas>
  </div>
  <div class="chart-card">
    <div class="chart-card-title">Causes · last 90 days</div>
    <div class="chart-card-sub">Share of birds lost by cause</div>
    <?php if(empty($causes)): ?>
      <div class="mort-empty">No deaths logged in the last 90 days.</div>
    <?php else: foreach($causes as $c):
      $pct = $maxCause>0 ? round(($c['total']/$maxCause)*100) : 0; ?>
      <a class="cause-row" href="?cause=<?php echo urlencode($c['cause']); ?>">
        <span class="cause-name"><?php echo htmlspecialchars($c['cause']); ?></span>
        <span class="cause-bar"><span style="width:<?php echo $pct; ?>%;"></span></span>
        <span class="cause-num"><?php echo number_format($c['total']); ?></span>
      </a>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Per-flock mortality -->
<div class="card mort-card">
  <div class="mort-head">
    <div class="mort-title">Mortality by flock <span class="mort-count"><?php echo count($flockRows); ?></span></div>
    <span class="mort-hint">Rate = deaths in 30 days ÷ (birds alive + those deaths)</span>
  </div>
  <div style="overflow-x:auto;">
  <table class="data-table">
    <thead><tr>
      <th>Flock</th><th>Birds alive</th><th>Deaths 30d</th><th>Rate 30d</th><th>Deaths all-time</th><th>Last death</th><th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach($flockRows as $fr):
      $barClr = $fr['level']==='critical'?'#ef4444':($fr['level']==='watch'?'#f59e0b':'#22c55e'); ?>
      <tr>
        <td><strong><?php echo htmlspecialchars($fr['flock_name']); ?></strong><br><small style="color:#9ca3af;"><?php echo ucfirst($fr['bird_type']); ?> · <?php echo ucfirst($fr['status']); ?></small></td>
        <td style="font-weight:700;"><?php echo number_format($fr['quantity']); ?></td>
        <td style="font-weight:700;color:<?php echo $fr['d30']>0?'#ef4444':'#9ca3af'; ?>;"><?php echo number_format($fr['d30']); ?></td>
        <td>
          <div class="rate-wrap"><div class="rate-bar"><div style="width:<?php echo min(100,$fr['rate30']*10); ?>%;background:<?php echo $barClr; ?>;"></div></div>
          <span style="font-weight:700;color:<?php echo $barClr; ?>;"><?php echo $fr['rate30']; ?>%</span></div>
        </td>
        <td><?php echo number_format($fr['dall']); ?></td>
        <td style="color:#6b7280;font-size:12px;"><?php echo $fr['last_date'] ? date('M d, Y', strtotime($fr['last_date'])) : '—'; ?></td>
        <td><?php echo levelBadge($fr['level']); ?></td>
        <td style="text-align:right;">
          <button class="btn btn-sm btn-secondary" onclick="openMortModal(<?php echo (int)$fr['id']; ?>)"><i class="fas fa-plus"></i> Log</button>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if(empty($flockRows)): ?>
      <tr><td colspan="8" style="text-align:center;padding:30px;color:#9ca3af;">No live flocks.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Log table -->
<div class="filter-bar">
  <a class="fchip <?php echo !$flockFilter&&!$causeFilter&&!$rangeFilter?'active':''; ?>" href="mortality.php">All</a>
  <a class="fchip <?php echo $rangeFilter==='7'?'active':''; ?>" href="?range=7">7 days</a>
  <a class="fchip <?php echo $rangeFilter==='30'?'active':''; ?>" href="?range=30">30 days</a>
  <a class="fchip <?php echo $rangeFilter==='90'?'active':''; ?>" href="?range=90">90 days</a>
  <div class="fchip-divider"></div>
  <select class="fld-ctrl" onchange="location.href='?flock_id='+this.value+'&range=<?php echo urlencode($rangeFilter); ?>'" style="width:180px;">
    <option value="">All flocks</option>
    <?php foreach($liveFlocks as $lf): ?>
    <option value="<?php echo $lf['id']; ?>" <?php echo $flockFilter==$lf['id']?'selected':''; ?>><?php echo htmlspecialchars($lf['flock_name']); ?></option>
    <?php endforeach; ?>
  </select>
  <select class="fld-ctrl" onchange="location.href='?cause='+encodeURIComponent(this.value)+'&range=<?php echo urlencode($rangeFilter); ?>'" style="width:180px;">
    <option value="">All causes</option>
    <?php foreach($CAUSES as $cz): ?>
    <option value="<?php echo htmlspecialchars($cz); ?>" <?php echo $causeFilter===$cz?'selected':''; ?>><?php echo htmlspecialchars($cz); ?></option>
    <?php endforeach; ?>
  </select>
  <?php if($flockFilter||$causeFilter||$rangeFilter): ?><a href="mortality.php" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
</div>

<div class="card mort-card">
  <div class="mort-head">
    <div class="mort-title">Death log <span class="mort-count"><?php echo count($logs); ?></span></div>
    <span class="mort-hint">Newest first</span>
  </div>
  <div style="overflow-x:auto;">
  <table class="data-table">
    <thead><tr><th>Date</th><th>Flock</th><th>Deaths</th><th>Cause</th><th>Notes</th><th>Recorded by</th></tr></thead>
    <tbody>
    <?php foreach($logs as $l): ?>
      <tr>
        <td style="white-space:nowrap;font-size:12px;color:#6b7280;"><?php echo date('M d, Y', strtotime($l['log_date'])); ?></td>
        <td><strong><?php echo htmlspecialchars($l['flock_name']); ?></strong></td>
        <td><span class="death-num"><?php echo number_format($l['count']); ?></span></td>
        <td><?php echo htmlspecialchars($l['cause'] ?: 'Unknown'); ?></td>
        <td style="font-size:12px;color:#6b7280;max-width:260px;"><?php echo htmlspecialchars(mb_substr($l['notes'] ?? '', 0, 90)); ?></td>
        <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($l['recorded_name'] ?? '—'); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if(empty($logs)): ?>
      <tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">No deaths match these filters.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div>
</div>

<!-- Log deaths modal -->
<div class="modal-overlay" id="mortModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h3><i class="fas fa-skull" style="color:#ef4444;margin-right:8px;"></i>Log Deaths</h3>
      <button class="modal-close" onclick="closeMortModal()"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="mortForm">
      <input type="hidden" name="add_mortality" value="1">
      <div style="padding:16px 32px 0;">
        <div class="form-group">
          <label>Flock *</label>
          <select name="flock_id" id="mFlock" class="form-control" required>
            <option value="">Select flock…</option>
            <?php foreach($liveFlocks as $lf): ?>
            <option value="<?php echo $lf['id']; ?>"><?php echo htmlspecialchars($lf['flock_name']); ?> — <?php echo number_format($lf['quantity']); ?> alive</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="avail-box" id="mAvail">
          <div><span class="avail-lbl">Birds alive</span><strong id="mAliveNum">—</strong></div>
          <div><span class="avail-lbl">After this entry</span><strong id="mAfterNum">—</strong></div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Deaths *</label>
            <input type="number" name="count" id="mCount" class="form-control" min="1" required>
            <div class="form-hint" id="mCountHint">Choose a flock first</div>
          </div>
          <div class="form-group">
            <label>Date *</label>
            <input type="date" name="log_date" id="mDate" class="form-control" required value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
            <div class="form-hint" id="mDateHint"></div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Cause</label>
            <select name="cause" id="mCause" class="form-control">
              <?php foreach($CAUSES as $cz): ?><option value="<?php echo htmlspecialchars($cz); ?>" <?php echo $cz==='Unknown'?'selected':''; ?>><?php echo htmlspecialchars($cz); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="mCauseOtherWrap" style="display:none;">
            <label>Describe cause</label>
            <input type="text" name="cause_other" class="form-control" placeholder="e.g. Snake bite">
          </div>
        </div>

        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Post-mortem findings, what was seen…"></textarea>
        </div>
        <div class="form-hint" id="mWarn" style="color:#991b1b;display:none;margin-bottom:8px;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeMortModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="mSubmit"><i class="fas fa-save"></i> Log Deaths</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
