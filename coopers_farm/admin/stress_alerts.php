<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_alert'])) {
        $pdo->prepare("INSERT INTO stress_alerts (flock_id, alert_date, alert_type, severity, description, action_taken, recorded_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([(int)$_POST['flock_id'], $_POST['alert_date'], $_POST['alert_type'],
                       $_POST['severity'], sanitize($_POST['description']??''),
                       sanitize($_POST['action_taken']??''), $_SESSION['user_id']]);
        auditLog($pdo, 'log_stress_alert', 'stress', null,
            $_POST['alert_type'] . ' — ' . $_POST['severity'] . ' severity for flock #' . (int)$_POST['flock_id']);
        $msg = 'success:Stress alert logged!';
    }
    if (isset($_POST['resolve_alert'])) {
        $aid = (int)$_POST['alert_id'];
        $pdo->prepare("UPDATE stress_alerts SET resolved=1, resolved_at=NOW(), action_taken=CONCAT(COALESCE(action_taken,''), ?) WHERE id=?")
            ->execute(["\n[Resolved: " . sanitize($_POST['resolution']??'') . "]", $aid]);
        auditLog($pdo, 'resolve_stress_alert', 'stress', $aid, 'Resolved: ' . sanitize($_POST['resolution']??''));
        $msg = 'success:Alert marked as resolved!';
    }
    header('Location: stress_alerts.php?msg=' . urlencode($msg)); exit;
}

$urlMsg  = $_GET['msg'] ?? '';
$flocks  = $pdo->query("SELECT * FROM flocks WHERE status='active' ORDER BY flock_name")->fetchAll();
$alerts  = $pdo->query("
    SELECT sa.*, f.flock_name, f.bird_type, u.name as recorded_by_name
    FROM stress_alerts sa
    JOIN flocks f ON f.id=sa.flock_id
    LEFT JOIN users u ON u.id=sa.recorded_by
    ORDER BY sa.resolved ASC, sa.severity DESC, sa.alert_date DESC
    LIMIT 100
")->fetchAll();

$openAlerts    = count(array_filter($alerts, fn($a) => !$a['resolved']));
$criticalCount = count(array_filter($alerts, fn($a) => !$a['resolved'] && $a['severity']==='critical'));

$sevColors = ['low'=>'#4ade80','medium'=>'#fbbf24','high'=>'#e87722','critical'=>'#ef4444'];
$typeIcons = [
    'heat_stress'    => 'fas fa-thermometer-full',
    'water_shortage' => 'fas fa-tint-slash',
    'feed_shortage'  => 'fas fa-seedling',
    'disease_risk'   => 'fas fa-virus',
    'predator'       => 'fas fa-paw',
    'other'          => 'fas fa-exclamation-circle',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Stress Alerts — Coopers Admin</title>
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
      <div><h1>Stress &amp; Alert Logs</h1><p>Log and manage bird welfare incidents and early warning alerts</p></div>
      <button class="btn btn-primary" onclick="document.getElementById('addAlertModal').classList.add('open')">
        <i class="fas fa-plus"></i> Log Alert
      </button>
    </div>

    <?php if($urlMsg): [$t,$tx] = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $t; ?>"><?php echo htmlspecialchars($tx); ?></div>
    <?php endif; ?>

    <?php if($criticalCount > 0): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      <i class="fas fa-skull-crossbones"></i>
      <strong><?php echo $criticalCount; ?> CRITICAL alert(s) unresolved!</strong> Immediate action required.
    </div>
    <?php endif; ?>

    <div class="stats-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Open Alerts</span><div class="stat-icon-small red"><i class="fas fa-bell"></i></div></div>
        <h2 style="color:<?php echo $openAlerts>0?'#ef4444':'inherit'; ?>"><?php echo $openAlerts; ?></h2><p>Need attention</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Critical</span><div class="stat-icon-small red"><i class="fas fa-exclamation-triangle"></i></div></div>
        <h2 style="color:<?php echo $criticalCount>0?'#ef4444':'inherit'; ?>"><?php echo $criticalCount; ?></h2><p>Urgent response needed</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Resolved</span><div class="stat-icon-small green"><i class="fas fa-check-circle"></i></div></div>
        <h2><?php echo count(array_filter($alerts,fn($a)=>$a['resolved'])); ?></h2><p>Handled successfully</p>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>All Alerts</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Flock</th><th>Type</th><th>Severity</th><th>Description</th><th>Action Taken</th><th>Status</th><th>By</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach($alerts as $a):
              $icon  = $typeIcons[$a['alert_type']] ?? 'fas fa-exclamation-circle';
              $color = $sevColors[$a['severity']] ?? '#888';
            ?>
            <tr style="<?php echo !$a['resolved'] && $a['severity']==='critical' ? 'background:#fff5f5;' : ''; ?>">
              <td><?php echo date('M d, Y',strtotime($a['alert_date'])); ?></td>
              <td><strong><?php echo htmlspecialchars($a['flock_name']); ?></strong><br><small><?php echo ucfirst($a['bird_type']); ?></small></td>
              <td><i class="<?php echo $icon; ?>" style="color:<?php echo $color; ?>;margin-right:5px;"></i><?php echo ucwords(str_replace('_',' ',$a['alert_type'])); ?></td>
              <td><span class="status-badge" style="background:<?php echo $color; ?>22;color:<?php echo $color; ?>;"><?php echo ucfirst($a['severity']); ?></span></td>
              <td style="max-width:150px;font-size:13px;"><?php echo htmlspecialchars(substr($a['description']??'',0,60)); ?></td>
              <td style="max-width:150px;font-size:12px;color:#888;"><?php echo htmlspecialchars(substr($a['action_taken']??'—',0,60)); ?></td>
              <td>
                <?php if($a['resolved']): ?>
                  <span class="status-badge badge-active">✅ Resolved</span>
                <?php else: ?>
                  <span class="status-badge badge-pending">⚠ Open</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars($a['recorded_by_name']??'—'); ?></td>
              <td>
                <?php if(!$a['resolved']): ?>
                <button class="btn btn-sm btn-primary"
                  onclick="document.getElementById('resolveId').value=<?php echo $a['id']; ?>;document.getElementById('resolveModal').classList.add('open')">
                  <i class="fas fa-check"></i> Resolve
                </button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($alerts)): ?><tr><td colspan="9" style="text-align:center;color:#888;padding:30px;">No alerts logged</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Log Alert Modal -->
<div class="modal-overlay" id="addAlertModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h3><i class="fas fa-exclamation-triangle" style="color:#E87722;margin-right:8px;"></i>Log Stress Alert</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_alert" value="1">
      <div class="form-row">
        <div class="form-group">
          <label>Flock *</label>
          <select name="flock_id" class="form-control" required>
            <option value="">Select flock...</option>
            <?php foreach($flocks as $fl): ?><option value="<?php echo $fl['id']; ?>"><?php echo htmlspecialchars($fl['flock_name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="alert_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Alert Type *</label>
          <select name="alert_type" class="form-control" required>
            <option value="heat_stress">🌡️ Heat Stress</option>
            <option value="water_shortage">💧 Water Shortage</option>
            <option value="feed_shortage">🌾 Feed Shortage</option>
            <option value="disease_risk">🦠 Disease Risk</option>
            <option value="predator">🐾 Predator</option>
            <option value="other">⚠️ Other</option>
          </select>
        </div>
        <div class="form-group">
          <label>Severity *</label>
          <select name="severity" class="form-control" required>
            <option value="low">🟢 Low</option>
            <option value="medium" selected>🟡 Medium</option>
            <option value="high">🟠 High</option>
            <option value="critical">🔴 Critical</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" class="form-control" rows="2" placeholder="What happened? What symptoms did you observe?"></textarea>
      </div>
      <div class="form-group">
        <label>Action Taken</label>
        <textarea name="action_taken" class="form-control" rows="2" placeholder="What did you do to address it?"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Log Alert</button>
      </div>
    </form>
  </div>
</div>

<!-- Resolve Modal -->
<div class="modal-overlay" id="resolveModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <h3><i class="fas fa-check-circle" style="color:#4ade80;margin-right:8px;"></i>Resolve Alert</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="resolve_alert" value="1">
      <input type="hidden" name="alert_id" id="resolveId">
      <div class="form-group" style="margin:24px 32px 0;">
        <label>Resolution Notes *</label>
        <textarea name="resolution" class="form-control" rows="3" required placeholder="How was this resolved? What was the final outcome?"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Mark Resolved</button>
      </div>
    </form>
  </div>
</div>
<script src="../public/js/admin.js"></script>
</body>
</html>
