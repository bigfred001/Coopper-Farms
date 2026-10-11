<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_record'])) {
        $flockId      = (int)$_POST['flock_id'];
        $outcome      = $_POST['outcome'];
        $affectedCount = (int)$_POST['affected_count'];
        $recordDate   = $_POST['record_date'];
        $diseaseName  = sanitize($_POST['disease_name']);

        $pdo->prepare("INSERT INTO health_records (flock_id, record_date, type, disease_name, symptoms, treatment, treated_by, outcome, affected_count, description, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$flockId, $recordDate, $_POST['type'], $diseaseName, sanitize($_POST['symptoms']), sanitize($_POST['treatment']), sanitize($_POST['treated_by']), $outcome, $affectedCount, sanitize($_POST['description']), sanitize($_POST['notes']), $_SESSION['user_id']]);
        $newHealthId = $pdo->lastInsertId();
        auditLog($pdo, 'add_health_record', 'health', (int)$newHealthId, "Added {$_POST['type']} record for flock #{$flockId}: {$diseaseName}. Outcome: {$outcome}");

        // ── Auto-log to mortality if outcome = died ──────────────
        if ($outcome === 'died' && $affectedCount > 0) {
            $pdo->prepare("INSERT INTO mortality_logs (flock_id, log_date, count, cause, notes, recorded_by) VALUES (?,?,?,?,?,?)")
                ->execute([$flockId, $recordDate, $affectedCount,
                    $diseaseName ?: 'Disease/Health issue',
                    'Auto-logged from health record: ' . sanitize($_POST['description'] ?? ''),
                    $_SESSION['user_id']]);

            // Deduct from flock quantity
            $pdo->prepare("UPDATE flocks SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")
                ->execute([$affectedCount, $flockId]);

            // Mark flock depleted if quantity hits zero
            $pdo->prepare("UPDATE flocks SET status='depleted' WHERE id=? AND quantity=0")->execute([$flockId]);

            $msg = 'success:Health record added and ' . $affectedCount . ' deaths auto-logged to mortality!';
        } else {
            $msg = 'success:Health record added!';
        }
    }

    if (isset($_POST['update_outcome'])) {
        $rid          = (int)$_POST['record_id'];
        $newOutcome   = $_POST['outcome'];
        $newStatus    = $_POST['status'];

        // Get existing record
        $existing = $pdo->prepare("SELECT * FROM health_records WHERE id=?");
        $existing->execute([$rid]);
        $rec = $existing->fetch();

        $pdo->prepare("UPDATE health_records SET outcome=?, status=?, updated_by=? WHERE id=?")->execute([$newOutcome, $newStatus, $_SESSION['user_id'], $rid]);
        auditLog($pdo, 'update_health_record', 'health', $rid, "Updated outcome to '{$newOutcome}', status to '{$newStatus}'");

        // ── Auto-log to mortality if outcome changed TO died ──────
        if ($rec && $rec['outcome'] !== 'died' && $newOutcome === 'died' && $rec['affected_count'] > 0) {
            // Check not already logged for this record
            $alreadyLogged = $pdo->prepare("SELECT id FROM mortality_logs WHERE notes LIKE ?");
            $alreadyLogged->execute(['%Health record%auto%' . $rec['flock_id'] . '%']);
            if (!$alreadyLogged->fetch()) {
                $pdo->prepare("INSERT INTO mortality_logs (flock_id, log_date, count, cause, notes, recorded_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$rec['flock_id'], $rec['record_date'], $rec['affected_count'],
                        $rec['disease_name'] ?: 'Disease/Health issue',
                        'Auto-logged from health record update',
                        $_SESSION['user_id']]);

                $pdo->prepare("UPDATE flocks SET quantity = GREATEST(0, quantity - ?) WHERE id = ?")
                    ->execute([$rec['affected_count'], $rec['flock_id']]);

                $pdo->prepare("UPDATE flocks SET status='depleted' WHERE id=? AND quantity=0")->execute([$rec['flock_id']]);
            }
            $msg = 'success:Record updated! ' . $rec['affected_count'] . ' deaths auto-logged to mortality.';
        } else {
            $msg = 'success:Record updated!';
        }
    }
    header('Location: health.php?msg=' . urlencode($msg));
    exit;
}

$urlMsg = $_GET['msg'] ?? '';
$records = $pdo->query("
    SELECT hr.*, f.flock_name, f.bird_type 
    FROM health_records hr 
    JOIN flocks f ON hr.flock_id = f.id 
    ORDER BY hr.record_date DESC LIMIT 100
")->fetchAll();

$flocks = $pdo->query("SELECT * FROM flocks ORDER BY flock_name")->fetchAll();

$activeRecords = $pdo->query("SELECT COUNT(*) FROM health_records WHERE status='active'")->fetchColumn();
$recoveredRecords = $pdo->query("SELECT COUNT(*) FROM health_records WHERE outcome='recovered'")->fetchColumn();
$totalAffected = $pdo->query("SELECT COALESCE(SUM(affected_count),0) FROM health_records")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Health Records - Coopers Admin</title>
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
      <div><h1>Health Records</h1><p>Disease tracking, treatments and health monitoring</p></div>
      <button class="btn btn-primary" onclick="document.getElementById('addHealthModal').classList.add('open')">
        <i class="fas fa-plus"></i> Add Health Record
      </button>
    </div>

    <?php if($urlMsg): list($type,$text) = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $type; ?>"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>

    <div class="stats-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Active Cases</span><div class="stat-icon-small red"><i class="fas fa-heartbeat"></i></div></div>
        <h2 style="color:#ef4444;"><?php echo $activeRecords; ?></h2><p>Ongoing health issues</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Recovered</span><div class="stat-icon-small green"><i class="fas fa-check-circle"></i></div></div>
        <h2><?php echo $recoveredRecords; ?></h2><p>Successful treatments</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Total Affected</span><div class="stat-icon-small orange"><i class="fas fa-kiwi-bird"></i></div></div>
        <h2><?php echo number_format($totalAffected); ?></h2><p>Birds treated total</p>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Health Records (<?php echo count($records); ?>)</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr><th>Date</th><th>Flock</th><th>Type</th><th>Disease/Issue</th><th>Affected</th><th>Treatment</th><th>Treated By</th><th>Outcome</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach($records as $r): ?>
            <tr>
              <td><?php echo date('M d, Y', strtotime($r['record_date'])); ?></td>
              <td><strong><?php echo htmlspecialchars($r['flock_name']); ?></strong><br><small><?php echo ucfirst($r['bird_type']); ?></small></td>
              <td><span class="status-badge badge-confirmed"><?php echo ucfirst($r['type']); ?></span></td>
              <td><?php echo htmlspecialchars($r['disease_name'] ?: ($r['description'] ?? '—')); ?></td>
              <td><?php echo number_format($r['affected_count']); ?> birds</td>
              <td style="max-width:140px;font-size:13px;"><?php echo htmlspecialchars(substr($r['treatment'] ?? '', 0, 60)); ?></td>
              <td><?php echo htmlspecialchars($r['treated_by'] ?? '—'); ?></td>
              <td>
                <span class="status-badge <?php
                  echo $r['outcome']=='recovered' ? 'badge-active' : ($r['outcome']=='died' ? 'badge-cancelled' : ($r['outcome']=='pending' ? 'badge-pending' : 'badge-scheduled'));
                ?>">
                  <?php echo ucfirst($r['outcome'] ?? 'pending'); ?>
                </span>
                <?php if($r['outcome']==='died'): ?>
                  <br><small style="color:#ef4444;font-size:10px;"><i class="fas fa-skull"></i> Logged to Mortality</small>
                <?php endif; ?>
              </td>
              <td><span class="status-badge badge-<?php echo $r['status']; ?>"><?php echo ucfirst($r['status']); ?></span></td>
              <td>
                <button class="btn btn-sm btn-secondary" onclick="updateRecord(<?php echo $r['id']; ?>,'<?php echo $r['outcome']; ?>','<?php echo $r['status']; ?>')">
                  <i class="fas fa-edit"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Add Health Record Modal -->
<div class="modal-overlay" id="addHealthModal">
  <div class="modal" style="max-width:680px;">
    <div class="modal-header">
      <h3><i class="fas fa-heartbeat" style="color:#ef4444;margin-right:8px;"></i>Add Health Record</h3>
      <button class="modal-close" onclick="document.getElementById('addHealthModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_record" value="1">
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
          <label>Record Date *</label>
          <input type="date" name="record_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Type *</label>
          <select name="type" class="form-control" required>
            <option value="disease">Disease</option>
            <option value="injury">Injury</option>
            <option value="checkup">Checkup</option>
            <option value="treatment">Treatment</option>
          </select>
        </div>
        <div class="form-group">
          <label>Disease / Issue Name</label>
          <input type="text" name="disease_name" class="form-control" placeholder="e.g. Newcastle, Coccidiosis">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Birds Affected</label>
          <input type="number" name="affected_count" class="form-control" min="0" value="0">
        </div>
        <div class="form-group">
          <label>Treated By</label>
          <input type="text" name="treated_by" class="form-control" placeholder="Vet or farm worker name">
        </div>
      </div>
      <div class="form-group">
        <label>Symptoms Observed</label>
        <textarea name="symptoms" class="form-control" rows="2" placeholder="Describe symptoms..."></textarea>
      </div>
      <div class="form-group">
        <label>Treatment Given</label>
        <textarea name="treatment" class="form-control" rows="2" placeholder="Medications, dosage, procedure..."></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Outcome</label>
          <select name="outcome" class="form-control">
            <option value="pending">Pending</option>
            <option value="ongoing">Ongoing</option>
            <option value="recovered">Recovered</option>
            <option value="died">Died</option>
          </select>
        </div>
        <div class="form-group">
          <label>Description</label>
          <input type="text" name="description" class="form-control" placeholder="Brief description">
        </div>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('addHealthModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Record</button>
      </div>
    </form>
  </div>
</div>

<!-- Update Record Modal -->
<div class="modal-overlay" id="updateModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <h3>Update Health Record</h3>
      <button class="modal-close" onclick="document.getElementById('updateModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="update_outcome" value="1">
      <input type="hidden" name="record_id" id="recordId">
      <div class="form-group">
        <label>Outcome</label>
        <select name="outcome" id="recordOutcome" class="form-control" onchange="checkDiedOutcome(this)">
          <option value="pending">Pending</option>
          <option value="ongoing">Ongoing</option>
          <option value="recovered">Recovered</option>
          <option value="died">Died</option>
        </select>
        <div id="diedWarning" style="display:none;margin-top:8px;background:#fee2e2;border-radius:8px;padding:10px 12px;font-size:13px;color:#991b1b;">
          <i class="fas fa-exclamation-triangle"></i>
          <strong>Mortality will be auto-logged!</strong> The affected birds count will be recorded in the Mortality Log and deducted from the flock automatically.
        </div>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="status" id="recordStatus" class="form-control">
          <option value="active">Active</option>
          <option value="completed">Completed</option>
          <option value="monitoring">Monitoring</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('updateModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
</div>

<script src="../public/js/admin.js"></script>
<script>
function updateRecord(id, outcome, status) {
  document.getElementById('recordId').value = id;
  document.getElementById('recordOutcome').value = outcome;
  document.getElementById('recordStatus').value = status;
  checkDiedOutcome(document.getElementById('recordOutcome'));
  document.getElementById('updateModal').classList.add('open');
}

function checkDiedOutcome(sel) {
  const warn = document.getElementById('diedWarning');
  if (warn) warn.style.display = sel.value === 'died' ? 'block' : 'none';
}

// Also wire up the add form
const addOutcomeSelect = document.querySelector('#addHealthModal select[name="outcome"]');
if (addOutcomeSelect) {
  addOutcomeSelect.addEventListener('change', function() {
    let warn = document.getElementById('addDiedWarning');
    if (!warn) {
      warn = document.createElement('div');
      warn.id = 'addDiedWarning';
      warn.style.cssText = 'display:none;margin-top:8px;background:#fee2e2;border-radius:8px;padding:10px 12px;font-size:13px;color:#991b1b;';
      warn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <strong>Auto-logs to Mortality!</strong> Deaths will be recorded and flock count updated.';
      this.parentNode.appendChild(warn);
    }
    warn.style.display = this.value === 'died' ? 'block' : 'none';
  });
}
</script>
</body>
</html>
