<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['start_day'])) {
        $open = currentDayRow($pdo);
        if ($open) {
            $msg = 'error:The day for ' . date('d M Y', strtotime($open['day_date'])) . ' is still open. End it first.';
        } elseif (dayEndedToday($pdo)) {
            $msg = 'error:The day for ' . date('d M Y') . ' has already been started and ended. Each day runs once — the next day starts on the next system date.';
        } else {
            $pdo->prepare("INSERT INTO day_log (day_date, status, started_at, started_by) VALUES (CURDATE(), 'open', NOW(), ?)")
                ->execute([$_SESSION['user_id']]);
            $id = (int)$pdo->lastInsertId();
            auditLog($pdo, 'start_day', 'day', $id, 'Day started by ' . ($_SESSION['name'] ?? 'admin'));
            $msg = 'success:Day started. You can log now.';
        }
    }

    if (isset($_POST['end_day'])) {
        $open = currentDayRow($pdo);
        if (!$open) {
            $msg = 'error:There is no open day to end.';
        } else {
            $pending = dayPendingDeliveries($pdo);
            if ($pending) {
                $list = implode('; ', array_map(fn($o) => $o['order_number'] . ' (' . $o['customer_name'] . ')', $pending));
                header('Location: day.php?msg=' . urlencode('error:Complete these deliveries before ending the day: ' . $list)); exit;
            }
            // The report is built from what was logged since the day started, then the day is closed
            $uid    = (int)$_SESSION['user_id'];
            $report = buildDayReport($pdo, $open);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO day_reports (day_log_id, generated_at, generated_by, report_json) VALUES (?, NOW(), ?, ?)")
                ->execute([(int)$open['id'], $uid, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
            $reportId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE day_log SET status='closed', ended_at=NOW(), ended_by=? WHERE id=?")
                ->execute([$uid, (int)$open['id']]);
            $pdo->commit();
            auditLog($pdo, 'end_day', 'day', (int)$open['id'], 'Day ended by ' . ($_SESSION['name'] ?? 'admin') . '. Report #' . $reportId);
            header('Location: day_report.php?id=' . $reportId . '&ended=' . urlencode(date('d M Y', strtotime($open['day_date'])))); exit;
        }
    }

    header('Location: day.php?msg=' . urlencode($msg)); exit;
}

$urlMsg = $_GET['msg'] ?? '';
$open   = currentDayRow($pdo);
$endedToday = dayEndedToday($pdo);
$pendingDel = $open ? dayPendingDeliveries($pdo) : [];
$history = $pdo->query("SELECT d.*, u1.name AS starter, u2.name AS ender,
        (SELECT r.id FROM day_reports r WHERE r.day_log_id = d.id ORDER BY r.id DESC LIMIT 1) AS report_id
    FROM day_log d LEFT JOIN users u1 ON u1.id = d.started_by LEFT JOIN users u2 ON u2.id = d.ended_by
    ORDER BY d.id DESC LIMIT 20")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Start &amp; End of Day — Coopers Admin</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../public/css/admin.css?v=8">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
<?php include 'includes/topbar.php'; ?>
<div class="dashboard-content dash">

<div class="dash-head">
  <div>
    <h1>Start &amp; End of Day</h1>
    <p>Start the day to allow logging. End it to generate the day's report. You can do either at any time.</p>
  </div>
</div>

<?php if($urlMsg): [$t,$tx]=explode(':',$urlMsg,2); ?>
<div class="alert alert-<?php echo $t; ?>" style="margin-bottom:14px;"><i class="fas fa-<?php echo $t==='error'?'exclamation-circle':'check-circle'; ?>"></i> <?php echo htmlspecialchars($tx); ?></div>
<?php endif; ?>

<?php if(!$open): ?>
<section class="card dash-card day-card">
  <div class="day-state closed"><i class="fas fa-moon"></i>
    <div><strong>Day not started</strong>
    <span>System date: <?php echo date('d M Y'); ?>. <?php echo $endedToday ? 'This day has already been started and ended. The next day starts on the next system date.' : 'Logging is off. Start the day to begin logging.'; ?></span></div>
  </div>
  <form method="POST">
    <input type="hidden" name="start_day" value="1">
    <button type="submit" class="btn btn-primary day-allow" <?php echo $endedToday ? 'disabled' : ''; ?>><i class="fas fa-sun"></i> Start day for <?php echo date('d M Y'); ?></button>
  </form>
</section>
<?php else: ?>
<section class="card dash-card day-card">
  <div class="day-state open"><i class="fas fa-sun"></i>
    <div><strong>Day open — <?php echo date('l, d M Y', strtotime($open['day_date'])); ?></strong>
    <span>Started at <?php echo date('H:i', strtotime($open['started_at'])); ?>. When you're done logging, end the day.</span></div>
  </div>
</section>

<?php if($pendingDel): ?>
  <div class="lot-flag lot-expired" style="margin-bottom:12px;">
    <strong><?php echo count($pendingDel); ?> delivery(ies) not yet delivered.</strong> The day cannot end until they are delivered or cancelled:
    <?php foreach($pendingDel as $o): ?><div><?php echo htmlspecialchars($o['order_number']); ?> — <?php echo htmlspecialchars($o['customer_name']); ?> (<?php echo htmlspecialchars($o['customer_phone']); ?>) · <?php echo ucwords(str_replace('_',' ',$o['order_status'])); ?> · GHS <?php echo number_format((float)$o['total_amount'],2); ?></div><?php endforeach; ?>
    <a href="orders.php" style="font-weight:700;">Open orders →</a>
  </div>
<?php endif; ?>
<form method="POST">
  <input type="hidden" name="end_day" value="1">
  <p class="dc-hint" style="margin:0 0 12px;">Ending the day creates a report of everything logged since it was started, then logging stops.</p>
  <button type="submit" class="btn btn-primary day-allow" <?php echo $pendingDel ? 'disabled' : ''; ?>><i class="fas fa-file-alt"></i> End day and generate report</button>
</form>
<?php endif; ?>


<section class="card dash-card">
  <div class="dc-head"><h3>Recent days</h3></div>
  <table class="dash-table">
    <thead><tr><th>Date</th><th>Started</th><th>By</th><th>Ended</th><th>By</th><th>Report</th><th>Status</th></tr></thead>
    <tbody>
    <?php if(empty($history)): ?>
      <tr><td colspan="7" class="dc-empty">No days recorded yet.</td></tr>
    <?php else: foreach($history as $h): ?>
      <tr>
        <td><?php echo date('d M Y', strtotime($h['day_date'])); ?></td>
        <td><?php echo date('H:i', strtotime($h['started_at'])); ?></td>
        <td><?php echo htmlspecialchars($h['starter'] ?? '—'); ?></td>
        <td><?php echo $h['ended_at'] ? date('H:i', strtotime($h['ended_at'])) : '—'; ?></td>
        <td><?php echo htmlspecialchars($h['ender'] ?? '—'); ?></td>
        <td><?php echo $h['report_id'] ? '<a href="day_report.php?id='.(int)$h['report_id'].'">View</a>' : '—'; ?></td>
        <td><span class="status-dot <?php echo $h['status']==='open'?'green':'grey'; ?>"></span><?php echo ucfirst($h['status']); ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</section>

</div>
</div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
