<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

// A dose's due date: scheduled rows keep the planned date in date_given; follow-ups may use next_due_date.
$dueSql  = "COALESCE(v.next_due_date, v.date_given)";
// Open doses are the ones still owed birds (not completed, not partly-recorded sessions)
$openSql = "v.status IN ('scheduled','overdue')";
$effSql  = "CASE WHEN v.status='completed' THEN 'completed'
                 WHEN v.status='in_progress' THEN 'in_progress'
                 WHEN $dueSql < CURDATE() THEN 'overdue'
                 ELSE 'scheduled' END";

// Keep stored status in step with the due date (used by other pages)
$pdo->exec("UPDATE vaccinations SET status='overdue' WHERE status='scheduled' AND COALESCE(next_due_date,date_given) < CURDATE()");
$pdo->exec("UPDATE vaccinations SET status='scheduled' WHERE status='overdue' AND COALESCE(next_due_date,date_given) >= CURDATE()");

function lotNote(string $notes, ?string $expiry): string {
    return ($expiry && $expiry <= date('Y-m-d')) ? trim($notes."\n[Expired lot used — user confirmed]") : $notes;
}
function vaccFlock(PDO $pdo, int $flockId): ?array {
    $s = $pdo->prepare("SELECT id, flock_name, quantity FROM flocks WHERE id=?");
    $s->execute([$flockId]);
    return $s->fetch() ?: null;
}
// Birds already vaccinated in the open round (partial sessions) for this flock and vaccine
function vaccRoundDone(PDO $pdo, int $flockId, string $vaccine): int {
    $s = $pdo->prepare("SELECT COALESCE(SUM(birds_vaccinated),0) FROM vaccinations
        WHERE flock_id=? AND LOWER(vaccine_name)=LOWER(?) AND status='in_progress'");
    $s->execute([$flockId, $vaccine]);
    return (int)$s->fetchColumn();
}
// Round reached 100%: its partial sessions become completed
function vaccCloseRound(PDO $pdo, int $flockId, string $vaccine): void {
    $pdo->prepare("UPDATE vaccinations SET status='completed'
        WHERE flock_id=? AND LOWER(vaccine_name)=LOWER(?) AND status='in_progress'")
        ->execute([$flockId, $vaccine]);
}

// ── POST handlers ─────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Record a vaccination session (may be partial)
    if (isset($_POST['add_vaccination'])) {
        $flockId   = (int)($_POST['flock_id'] ?? 0);
        $vName     = sanitize($_POST['vaccine_name'] ?? '');
        $dateGiven = $_POST['date_given'] ?? date('Y-m-d');
        $birds     = (int)($_POST['birds_vaccinated'] ?? 0);
        $nextDue   = !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : null;
        $lot       = sanitize($_POST['lot_number'] ?? '');
        $expiry    = !empty($_POST['lot_expiry']) ? $_POST['lot_expiry'] : null;
        $method    = $_POST['method'] ?? 'drinking_water';
        $dosage    = sanitize($_POST['dosage'] ?? '');
        $flock     = vaccFlock($pdo, $flockId);

        if (!$flock || $vName === '')            $msg = 'error:Choose a flock and enter the vaccine.';
        elseif ($birds < 1)                      $msg = 'error:Enter how many birds were vaccinated (at least 1).';
        elseif ($expiry && $expiry <= date('Y-m-d') && empty($_POST['lot_override'])) $msg = 'error:This lot expired on '.date('M d, Y', strtotime($expiry)).' or expires today. Tick "Use this lot anyway" to record it.';
        else {
            $qty       = (int)$flock['quantity'];
            $done      = vaccRoundDone($pdo, $flockId, $vName);
            $remaining = max(0, $qty - $done);
            if ($birds > $remaining) {
                $msg = "error:{$flock['flock_name']} has {$remaining} birds left to vaccinate for {$vName} ({$done} of {$qty} done). You entered {$birds}.";
            } elseif ($nextDue && $nextDue <= $dateGiven) {
                $msg = 'error:The next due date must be after the date given.';
            } else {
                $total    = $done + $birds;
                $complete = $total >= $qty;
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO vaccinations
                    (flock_id, vaccine_name, date_given, next_due_date, dosage, method, lot_number, lot_expiry,
                     administered_by, birds_vaccinated, notes, status, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$flockId, $vName, $dateGiven, $nextDue, $dosage, $method, $lot, $expiry,
                        sanitize($_POST['administered_by'] ?? ''), $complete ? $total : $birds,
                        lotNote(sanitize($_POST['notes'] ?? ''), $expiry), $complete ? 'completed' : 'in_progress', $_SESSION['user_id']]);
                $vid = (int)$pdo->lastInsertId();
                if ($complete) {
                    vaccCloseRound($pdo, $flockId, $vName);
                    if ($nextDue) {   // next dose is scheduled only once the round is 100%
                        $pdo->prepare("INSERT INTO vaccinations
                            (flock_id, vaccine_name, date_given, next_due_date, dosage, method, status, created_by)
                            VALUES (?,?,?,NULL,?,?,'scheduled',?)")
                            ->execute([$flockId, $vName, $nextDue, $dosage, $method, $_SESSION['user_id']]);
                    }
                }
                $pdo->commit();
                auditLog($pdo, 'add_vaccination', 'vaccination', $vid,
                    "{$vName} for {$flock['flock_name']}: +{$birds} birds, total {$total} of {$qty}"
                    .($complete ? ' (round complete)' : ' (partial)'));
                $msg = $complete
                    ? "success:{$vName} round complete — 100% of {$flock['flock_name']} vaccinated."
                    : "info:Recorded {$birds} birds. " . ($remaining - $birds) . " still to vaccinate — the round stays In progress until 100%.";
            }
        }
    }

    // Complete a scheduled dose with a session (may be partial)
    if (isset($_POST['mark_complete'])) {
        $vid       = (int)($_POST['vacc_id'] ?? 0);
        $dateGiven = $_POST['date_given'] ?: date('Y-m-d');
        $birds     = (int)($_POST['birds_vaccinated'] ?? 0);
        $nextDue2  = !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : null;
        $adminBy   = sanitize($_POST['administered_by'] ?? '');
        $lot       = sanitize($_POST['lot_number'] ?? '');
        $expiry    = !empty($_POST['lot_expiry']) ? $_POST['lot_expiry'] : null;
        $notes     = lotNote(sanitize($_POST['notes'] ?? ''), $expiry);

        $sr = $pdo->prepare("SELECT v.*, f.flock_name, f.quantity AS flock_qty
            FROM vaccinations v JOIN flocks f ON f.id=v.flock_id WHERE v.id=?");
        $sr->execute([$vid]);
        $sched = $sr->fetch();

        if (!$sched)                              $msg = 'error:Record not found.';
        elseif ($birds < 0)                       $msg = 'error:Birds vaccinated cannot be negative.';
        elseif ($expiry && $expiry <= date('Y-m-d') && empty($_POST['lot_override'])) $msg = 'error:This lot expired on '.date('M d, Y', strtotime($expiry)).' or expires today. Tick "Use this lot anyway" to record it.';
        else {
            $qty       = (int)$sched['flock_qty'];
            $done      = vaccRoundDone($pdo, (int)$sched['flock_id'], $sched['vaccine_name']);
            $remaining = max(0, $qty - $done);
            if ($birds > $remaining) {
                $msg = "error:{$sched['flock_name']} has {$remaining} birds left to vaccinate for {$sched['vaccine_name']} ({$done} of {$qty} done). You entered {$birds}.";
            } elseif ($birds < 1 && $remaining > 0) {
                $msg = 'error:Enter how many birds were vaccinated (at least 1).';
            } elseif ($nextDue2 && $nextDue2 <= $dateGiven) {
                $msg = 'error:The next due date must be after the date given.';
            } else {
                $total    = $done + $birds;
                $complete = $remaining === 0 || $total >= $qty;
                $pdo->beginTransaction();
                if ($complete) {
                    $pdo->prepare("UPDATE vaccinations SET status='completed', date_given=?, next_due_date=?,
                        administered_by=?, birds_vaccinated=?, notes=?, lot_number=?, lot_expiry=?, completed_by=? WHERE id=?")
                        ->execute([$dateGiven, $nextDue2, $adminBy, $total, $notes, $lot, $expiry, $_SESSION['user_id'], $vid]);
                    vaccCloseRound($pdo, (int)$sched['flock_id'], $sched['vaccine_name']);
                    if ($nextDue2) {
                        $pdo->prepare("INSERT INTO vaccinations
                            (flock_id, vaccine_name, date_given, next_due_date, dosage, method, status, created_by)
                            VALUES (?,?,?,NULL,?,?,'scheduled',?)")
                            ->execute([$sched['flock_id'], $sched['vaccine_name'], $nextDue2,
                                $sched['dosage'], $sched['method'], $_SESSION['user_id']]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO vaccinations
                        (flock_id, vaccine_name, date_given, next_due_date, dosage, method, lot_number, lot_expiry,
                         administered_by, birds_vaccinated, notes, status, created_by, completed_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,'in_progress',?,?)")
                        ->execute([$sched['flock_id'], $sched['vaccine_name'], $dateGiven, $nextDue2,
                            $sched['dosage'], $sched['method'], $lot, $expiry, $adminBy, $birds, $notes,
                            $_SESSION['user_id'], $_SESSION['user_id']]);
                }
                $pdo->commit();
                auditLog($pdo, 'complete_vaccination', 'vaccination', $vid,
                    "{$sched['vaccine_name']} for {$sched['flock_name']}: +{$birds}, total {$total} of {$qty}"
                    .($complete ? ' (round complete)' : ' (partial)'));
                $msg = $complete
                    ? "success:{$sched['vaccine_name']} complete — 100% of {$sched['flock_name']} vaccinated."
                    : "info:Recorded {$birds} birds. " . ($remaining - $birds) . " still to vaccinate — this dose stays due until 100%.";
            }
        }
    }

    if (isset($_POST['reschedule'])) {
        $vid     = (int)($_POST['vacc_id'] ?? 0);
        $newDate = $_POST['new_due_date'] ?? '';
        $reason  = sanitize($_POST['reason'] ?? '');
        $vr = $pdo->prepare("SELECT v.*, f.flock_name FROM vaccinations v JOIN flocks f ON f.id=v.flock_id WHERE v.id=?");
        $vr->execute([$vid]);
        $vacc = $vr->fetch();
        if (!$vacc || !$newDate)           $msg = 'error:Could not reschedule.';
        elseif ($newDate < date('Y-m-d'))  $msg = 'error:The new date cannot be in the past.';
        else {
            $oldDate = $vacc['date_given'] ?? '';
            $pdo->prepare("UPDATE vaccinations SET date_given=?, next_due_date=NULL, status='scheduled' WHERE id=?")
                ->execute([$newDate, $vid]);
            auditLog($pdo, 'reschedule_vaccination', 'vaccination', $vid,
                "Rescheduled '{$vacc['vaccine_name']}' for {$vacc['flock_name']} from {$oldDate} to {$newDate}. Reason: {$reason}");
            $msg = 'success:Rescheduled to ' . date('M d, Y', strtotime($newDate)) . '!';
        }
    }

    header('Location: vaccinations.php?msg=' . urlencode($msg)); exit;
}

// ── Filters ───────────────────────────────────────────────────
$urlMsg       = $_GET['msg']     ?? '';
$flockFilter  = (int)($_GET['flock_id'] ?? 0);
$statusFilter = $_GET['status']  ?? '';
$vaccFilter   = $_GET['vaccine'] ?? '';
$search       = $_GET['search']  ?? '';

$where  = "WHERE 1=1";
$params = [];
if ($flockFilter) { $where .= " AND v.flock_id=?"; $params[] = $flockFilter; }
if ($vaccFilter)  { $where .= " AND v.vaccine_name=?"; $params[] = $vaccFilter; }
if ($search)      { $where .= " AND (v.vaccine_name LIKE ? OR f.flock_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($statusFilter === 'due7') {
    $where .= " AND $openSql AND $dueSql BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($statusFilter) {
    $where .= " AND ($effSql)=?"; $params[] = $statusFilter;
}

$stmt = $pdo->prepare("
    SELECT v.*, f.flock_name, f.bird_type, f.quantity AS flock_qty,
           cb.name AS created_by_name, cpb.name AS completed_by_name,
           CASE WHEN v.status='completed' THEN v.next_due_date
                WHEN v.status='in_progress' THEN NULL
                ELSE $dueSql END AS due_date,
           ($effSql) AS eff_status,
           DATEDIFF($dueSql, CURDATE()) AS days_until_due
    FROM vaccinations v
    JOIN flocks f ON f.id=v.flock_id
    LEFT JOIN users cb  ON cb.id=v.created_by
    LEFT JOIN users cpb ON cpb.id=v.completed_by
    $where
    ORDER BY CASE ($effSql) WHEN 'overdue' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'scheduled' THEN 3 ELSE 4 END,
             $dueSql ASC
    LIMIT 300
");
$stmt->execute($params);
$vaccinations = $stmt->fetchAll();
foreach ($vaccinations as &$v) {
    $v['status']        = $v['eff_status'];
    $v['next_due_date'] = $v['due_date'];
}
unset($v);

// ── KPIs (open doses only — partial sessions are shown separately) ─
$overdueCount    = (int)$pdo->query("SELECT COUNT(*) FROM vaccinations v WHERE $openSql AND $dueSql < CURDATE()")->fetchColumn();
$todayCount      = (int)$pdo->query("SELECT COUNT(*) FROM vaccinations v WHERE $openSql AND $dueSql = CURDATE()")->fetchColumn();
$week7Count      = (int)$pdo->query("SELECT COUNT(*) FROM vaccinations v WHERE $openSql AND $dueSql BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
$scheduledCount  = (int)$pdo->query("SELECT COUNT(*) FROM vaccinations v WHERE $openSql AND $dueSql >= CURDATE()")->fetchColumn();
$inProgressCount = (int)$pdo->query("SELECT COUNT(*) FROM vaccinations v WHERE v.status='in_progress'")->fetchColumn();
$completedCount  = (int)$pdo->query("SELECT COUNT(*) FROM vaccinations v WHERE v.status='completed'")->fetchColumn();

$nextUpcoming = $pdo->query("SELECT f.flock_name, $dueSql AS next_due_date
    FROM vaccinations v JOIN flocks f ON f.id=v.flock_id
    WHERE $openSql AND $dueSql >= CURDATE()
    ORDER BY $dueSql ASC LIMIT 1")->fetch();

// Attention: overdue or due within 7 days, plus any round still in progress
$attentionList = $pdo->query("SELECT v.id, v.vaccine_name, f.flock_name, f.bird_type, v.status AS raw_status,
        $dueSql AS due_date, DATEDIFF($dueSql, CURDATE()) AS days_until_due
    FROM vaccinations v JOIN flocks f ON f.id=v.flock_id
    WHERE ($openSql AND $dueSql <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) OR v.status='in_progress'
    ORDER BY CASE WHEN v.status='in_progress' THEN 1 ELSE 0 END, $dueSql ASC
    LIMIT 8")->fetchAll();
foreach ($attentionList as &$a) {
    $a['status']        = $a['raw_status'] === 'in_progress' ? 'in_progress' : (((int)$a['days_until_due'] < 0) ? 'overdue' : 'scheduled');
    $a['next_due_date'] = $a['due_date'];
}
unset($a);

// Coverage: highest single-round vaccinated count per flock vs flock size
$coverageData = $pdo->query("
    SELECT f.flock_name, f.quantity AS flock_qty, f.bird_type,
           COUNT(v.id) AS vacc_records,
           MAX(v.birds_vaccinated) AS last_birds_vaccd,
           MAX(CASE WHEN v.status='completed' THEN v.date_given END) AS last_vacc_date,
           SUM(CASE WHEN v.status='overdue' THEN 1 ELSE 0 END) AS has_overdue
    FROM flocks f
    LEFT JOIN vaccinations v ON v.flock_id=f.id
    WHERE f.status='active'
    GROUP BY f.id
    ORDER BY f.flock_name
")->fetchAll();

$vaccineNames = $pdo->query("SELECT DISTINCT vaccine_name FROM vaccinations ORDER BY vaccine_name")->fetchAll(PDO::FETCH_COLUMN);
$flocks       = $pdo->query("SELECT * FROM flocks WHERE status='active' ORDER BY flock_name")->fetchAll();

$flockHistory = [];
foreach ($flocks as $fl) {
    $hs = $pdo->prepare("SELECT * FROM vaccinations WHERE flock_id=? ORDER BY COALESCE(date_given, CURDATE()) DESC LIMIT 10");
    $hs->execute([$fl['id']]);
    $flockHistory[$fl['id']] = $hs->fetchAll();
}

function statusBadge(string $s): string {
    $map = [
        'completed'   => ['#dcfce7','#15803d','fa-check-circle','Completed'],
        'in_progress' => ['#fef3c7','#92400e','fa-spinner','In progress'],
        'scheduled'   => ['#dbeafe','#1e40af','fa-clock','Scheduled'],
        'overdue'     => ['#fee2e2','#991b1b','fa-exclamation-circle','Overdue'],
    ];
    [$bg,$color,$icon,$label] = $map[$s] ?? ['#f1f5f9','#475569','fa-circle','Unknown'];
    return "<span style='display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:50px;font-size:11px;font-weight:700;background:{$bg};color:{$color};'>
              <i class='fas {$icon}' style='font-size:9px;'></i> {$label}
            </span>";
}

function methodBadge(string $m): string {
    $map = ['injection'=>['💉','Injection'],'drinking_water'=>['💧','Water'],'eye_drop'=>['👁','Eye Drop'],'spray'=>['💨','Spray']];
    [$icon,$label] = $map[$m] ?? ['🔹', ucwords(str_replace('_',' ',$m))];
    return "<span style='font-size:12px;'>{$icon} {$label}</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Vaccinations — Coopers Admin</title>
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
    <h1>Vaccination Tracker</h1>
    <p>Manage and monitor vaccination schedules across all flocks</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="vaccinations.php" class="btn btn-secondary btn-sm"><i class="fas fa-sync"></i> Sync Status</a>
    <button class="btn btn-primary" onclick="document.getElementById('addVaccModal').classList.add('open')">
      <i class="fas fa-syringe"></i> Record Vaccination
    </button>
  </div>
</div>

<?php if($urlMsg): [$t,$tx]=explode(':',$urlMsg,2); ?>
<div class="alert alert-<?php echo $t; ?>" style="margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($tx); ?></div>
<?php endif; ?>

<!-- ── KPI Strip ──────────────────────────────────────────── -->
<div class="kpi-strip">
  <div class="kpi <?php echo $overdueCount>0?'red':'green'; ?>">
    <i class="fas fa-exclamation-circle kpi-ico"></i>
    <div class="kpi-lbl">Overdue</div>
    <div class="kpi-val" style="color:<?php echo $overdueCount>0?'#ef4444':'#22c55e'; ?>"><?php echo $overdueCount; ?></div>
    <div class="kpi-sub"><?php echo $overdueCount>0?'Act immediately':'All up to date ✓'; ?></div>
  </div>
  <div class="kpi <?php echo $todayCount>0?'orange':'green'; ?>">
    <i class="fas fa-clock kpi-ico"></i>
    <div class="kpi-lbl">Due Today</div>
    <div class="kpi-val" style="color:<?php echo $todayCount>0?'#f59e0b':'inherit'; ?>"><?php echo $todayCount; ?></div>
    <div class="kpi-sub">Administer today</div>
  </div>
  <div class="kpi blue">
    <i class="fas fa-calendar kpi-ico"></i>
    <div class="kpi-lbl">Next 7 Days</div>
    <div class="kpi-val"><?php echo $week7Count; ?></div>
    <div class="kpi-sub">Upcoming doses</div>
    <?php if($nextUpcoming): ?>
    <div class="kpi-next">
      Next: <?php echo htmlspecialchars($nextUpcoming['flock_name']); ?>
      · <?php echo date('M d', strtotime($nextUpcoming['next_due_date'])); ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="kpi purple">
    <i class="fas fa-list kpi-ico"></i>
    <div class="kpi-lbl">Total Scheduled</div>
    <div class="kpi-val"><?php echo $scheduledCount; ?></div>
    <div class="kpi-sub">Future doses planned</div>
  </div>
  <div class="kpi green">
    <i class="fas fa-check-circle kpi-ico"></i>
    <div class="kpi-lbl">Completed</div>
    <div class="kpi-val" style="color:#22c55e;"><?php echo $completedCount; ?></div>
    <div class="kpi-sub">All-time administered</div>
  </div>
</div>

<!-- ── Middle row: Attention panel + Coverage ─────────────── -->
<div class="mid-row">

  <!-- Vaccinations requiring attention -->
  <div class="attention-card">
    <div class="attention-head <?php echo empty($attentionList)?'clean':''; ?>">
      <h3>
        <?php if(empty($attentionList)): ?>
          <i class="fas fa-shield-alt" style="color:#22c55e;"></i> All Flocks Up to Date
        <?php else: ?>
          <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i>
          Requires Attention
          <span style="background:#ef4444;color:white;font-size:10px;font-weight:700;padding:1px 7px;border-radius:50px;"><?php echo count($attentionList); ?></span>
        <?php endif; ?>
      </h3>
      <?php if(!empty($attentionList)): ?>
      <a href="?status=overdue" style="font-size:12px;color:var(--primary);font-weight:700;">View all →</a>
      <?php endif; ?>
    </div>

    <?php if(empty($attentionList)): ?>
    <div style="text-align:center;padding:30px;color:#9ca3af;">
      <i class="fas fa-check-circle" style="font-size:32px;color:#4ade80;margin-bottom:8px;display:block;"></i>
      No vaccinations overdue or due within 7 days.
    </div>
    <?php else: ?>
    <?php foreach($attentionList as $a):
      $d = (int)($a['days_until_due'] ?? 0);
      if($a['status']==='overdue') { $dot='#ef4444'; $badgeBg='#fee2e2'; $badgeColor='#991b1b'; $badgeText=abs($d).'d overdue'; }
      elseif($d===0)               { $dot='#f59e0b'; $badgeBg='#fef3c7'; $badgeColor='#92400e'; $badgeText='Due TODAY'; }
      elseif($d<=3)                { $dot='#f59e0b'; $badgeBg='#fef3c7'; $badgeColor='#92400e'; $badgeText='Due in '.$d.'d'; }
      else                         { $dot='#3b82f6'; $badgeBg='#dbeafe'; $badgeColor='#1e40af'; $badgeText='Due in '.$d.'d'; }
    ?>
    <div class="attn-row">
      <div class="attn-dot" style="background:<?php echo $dot; ?>;box-shadow:0 0 0 3px <?php echo $dot; ?>22;"></div>
      <div class="attn-info">
        <div class="attn-name"><?php echo htmlspecialchars($a['flock_name']); ?></div>
        <div class="attn-sub"><?php echo htmlspecialchars($a['vaccine_name']); ?> · <?php echo ucfirst($a['bird_type']); ?></div>
      </div>
      <span class="attn-badge" style="background:<?php echo $badgeBg; ?>;color:<?php echo $badgeColor; ?>;">
        <?php echo $badgeText; ?>
      </span>
      <div class="attn-acts" onclick="event.stopPropagation()">
        <?php if($a['status']==='overdue' || $d<=7): ?>
        <button class="act-btn" style="background:var(--primary);color:white;border-color:var(--primary);"
          onclick="openCompleteModal(<?php echo $a['id']; ?>,'<?php echo htmlspecialchars($a['vaccine_name'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($a['flock_name'],ENT_QUOTES); ?>')"
          title="Mark done"><i class="fas fa-check"></i></button>
        <button class="act-btn"
          onclick="openRescheduleModal(<?php echo $a['id']; ?>,'<?php echo htmlspecialchars($a['vaccine_name'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($a['flock_name'],ENT_QUOTES); ?>','<?php echo $a['next_due_date']??''; ?>')"
          title="Reschedule"><i class="fas fa-calendar-alt"></i></button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Flock vaccination coverage -->
  <div class="coverage-card">
    <div class="coverage-title">Flock Vaccination Coverage</div>
    <?php foreach($coverageData as $cv):
      $pct = ($cv['flock_qty']>0 && $cv['last_birds_vaccd']>0)
             ? min(100, round(($cv['last_birds_vaccd']/$cv['flock_qty'])*100))
             : ($cv['vacc_records']>0 ? 100 : 0);
      $barClr = $pct>=90?'#22c55e':($pct>=60?'#f59e0b':'#ef4444');
      $typeEmoji = ['layer'=>'🐔','broiler'=>'🍗','cockerel'=>'🐓','turkey'=>'🦃'][$cv['bird_type']]??'🐦';
    ?>
    <div class="cov-row">
      <div class="cov-name">
        <span><?php echo $typeEmoji; ?> <?php echo htmlspecialchars($cv['flock_name']); ?></span>
        <span class="cov-pct" style="color:<?php echo $barClr; ?>;"><?php echo $pct; ?>%</span>
      </div>
      <div class="cov-bar"><div class="cov-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $barClr; ?>;"></div></div>
      <div class="cov-meta">
        <?php if($cv['last_birds_vaccd']): ?>
          <?php echo number_format($cv['last_birds_vaccd']); ?>/<?php echo number_format($cv['flock_qty']); ?> birds
          <?php if($cv['last_vacc_date']): ?> · Last: <?php echo date('M d', strtotime($cv['last_vacc_date'])); ?><?php endif; ?>
        <?php else: ?>
          No vaccination recorded
        <?php endif; ?>
        <?php if($cv['has_overdue']>0): ?> · <span style="color:#ef4444;font-weight:700;">⚠ Overdue</span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($coverageData)): ?>
    <div style="text-align:center;color:#9ca3af;padding:20px;font-size:13px;">No active flocks</div>
    <?php endif; ?>
  </div>

</div><!-- /mid-row -->

<!-- ── Filter bar ──────────────────────────────────────────── -->
<div class="filter-bar">
  <i class="fas fa-filter" style="color:#9ca3af;font-size:13px;"></i>
  <!-- Status chips -->
  <a class="fchip <?php echo !$statusFilter&&!$flockFilter&&!$search?'active':''; ?>" href="vaccinations.php">All</a>
  <a class="fchip danger <?php echo $statusFilter==='overdue'?'active':''; ?>" href="?status=overdue">🔴 Overdue<?php if($overdueCount>0): ?> (<?php echo $overdueCount; ?>)<?php endif; ?></a>
  <a class="fchip amber <?php echo $statusFilter==='scheduled'?'active':''; ?>" href="?status=scheduled">🟡 Scheduled</a>
  <a class="fchip <?php echo $statusFilter==='completed'?'active':''; ?>" href="?status=completed">✅ Completed</a>
  <a class="fchip amber <?php echo $statusFilter==='due7'?'active':''; ?>" href="?status=due7">📅 Due in 7 days</a>
  <a class="fchip <?php echo $statusFilter==='in_progress'?'active':''; ?>" href="?status=in_progress">🟠 In progress<?php if($inProgressCount>0): ?> (<?php echo $inProgressCount; ?>)<?php endif; ?></a>
  <div class="fchip-divider"></div>
  <!-- Flock filter -->
  <select class="fld-ctrl" onchange="window.location='?flock_id='+this.value+'&status=<?php echo urlencode($statusFilter); ?>'" style="width:170px;">
    <option value="">All Flocks</option>
    <?php foreach($flocks as $fl): ?>
    <option value="<?php echo $fl['id']; ?>" <?php echo $flockFilter==$fl['id']?'selected':''; ?>>
      <?php echo htmlspecialchars($fl['flock_name']); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <!-- Vaccine filter -->
  <select class="fld-ctrl" onchange="window.location='?vaccine='+this.value+'&status=<?php echo urlencode($statusFilter); ?>'" style="width:160px;">
    <option value="">All Vaccines</option>
    <?php foreach($vaccineNames as $vn): ?>
    <option value="<?php echo htmlspecialchars($vn); ?>" <?php echo $vaccFilter===$vn?'selected':''; ?>><?php echo htmlspecialchars($vn); ?></option>
    <?php endforeach; ?>
  </select>
  <!-- Search -->
  <input type="text" class="fld-ctrl" id="liveSearch" placeholder="🔍 Search flock or vaccine..."
         value="<?php echo htmlspecialchars($search); ?>" oninput="vaccLiveFilter(this.value)"
         style="margin-left:auto;width:210px;">
  <?php if($statusFilter||$flockFilter||$vaccFilter||$search): ?>
  <a href="vaccinations.php" class="btn btn-secondary btn-sm">Clear</a>
  <?php endif; ?>
</div>

<!-- ── Vaccination table ────────────────────────────────────── -->
<div class="card" style="overflow:hidden;">
  <div style="padding:13px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
    <div style="font-size:15px;font-weight:700;">
      Vaccination Records
      <span style="font-size:13px;color:#9ca3af;font-weight:400;margin-left:6px;">(<?php echo count($vaccinations); ?>)</span>
    </div>
    <span style="font-size:12px;color:#9ca3af;">Click row to view flock history</span>
  </div>
  <div style="overflow-x:auto;">
  <table class="vacc-table" id="vaccTable">
    <thead>
      <tr>
        <th>Flock</th>
        <th>Vaccine</th>
        <th>Date Given</th>
        <th>Next Due</th>
        <th>Method</th>
        <th>Birds</th>
        <th>Coverage</th>
        <th>Administered By</th>
        <th>Status</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($vaccinations as $v):
      $d   = (int)($v['days_until_due'] ?? 99);
      $cls = '';
      if ($v['status']==='overdue')                     $cls='row-overdue';
      elseif($v['status']==='scheduled' && $d===0)      $cls='row-today';

      $coverage = null;
      if ($v['birds_vaccinated'] && $v['flock_qty']) {
          $coverage = min(100, round(($v['birds_vaccinated']/$v['flock_qty'])*100));
      }
      $typeEmoji = ['layer'=>'🐔','broiler'=>'🍗','cockerel'=>'🐓','turkey'=>'🦃'][$v['bird_type']]??'🐦';
    ?>
    <tr class="<?php echo $cls; ?> vacc-row"
        data-search="<?php echo strtolower(htmlspecialchars($v['flock_name'].' '.$v['vaccine_name'])); ?>"
        onclick="openFlockHistory(<?php echo $v['flock_id']; ?>,'<?php echo htmlspecialchars($v['flock_name'],ENT_QUOTES); ?>')">

      <!-- Flock -->
      <td class="flock-cell">
        <strong><?php echo htmlspecialchars($v['flock_name']); ?></strong>
        <span><?php echo $typeEmoji; ?> <?php echo ucfirst($v['bird_type']); ?></span>
      </td>

      <!-- Vaccine -->
      <td style="font-weight:700;"><?php echo htmlspecialchars($v['vaccine_name']); ?></td>

      <!-- Date given -->
      <td style="font-size:12px;color:#6b7280;">
        <?php echo ($v['date_given'] && $v['date_given']!=='0000-00-00')
            ? date('M d, Y', strtotime($v['date_given']))
            : '<span style="color:#d1d5db;">—</span>'; ?>
      </td>

      <!-- Next due -->
      <td>
        <?php if($v['next_due_date']): ?>
          <?php
            if($v['status']==='overdue')      { $dc='#ef4444'; $sfx=' ('.abs($d).'d overdue)'; }
            elseif($d===0)                    { $dc='#f59e0b'; $sfx=' (Today!)'; }
            elseif($d<=3 && $d>0)             { $dc='#f59e0b'; $sfx=' (in '.$d.'d)'; }
            elseif($v['status']==='completed'){ $dc='#22c55e'; $sfx=''; }
            else                              { $dc='#6b7280'; $sfx=''; }
          ?>
          <span style="font-size:12px;font-weight:700;color:<?php echo $dc; ?>;">
            <?php echo date('M d, Y', strtotime($v['next_due_date'])); ?>
            <?php echo $sfx; ?>
          </span>
        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
      </td>

      <!-- Method -->
      <td><?php echo methodBadge($v['method']??''); ?></td>

      <!-- Birds -->
      <td style="font-weight:700;text-align:center;"><?php echo $v['birds_vaccinated']?number_format($v['birds_vaccinated']):'—'; ?></td>

      <!-- Coverage pill -->
      <td>
        <?php if($coverage!==null): ?>
        <span class="coverage-pill" style="background:<?php echo $coverage>=90?'#dcfce7':($coverage>=60?'#fef3c7':'#fee2e2'); ?>;color:<?php echo $coverage>=90?'#15803d':($coverage>=60?'#92400e':'#991b1b'); ?>;">
          <?php echo $coverage; ?>%
        </span>
        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
      </td>

      <!-- Administered by -->
      <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($v['administered_by']??'—'); ?></td>

      <!-- Status -->
      <td><?php echo statusBadge($v['status']); ?></td>

      <!-- Actions -->
      <td onclick="event.stopPropagation()" style="text-align:right;">
        <div style="display:flex;gap:4px;justify-content:flex-end;">
          <?php if(in_array($v['status'],['scheduled','overdue'])): ?>
          <button class="act-btn" style="background:var(--primary);color:white;border-color:var(--primary);"
            onclick="openCompleteModal(<?php echo $v['id']; ?>,'<?php echo htmlspecialchars($v['vaccine_name'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($v['flock_name'],ENT_QUOTES); ?>')"
            title="Mark as completed"><i class="fas fa-check"></i></button>
          <button class="act-btn"
            onclick="openRescheduleModal(<?php echo $v['id']; ?>,'<?php echo htmlspecialchars($v['vaccine_name'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($v['flock_name'],ENT_QUOTES); ?>','<?php echo $v['next_due_date']??''; ?>')"
            title="Reschedule"><i class="fas fa-calendar-alt"></i></button>
          <?php else: ?>
          <?php if($v['status']==='in_progress'): ?><button class="act-btn" style="background:#f59e0b;color:white;border-color:#f59e0b;width:auto;padding:0 9px;font-size:11px;font-weight:700;" onclick="openRecordFor(<?php echo (int)$v['flock_id']; ?>,'<?php echo htmlspecialchars($v['vaccine_name'],ENT_QUOTES); ?>')" title="Record more birds for this round"><i class="fas fa-plus"></i> Add birds</button><?php else: ?><span style="font-size:11px;font-weight:700;color:#22c55e;padding:4px 8px;background:#f0fdf4;border-radius:6px;">
            <i class="fas fa-check-circle"></i> Done
          </span><?php endif; ?>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($vaccinations)): ?>
    <tr><td colspan="10" style="text-align:center;padding:48px;color:#9ca3af;">
      <i class="fas fa-syringe" style="font-size:40px;color:#e5e7eb;display:block;margin-bottom:12px;"></i>
      <strong style="display:block;margin-bottom:4px;">No vaccination records found</strong>
      <a href="vaccinations.php" style="color:var(--primary);font-size:13px;">Clear filters →</a>
    </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div>
</div>

<!-- ── Flock History Drawer ─────────────────────────────────── -->
<div class="drawer-overlay" id="histOverlay" onclick="vaccCloseDrawer()"></div>
<div class="drawer" id="histDrawer">
  <div class="dhead">
    <h3 id="drawerTitle">Vaccination History</h3>
    <button class="dclose" onclick="vaccCloseDrawer()"><i class="fas fa-times"></i></button>
  </div>
  <div class="dbody" id="drawerBody">
    <div style="text-align:center;padding:40px;color:#9ca3af;">
      <i class="fas fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;"></i>Loading…
    </div>
  </div>
</div>

<!-- ── Record Vaccination Modal ────────────────────────────── -->
<div class="modal-overlay" id="addVaccModal">
  <div class="modal" style="max-width:680px;">
    <div class="modal-header">
      <h3><i class="fas fa-syringe" style="color:var(--primary);margin-right:8px;"></i>Record Vaccination</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_vaccination" value="1">
      <div style="padding:16px 32px 0;">
        <div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">Flock &amp; Vaccine</div>
        <div class="form-row">
          <div class="form-group">
            <label>Flock *</label>
            <select name="flock_id" id="vFlock" class="form-control" required>
              <option value="">Select flock…</option>
              <?php foreach($flocks as $fl): ?>
              <option data-qty="<?php echo (int)$fl['quantity']; ?>" value="<?php echo $fl['id']; ?>" <?php echo $flockFilter==$fl['id']?'selected':''; ?>>
                <?php echo htmlspecialchars($fl['flock_name']); ?> (<?php echo ucfirst($fl['bird_type']); ?>)
              </option>
              <?php endforeach; ?>
            </select>
            <div class="batch-info" id="vBatchInfo">Choose a batch to see its birds</div>
            <div class="form-hint" id="vPrefillNote"></div>
          </div>
          <div class="form-group">
            <label>Vaccine *</label>
            <input type="text" name="vaccine_name" id="vVaccine" class="form-control" required
                   placeholder="e.g. Newcastle Disease" list="vaccineList">
            <datalist id="vaccineList">
              <option value="Newcastle Disease"><option value="Gumboro (IBD)">
              <option value="Marek's Disease"><option value="Fowl Pox">
              <option value="Infectious Bronchitis"><option value="Lasota">
              <option value="Avian Influenza"><option value="Coccidiosis">
            </datalist>
          </div>
        </div>
        <div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin:14px 0 10px;">Administration Details</div>
        <div class="form-row">
          <div class="form-group">
            <label>Date Given *</label>
            <input type="date" name="date_given" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="form-group">
            <label>Next Due Date</label>
            <input type="date" name="next_due_date" id="vNext" class="form-control" min="<?php echo date('Y-m-d',strtotime('+1 day')); ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Method</label>
            <select name="method" id="vMethod" class="form-control">
              <option value="drinking_water">💧 Drinking Water</option>
              <option value="injection">💉 Injection</option>
              <option value="eye_drop">👁 Eye Drop</option>
              <option value="spray">💨 Spray</option>
            </select>
          </div>
          <div class="form-group">
            <label>Dosage</label>
            <input type="text" name="dosage" id="vDosage" class="form-control" placeholder="e.g. 1 drop per bird">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Birds Vaccinated</label>
            <input type="number" name="birds_vaccinated" id="vBirds" class="form-control" min="0">
            <div class="form-hint" id="vBirdsHint"></div>
          </div>
          <div class="form-group">
            <label>Administered By</label>
            <input type="text" name="administered_by" class="form-control"
                   value="<?php echo htmlspecialchars($_SESSION['name']??''); ?>">
          </div>
        </div>
        <div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin:14px 0 10px;">Traceability (optional)</div>
        <div class="form-row">
          <div class="form-group">
            <label>Vaccine Lot / Batch No.</label>
            <input type="text" name="lot_number" id="vLot" class="form-control" placeholder="e.g. NCL-2026-0821">
          </div>
          <div class="form-group">
            <label>Vaccine Expiry Date</label>
            <input type="date" name="lot_expiry" id="vExpiry" class="form-control">
            <div class="lot-flag" id="vExpiryFlag" style="display:none;"></div>
            <label class="lot-override" style="display:none;"><input type="checkbox" name="lot_override" value="1" id="vOverride"> Use this lot anyway</label>
          </div>
        </div>
        <div class="form-group">
          <label>Notes / Observations</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Any reactions, missed birds, conditions…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="vSubmit"><i class="fas fa-save"></i> Save Record</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Mark Complete Modal ──────────────────────────────────── -->
<div class="modal-overlay" id="completeModal">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header">
      <h3><i class="fas fa-check-circle" style="color:#22c55e;margin-right:8px;"></i>Mark as Completed</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="mark_complete" value="1">
      <input type="hidden" name="vacc_id" id="cVaccId">
      <div style="padding:0 32px;">
        <div style="background:#f0fdf4;border-radius:10px;padding:12px 16px;margin-top:16px;margin-bottom:16px;">
          <strong id="cVaccineName" style="font-size:15px;display:block;"></strong>
          <span style="font-size:13px;color:#555;">Flock: <strong id="cFlockName"></strong></span><br><span style="font-size:13px;color:#555;">Birds in this batch: <strong id="cBatchBirds">—</strong></span>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Date Administered *</label>
            <input type="date" name="date_given" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="form-group">
            <label>Birds Vaccinated</label>
            <input type="number" name="birds_vaccinated" id="cBirds" class="form-control" min="0" placeholder="Number of birds">
            <div class="form-hint" id="cBirdsHint"></div>
          </div>
        </div>
        <div class="form-group">
          <label>Administered By</label>
          <input type="text" name="administered_by" class="form-control"
                 value="<?php echo htmlspecialchars($_SESSION['name']??''); ?>">
        </div>
        <div class="form-group">
          <label>Next Due Date <span style="font-weight:400;color:#9ca3af;">(schedules next dose)</span></label>
          <input type="date" name="next_due_date" id="cNext" class="form-control" min="<?php echo date('Y-m-d',strtotime('+1 day')); ?>">
        </div>
        <div class="form-group">
          <label>Notes / Observations</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Any reactions, missed birds…"></textarea>
        </div>
      </div>
      <div style="padding:0 32px;">
        <div class="form-row">
          <div class="form-group"><label>Vaccine Lot / Batch No.</label>
            <input type="text" name="lot_number" id="cLot" class="form-control" placeholder="e.g. NCL-2026-0821"></div>
          <div class="form-group"><label>Vaccine Expiry Date</label>
            <input type="date" name="lot_expiry" id="cExpiry" class="form-control">
            <div class="lot-flag" id="cExpiryFlag" style="display:none;"></div>
            <label class="lot-override" style="display:none;"><input type="checkbox" name="lot_override" value="1" id="cOverride"> Use this lot anyway</label></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="cSubmit"><i class="fas fa-check"></i> Mark Completed</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Reschedule Modal ─────────────────────────────────────── -->
<div class="modal-overlay" id="reschedModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header">
      <h3><i class="fas fa-calendar-alt" style="color:#f59e0b;margin-right:8px;"></i>Reschedule Vaccination</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="reschedule" value="1">
      <input type="hidden" name="vacc_id" id="rVaccId">
      <div style="padding:0 32px;">
        <div style="background:#fffbf0;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;margin-top:16px;margin-bottom:16px;">
          <strong id="rVaccineName" style="display:block;font-size:15px;"></strong>
          <span style="font-size:13px;">Flock: <strong id="rFlockName"></strong></span><br>
          <span style="font-size:12px;color:#92400e;">Currently due: <strong id="rCurrentDate"></strong></span>
        </div>
        <div class="form-group">
          <label>New Due Date *</label>
          <input type="date" name="new_due_date" id="rNewDate" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-group">
          <label>Reason for Rescheduling</label>
          <textarea name="reason" class="form-control" rows="2"
                    placeholder="e.g. Vet unavailable, birds unwell, supply delay…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background:#f59e0b;"><i class="fas fa-calendar-check"></i> Reschedule</button>
      </div>
    </form>
  </div>
</div>

<?php

$pageData = ['flockHistory'=>$flockHistory];
?>
<?php
$pageData['vaccRow'] = [];
foreach ($pdo->query("SELECT v.id, v.flock_id, v.vaccine_name, f.quantity FROM vaccinations v JOIN flocks f ON f.id=v.flock_id WHERE v.status IN ('scheduled','overdue')")->fetchAll() as $r) {
    $pageData['vaccRow'][$r['id']] = ['f'=>(int)$r['flock_id'], 'v'=>$r['vaccine_name'], 'q'=>(int)$r['quantity']];
}
$pageData['progress'] = [];
foreach ($pdo->query("SELECT flock_id, LOWER(vaccine_name) AS vn, SUM(birds_vaccinated) AS done FROM vaccinations WHERE status='in_progress' GROUP BY flock_id, LOWER(vaccine_name)")->fetchAll() as $r) {
    $pageData['progress'][$r['flock_id'].'|'.$r['vn']] = (int)$r['done'];
}
$pageData['flockQty'] = [];
foreach ($flocks as $fl) { $pageData['flockQty'][$fl['id']] = (int)$fl['quantity']; }
?>
<?php
$pageData['lastLog'] = [];
foreach ($pdo->query("SELECT flock_id, LOWER(vaccine_name) AS vn, date_given, next_due_date, lot_number, lot_expiry, method, dosage
    FROM vaccinations WHERE status IN ('in_progress','completed') ORDER BY id DESC")->fetchAll() as $r) {
    $k = $r['flock_id'].'|'.$r['vn'];
    if (!isset($pageData['lastLog'][$k])) $pageData['lastLog'][$k] = $r;
}
?>
<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
