<?php
require_once '../config/config.php';
requireAdmin();

// Handle form
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_flock'])) {
        $pdo->prepare("INSERT INTO flocks (flock_name, bird_type, quantity, arrival_date, source, housing_unit, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([sanitize($_POST['flock_name']), $_POST['bird_type'], (int)$_POST['quantity'], $_POST['arrival_date'], sanitize($_POST['source']), sanitize($_POST['housing_unit']), sanitize($_POST['notes']), $_SESSION['user_id']]);
        $newFlockId = $pdo->lastInsertId();
        auditLog($pdo, 'add_flock', 'flock', (int)$newFlockId,
            "Added flock '" . sanitize($_POST['flock_name']) . "' — " . $_POST['bird_type'] . " — qty: " . (int)$_POST['quantity']);
        $msg = 'success:Flock added successfully!';
    }
    if (isset($_POST['update_status'])) {
        $fid = (int)$_POST['flock_id'];
        $pdo->prepare("UPDATE flocks SET status=? WHERE id=?")->execute([$_POST['status'], $fid]);
        auditLog($pdo, 'update_flock_status', 'flock', $fid, "Status changed to: " . $_POST['status']);
        $msg = 'success:Flock status updated!';
    }
    header('Location: flocks.php?msg=' . urlencode($msg));
    exit;
}

$urlMsg = $_GET['msg'] ?? '';
$flocks = $pdo->query("SELECT f.*, 
    (SELECT COUNT(*) FROM vaccinations v WHERE v.flock_id=f.id) as vacc_count,
    (SELECT COALESCE(SUM(ml.count),0) FROM mortality_logs ml WHERE ml.flock_id=f.id) as total_mortality,
    (SELECT COALESCE(SUM(ep.quantity),0) FROM egg_production ep WHERE ep.flock_id=f.id) as total_eggs
    FROM flocks f ORDER BY f.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Flocks - Coopers Admin</title>
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
      <div>
        <h1>Flock Management</h1>
        <p>Track all your bird groups and their performance</p>
      </div>
      <button class="btn btn-primary" onclick="document.getElementById('addFlockModal').classList.add('open')">
        <i class="fas fa-plus"></i> Add Flock
      </button>
    </div>

    <?php if($urlMsg): list($type,$text) = explode(':', $urlMsg, 2); ?>
    <div class="alert alert-<?php echo $type; ?>"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Total Flocks</span><div class="stat-icon-small green"><i class="fas fa-layer-group"></i></div></div>
        <h2><?php echo count($flocks); ?></h2>
        <p><?php echo count(array_filter($flocks, fn($f) => $f['status']=='active')); ?> active</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Total Birds</span><div class="stat-icon-small blue"><i class="fas fa-kiwi-bird"></i></div></div>
        <h2><?php echo number_format(array_sum(array_column(array_filter($flocks, fn($f) => $f['status']=='active'), 'quantity'))); ?></h2>
        <p>Currently alive</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Total Eggs Produced</span><div class="stat-icon-small yellow"><i class="fas fa-egg"></i></div></div>
        <h2><?php echo number_format(array_sum(array_column($flocks, 'total_eggs'))); ?></h2>
        <p>All time</p>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>All Flocks</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>Flock Name</th>
              <th>Type</th>
              <th>Quantity</th>
              <th>Arrival</th>
              <th>Age</th>
              <th>Housing</th>
              <th>Vaccinations</th>
              <th>Mortality</th>
              <th>Eggs</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($flocks as $f):
              $age = round((time() - strtotime($f['arrival_date'])) / 86400);
              $ageStr = $age < 7 ? "{$age}d" : ($age < 30 ? round($age/7)."w" : round($age/30)."m");
            ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($f['flock_name']); ?></strong></td>
              <td><?php echo ucfirst($f['bird_type']); ?></td>
              <td><?php echo number_format($f['quantity']); ?></td>
              <td><?php echo date('M d, Y', strtotime($f['arrival_date'])); ?></td>
              <td><?php echo $ageStr; ?></td>
              <td><?php echo htmlspecialchars($f['housing_unit'] ?? '—'); ?></td>
              <td><?php echo $f['vacc_count']; ?></td>
              <td><?php echo $f['total_mortality']; ?></td>
              <td><?php echo number_format($f['total_eggs']); ?></td>
              <td><span class="status-badge badge-<?php echo $f['status']; ?>"><?php echo ucfirst($f['status']); ?></span></td>
              <td>
                <a href="vaccinations.php?flock_id=<?php echo $f['id']; ?>" class="btn btn-sm btn-secondary" title="Vaccinations"><i class="fas fa-syringe"></i></a>
                <button class="btn btn-sm btn-secondary" onclick="updateStatus(<?php echo $f['id']; ?>,'<?php echo $f['status']; ?>')" title="Update Status"><i class="fas fa-edit"></i></button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Add Flock Modal -->
<div class="modal-overlay" id="addFlockModal">
  <div class="modal" style="max-width:640px;">
    <div class="modal-header">
      <h3>Add New Flock</h3>
      <button class="modal-close" onclick="document.getElementById('addFlockModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_flock" value="1">
      <div class="form-row">
        <div class="form-group"><label>Flock Name *</label><input type="text" name="flock_name" class="form-control" required placeholder="e.g. Batch A - Layers"></div>
        <div class="form-group"><label>Bird Type *</label>
          <select name="bird_type" class="form-control" required>
            <option value="layer">Layer</option>
            <option value="broiler">Broiler</option>
            <option value="cockerel">Cockerel</option>
            <option value="turkey">Turkey</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Quantity *</label><input type="number" name="quantity" class="form-control" required min="1"></div>
        <div class="form-group"><label>Arrival Date *</label><input type="date" name="arrival_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Source / Supplier</label><input type="text" name="source" class="form-control" placeholder="e.g. Accra Hatchery"></div>
        <div class="form-group"><label>Housing Unit</label><input type="text" name="housing_unit" class="form-control" placeholder="e.g. House 1"></div>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="3" placeholder="Any additional information..."></textarea></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('addFlockModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Flock</button>
      </div>
    </form>
  </div>
</div>

<!-- Status Modal -->
<div class="modal-overlay" id="statusModal">
  <div class="modal" style="max-width:360px;">
    <div class="modal-header">
      <h3>Update Flock Status</h3>
      <button class="modal-close" onclick="document.getElementById('statusModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="update_status" value="1">
      <input type="hidden" name="flock_id" id="flockId">
      <div class="form-group">
        <label>Status</label>
        <select name="status" id="flockStatus" class="form-control">
          <option value="active">Active</option>
          <option value="sold">Sold</option>
          <option value="depleted">Depleted</option>
          <option value="quarantine">Quarantine</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('statusModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
</div>

<script src="../public/js/admin.js"></script>
<script>
function updateStatus(id, status) {
  document.getElementById('flockId').value = id;
  document.getElementById('flockStatus').value = status;
  document.getElementById('statusModal').classList.add('open');
}
</script>
</body>
</html>
