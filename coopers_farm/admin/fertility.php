<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_fertility'])) {
        $flockId = (int)$_POST['flock_id'];
        $set     = (int)$_POST['eggs_set'];
        $fertile = (int)$_POST['eggs_fertile'];
        $infert  = $set - $fertile;
        $pdo->prepare("INSERT INTO fertility_records (flock_id, record_date, eggs_set, eggs_fertile, eggs_infertile, notes, recorded_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$flockId, $_POST['record_date'], $set, $fertile, max(0,$infert), sanitize($_POST['notes']??''), $_SESSION['user_id']]);
        auditLog($pdo, 'add_fertility_record', 'fertility', null, "Flock #{$flockId}: {$set} set, {$fertile} fertile");
        $msg = 'success:Fertility record saved!';
    }
    header('Location: fertility.php?msg=' . urlencode($msg)); exit;
}

$urlMsg = $_GET['msg'] ?? '';
$flocks  = $pdo->query("SELECT * FROM flocks WHERE status='active' AND bird_type IN ('layer','broiler') ORDER BY flock_name")->fetchAll();
$records = $pdo->query("
    SELECT fr.*, f.flock_name, f.bird_type
    FROM fertility_records fr
    JOIN flocks f ON f.id = fr.flock_id
    ORDER BY fr.record_date DESC LIMIT 60
")->fetchAll();

$avgFertility = $pdo->query("SELECT COALESCE(AVG(fertility_rate),0) FROM fertility_records WHERE record_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
$totalEggsSet = $pdo->query("SELECT COALESCE(SUM(eggs_set),0) FROM fertility_records WHERE MONTH(record_date)=MONTH(CURDATE())")->fetchColumn();
$totalFertile = $pdo->query("SELECT COALESCE(SUM(eggs_fertile),0) FROM fertility_records WHERE MONTH(record_date)=MONTH(CURDATE())")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Fertility Tracking — Coopers Admin</title>
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
      <div><h1>Fertility Tracking</h1><p>Monitor egg fertility rates for breeder and layer flocks</p></div>
      <button class="btn btn-primary" onclick="document.getElementById('addFertModal').classList.add('open')">
        <i class="fas fa-plus"></i> Record Fertility Check
      </button>
    </div>

    <?php if($urlMsg): [$t,$tx] = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $t; ?>"><?php echo htmlspecialchars($tx); ?></div>
    <?php endif; ?>

    <div class="stats-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Avg Fertility Rate (30d)</span><div class="stat-icon-small green"><i class="fas fa-percentage"></i></div></div>
        <h2 style="color:<?php echo $avgFertility>=80?'#4ade80':($avgFertility>=65?'#fbbf24':'#ef4444'); ?>">
          <?php echo number_format($avgFertility,1); ?>%
        </h2>
        <p>Target: ≥80% for good performance</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Eggs Set (This Month)</span><div class="stat-icon-small yellow"><i class="fas fa-egg"></i></div></div>
        <h2><?php echo number_format($totalEggsSet); ?></h2><p>Total checked this month</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Fertile Eggs (This Month)</span><div class="stat-icon-small blue"><i class="fas fa-check-circle"></i></div></div>
        <h2><?php echo number_format($totalFertile); ?></h2>
        <p><?php echo $totalEggsSet>0 ? round(($totalFertile/$totalEggsSet)*100,1).'%' : '—'; ?> of total</p>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Fertility Records</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Flock</th><th>Eggs Set</th><th>Fertile</th><th>Infertile</th><th>Fertility Rate</th><th>Notes</th></tr></thead>
          <tbody>
            <?php foreach($records as $r):
              $rateColor = $r['fertility_rate']>=80?'#4ade80':($r['fertility_rate']>=65?'#fbbf24':'#ef4444');
            ?>
            <tr>
              <td><?php echo date('M d, Y',strtotime($r['record_date'])); ?></td>
              <td><strong><?php echo htmlspecialchars($r['flock_name']); ?></strong><br><small><?php echo ucfirst($r['bird_type']); ?></small></td>
              <td><?php echo number_format($r['eggs_set']); ?></td>
              <td style="color:#4ade80;font-weight:600;"><?php echo number_format($r['eggs_fertile']); ?></td>
              <td style="color:#ef4444;"><?php echo number_format($r['eggs_infertile']); ?></td>
              <td><strong style="color:<?php echo $rateColor; ?>;font-size:16px;"><?php echo $r['fertility_rate']; ?>%</strong></td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars(substr($r['notes']??'',0,60)); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($records)): ?><tr><td colspan="7" style="text-align:center;color:#888;padding:30px;">No fertility records yet</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="addFertModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">
      <h3><i class="fas fa-egg" style="color:var(--primary);margin-right:8px;"></i>Record Fertility Check</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_fertility" value="1">
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
          <label>Check Date *</label>
          <input type="date" name="record_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Eggs Set (Checked) *</label>
          <input type="number" name="eggs_set" class="form-control" required min="1" oninput="calcFert()">
        </div>
        <div class="form-group">
          <label>Fertile Eggs *</label>
          <input type="number" name="eggs_fertile" class="form-control" required min="0" oninput="calcFert()">
        </div>
      </div>
      <div id="fertPreview" style="display:none;background:#EDF5E8;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:14px;">
        Fertility Rate: <strong id="fertRate" style="color:var(--primary);font-size:18px;"></strong>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" class="form-control" rows="2" placeholder="Candling method, batch details..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
      </div>
    </form>
  </div>
</div>
<script src="../public/js/admin.js"></script>
<script>
function calcFert() {
  const set    = parseFloat(document.querySelector('[name=eggs_set]').value) || 0;
  const fert   = parseFloat(document.querySelector('[name=eggs_fertile]').value) || 0;
  const box    = document.getElementById('fertPreview');
  const rateEl = document.getElementById('fertRate');
  if (set > 0) {
    const rate = ((fert / set) * 100).toFixed(1);
    rateEl.textContent = rate + '%';
    rateEl.style.color = rate >= 80 ? '#4ade80' : (rate >= 65 ? '#fbbf24' : '#ef4444');
    box.style.display = 'block';
  } else { box.style.display = 'none'; }
}
</script>
</body>
</html>
