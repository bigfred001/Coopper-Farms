<?php
require_once '../config/config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_mortality'])) {
        $flockId = (int)$_POST['flock_id'];
        $count   = (int)$_POST['count'];
        $cause   = sanitize($_POST['cause']);

        $pdo->prepare("INSERT INTO mortality_logs (flock_id, log_date, count, cause, notes, recorded_by) VALUES (?,?,?,?,?,?)")
            ->execute([$flockId, $_POST['log_date'], $count, $cause, sanitize($_POST['notes']), $_SESSION['user_id']]);
        $newMortId = $pdo->lastInsertId();
        auditLog($pdo, 'log_mortality', 'mortality', (int)$newMortId, "Logged {$count} deaths for flock #{$flockId}. Cause: {$cause}");

        // ── Auto deduct mortality from flock quantity ──
        $pdo->prepare("UPDATE flocks SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")
            ->execute([$count, $flockId]);

        // Check if flock is now depleted
        $flockCheck = $pdo->prepare("SELECT quantity, flock_name FROM flocks WHERE id = ?");
        $flockCheck->execute([$flockId]);
        $flockRow = $flockCheck->fetch();
        if ($flockRow && $flockRow['quantity'] <= 0) {
            $pdo->prepare("UPDATE flocks SET status = 'depleted' WHERE id = ?")->execute([$flockId]);
        }

        // Send admin alert if deaths exceed threshold
        if ($count >= MORTALITY_ALERT_THRESHOLD) {
            $flockStmt = $pdo->prepare("SELECT flock_name FROM flocks WHERE id = ?");
            $flockStmt->execute([$flockId]);
            $flockRow = $flockStmt->fetch();
            $mailer->sendAdminMortalityAlert($flockRow['flock_name'] ?? "Flock #$flockId", $count, $cause);
        }

        $msg = 'success:Mortality logged!';
    }
    header('Location: mortality.php?msg=' . urlencode($msg));
    exit;
}

$urlMsg = $_GET['msg'] ?? '';
$flocks = $pdo->query("SELECT * FROM flocks ORDER BY flock_name")->fetchAll();
$logs = $pdo->query("
    SELECT ml.*, f.flock_name, f.quantity as flock_qty, f.bird_type
    FROM mortality_logs ml
    JOIN flocks f ON ml.flock_id = f.id
    ORDER BY ml.log_date DESC LIMIT 60
")->fetchAll();

$totalDeathsMonth = $pdo->query("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE MONTH(log_date)=MONTH(CURDATE())")->fetchColumn();
$totalDeathsAllTime = $pdo->query("SELECT COALESCE(SUM(count),0) FROM mortality_logs")->fetchColumn();
$todayDeaths = $pdo->query("SELECT COALESCE(SUM(count),0) FROM mortality_logs WHERE log_date=CURDATE()")->fetchColumn();

// Mortality by cause
$byCause = $pdo->query("SELECT cause, SUM(count) as total FROM mortality_logs WHERE cause IS NOT NULL AND cause != '' GROUP BY cause ORDER BY total DESC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Mortality - Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
      <div><h1>Mortality Tracking</h1><p>Log and analyze bird deaths to identify patterns and causes</p></div>
      <button class="btn btn-primary" onclick="document.getElementById('addMortalityModal').classList.add('open')">
        <i class="fas fa-plus"></i> Log Mortality
      </button>
    </div>

    <?php if($urlMsg): list($type,$text) = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $type; ?>"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>

    <div class="stats-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Today</span><div class="stat-icon-small red"><i class="fas fa-skull"></i></div></div>
        <h2 style="color:<?php echo $todayDeaths > 0 ? '#ef4444' : 'inherit'; ?>"><?php echo $todayDeaths; ?></h2><p>Deaths today</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>This Month</span><div class="stat-icon-small orange"><i class="fas fa-calendar"></i></div></div>
        <h2><?php echo number_format($totalDeathsMonth); ?></h2><p>Monthly deaths</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>All Time</span><div class="stat-icon-small blue"><i class="fas fa-history"></i></div></div>
        <h2><?php echo number_format($totalDeathsAllTime); ?></h2><p>Total recorded</p>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px;">
      <div class="card">
        <div class="card-header"><h3>Mortality Log</h3></div>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr><th>Date</th><th>Flock</th><th>Type</th><th>Deaths</th><th>Cause</th><th>Mortality Rate</th><th>Notes</th></tr>
            </thead>
            <tbody>
              <?php foreach($logs as $log):
                $rate = $log['flock_qty'] > 0 ? round(($log['count'] / $log['flock_qty']) * 100, 2) : 0;
              ?>
              <tr>
                <td><?php echo date('M d, Y', strtotime($log['log_date'])); ?></td>
                <td><strong><?php echo htmlspecialchars($log['flock_name']); ?></strong></td>
                <td><?php echo ucfirst($log['bird_type']); ?></td>
                <td style="color:#ef4444;font-weight:700;"><?php echo number_format($log['count']); ?></td>
                <td><?php echo htmlspecialchars($log['cause'] ?? '—'); ?></td>
                <td>
                  <span style="font-size:12px;color:<?php echo $rate > 2 ? '#ef4444' : ($rate > 1 ? '#fbbf24' : '#4ade80'); ?>;font-weight:600;">
                    <?php echo $rate; ?>%
                  </span>
                </td>
                <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars(substr($log['notes'] ?? '', 0, 50)); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3>Top Causes</h3></div>
        <div class="card-body">
          <?php if(empty($byCause)): ?>
            <p style="text-align:center;color:#888;padding:20px;">No data yet</p>
          <?php else: ?>
            <?php $maxVal = max(array_column($byCause, 'total')); ?>
            <?php foreach($byCause as $bc):
              $pct = $maxVal > 0 ? round(($bc['total'] / $maxVal) * 100) : 0;
            ?>
            <div style="margin-bottom:16px;">
              <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                <span style="font-weight:600;"><?php echo htmlspecialchars($bc['cause']); ?></span>
                <span style="color:#888;"><?php echo number_format($bc['total']); ?> deaths</span>
              </div>
              <div class="progress-bar">
                <div class="progress-fill red" style="width:<?php echo $pct; ?>%"></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add Mortality Modal -->
<div class="modal-overlay" id="addMortalityModal">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <h3>Log Bird Mortality</h3>
      <button class="modal-close" onclick="document.getElementById('addMortalityModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_mortality" value="1">
      <div class="form-row">
        <div class="form-group">
          <label>Flock *</label>
          <select name="flock_id" class="form-control" required>
            <option value="">Select flock...</option>
            <?php foreach($flocks as $fl): ?>
            <option value="<?php echo $fl['id']; ?>"><?php echo htmlspecialchars($fl['flock_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="log_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Number of Deaths *</label>
          <input type="number" name="count" class="form-control" required min="1" value="1">
        </div>
        <div class="form-group">
          <label>Cause</label>
          <input type="text" name="cause" class="form-control" list="causeList" placeholder="e.g. Disease, Unknown">
          <datalist id="causeList">
            <option value="Disease">
            <option value="Injury">
            <option value="Predator">
            <option value="Heat Stress">
            <option value="Unknown">
            <option value="Natural">
          </datalist>
        </div>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" class="form-control" rows="3" placeholder="Describe any symptoms, circumstances..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('addMortalityModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Log Deaths</button>
      </div>
    </form>
  </div>
</div>

<script src="../public/js/admin.js"></script>
</body>
</html>
