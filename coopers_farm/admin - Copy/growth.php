<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_growth'])) {
        $flockId = (int)$_POST['flock_id'];
        $weight  = (float)$_POST['avg_weight_g'];
        $feed    = (float)($_POST['feed_consumed_kg'] ?? 0);
        $pdo->prepare("INSERT INTO growth_records (flock_id, record_date, avg_weight_g, sample_size, feed_consumed_kg, notes, recorded_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$flockId, $_POST['record_date'], $weight, (int)$_POST['sample_size'], $feed, sanitize($_POST['notes'] ?? ''), $_SESSION['user_id']]);
        auditLog($pdo, 'add_growth_record', 'growth', null, "Flock #{$flockId}: avg weight {$weight}g, FCR logged");
        $msg = 'success:Growth record saved!';
    }
    header('Location: growth.php?msg=' . urlencode($msg)); exit;
}

$urlMsg = $_GET['msg'] ?? '';
$flocks = $pdo->query("SELECT * FROM flocks WHERE status='active' ORDER BY flock_name")->fetchAll();

$records = $pdo->query("
    SELECT gr.*, f.flock_name, f.bird_type,
           DATEDIFF(gr.record_date, f.arrival_date) as age_days
    FROM growth_records gr
    JOIN flocks f ON f.id = gr.flock_id
    ORDER BY gr.record_date DESC LIMIT 80
")->fetchAll();

// Chart data – weight over time per flock
$chartData = $pdo->query("
    SELECT gr.flock_id, f.flock_name, gr.record_date, gr.avg_weight_g, gr.fcr
    FROM growth_records gr JOIN flocks f ON f.id=gr.flock_id
    ORDER BY gr.flock_id, gr.record_date
")->fetchAll();

$flockCharts = [];
foreach ($chartData as $r) {
    $flockCharts[$r['flock_name']]['dates'][]   = $r['record_date'];
    $flockCharts[$r['flock_name']]['weights'][] = (float)$r['avg_weight_g'];
}

$avgWeight = $pdo->query("SELECT COALESCE(AVG(avg_weight_g),0) FROM growth_records WHERE record_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
$bestFCR   = $pdo->query("SELECT COALESCE(MIN(fcr),0) FROM growth_records WHERE fcr > 0 AND record_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)")->fetchColumn();
$totalRecs = count($records);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Growth Tracking — Coopers Admin</title>
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
        <h1>Growth Tracking</h1>
        <p>Monitor bird weight, Feed Conversion Ratio (FCR) and growth performance</p>
      </div>
      <button class="btn btn-primary" onclick="document.getElementById('addGrowthModal').classList.add('open')">
        <i class="fas fa-plus"></i> Record Weight
      </button>
    </div>

    <?php if($urlMsg): [$t,$tx] = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $t; ?>"><?php echo htmlspecialchars($tx); ?></div>
    <?php endif; ?>

    <!-- Info box -->
    <div class="alert alert-info" style="margin-bottom:20px;background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;">
      <i class="fas fa-info-circle"></i>
      <strong>FCR (Feed Conversion Ratio)</strong> = Feed consumed (kg) ÷ Weight gain (kg). 
      Lower FCR = more efficient. Broilers typically target FCR of 1.6–1.9. Layers: 2.0–2.5.
    </div>

    <div class="stats-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Avg Weight (30 days)</span><div class="stat-icon-small blue"><i class="fas fa-weight"></i></div></div>
        <h2><?php echo number_format($avgWeight,0); ?>g</h2><p>Average across all flocks</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Best FCR (30 days)</span><div class="stat-icon-small green"><i class="fas fa-chart-line"></i></div></div>
        <h2><?php echo $bestFCR > 0 ? number_format($bestFCR,2) : '—'; ?></h2><p>Lower is better</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Total Records</span><div class="stat-icon-small purple"><i class="fas fa-clipboard"></i></div></div>
        <h2><?php echo $totalRecs; ?></h2><p>All growth logs</p>
      </div>
    </div>

    <!-- Weight chart -->
    <?php if(!empty($flockCharts)): ?>
    <div class="card" style="margin-bottom:24px;">
      <div class="card-header"><h3>Weight Progression by Flock</h3></div>
      <div class="card-body"><canvas id="growthChart" height="80"></canvas></div>
    </div>
    <?php endif; ?>

    <!-- Records table -->
    <div class="card">
      <div class="card-header"><h3>Growth Records</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr><th>Date</th><th>Flock</th><th>Type</th><th>Age (days)</th><th>Avg Weight</th><th>Sample</th><th>Feed Used</th><th>FCR</th><th>Notes</th></tr>
          </thead>
          <tbody>
            <?php foreach($records as $r):
              $fcrColor = $r['fcr'] > 0 ? ($r['fcr'] <= 1.9 ? '#4ade80' : ($r['fcr'] <= 2.4 ? '#fbbf24' : '#ef4444')) : '#888';
            ?>
            <tr>
              <td><?php echo date('M d, Y',strtotime($r['record_date'])); ?></td>
              <td><strong><?php echo htmlspecialchars($r['flock_name']); ?></strong></td>
              <td><?php echo ucfirst($r['bird_type']); ?></td>
              <td><?php echo $r['age_days'] >= 0 ? $r['age_days'].'d' : '—'; ?></td>
              <td><strong><?php echo number_format($r['avg_weight_g'],0); ?>g</strong></td>
              <td><?php echo $r['sample_size'] ? number_format($r['sample_size']).' birds' : '—'; ?></td>
              <td><?php echo $r['feed_consumed_kg'] > 0 ? number_format($r['feed_consumed_kg'],1).'kg' : '—'; ?></td>
              <td>
                <?php if($r['fcr'] > 0): ?>
                  <strong style="color:<?php echo $fcrColor; ?>"><?php echo number_format($r['fcr'],2); ?></strong>
                <?php else: echo '—'; endif; ?>
              </td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars(substr($r['notes']??'',0,50)); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($records)): ?><tr><td colspan="9" style="text-align:center;color:#888;padding:30px;">No growth records yet</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addGrowthModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h3><i class="fas fa-weight" style="color:var(--primary);margin-right:8px;"></i>Record Bird Weight</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_growth" value="1">
      <div class="form-row">
        <div class="form-group">
          <label>Flock *</label>
          <select name="flock_id" class="form-control" required>
            <option value="">Select flock...</option>
            <?php foreach($flocks as $fl): ?>
            <option value="<?php echo $fl['id']; ?>"><?php echo htmlspecialchars($fl['flock_name']); ?> (<?php echo ucfirst($fl['bird_type']); ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="record_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Average Weight (grams) *</label>
          <input type="number" name="avg_weight_g" class="form-control" required min="1" step="0.1" placeholder="e.g. 2100">
          <small style="color:#888;">Weigh a sample of birds and calculate average</small>
        </div>
        <div class="form-group">
          <label>Sample Size (birds weighed)</label>
          <input type="number" name="sample_size" class="form-control" min="1" placeholder="e.g. 20">
        </div>
      </div>
      <div class="form-group">
        <label>Feed Consumed (kg) — since last record</label>
        <input type="number" name="feed_consumed_kg" class="form-control" min="0" step="0.1" placeholder="Total kg fed since last weigh-in">
        <small style="color:#888;">Used to calculate FCR automatically</small>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" class="form-control" rows="2" placeholder="Observations, weather conditions..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Record</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="../public/js/admin.js"></script>
<script>
const flockCharts = <?php echo json_encode($flockCharts); ?>;
const colors = ['#4A7C59','#E87722','#3b82f6','#a855f7','#ef4444','#06b6d4'];
const ctx = document.getElementById('growthChart');
if (ctx && Object.keys(flockCharts).length > 0) {
  const datasets = Object.entries(flockCharts).map(([name, data], i) => ({
    label: name,
    data: data.weights,
    borderColor: colors[i % colors.length],
    backgroundColor: 'transparent',
    tension: 0.4, borderWidth: 2, pointRadius: 4
  }));
  // Use first flock's dates as labels
  const labels = Object.values(flockCharts)[0].dates.map(d =>
    new Date(d).toLocaleDateString('en-GB',{month:'short',day:'numeric'})
  );
  new Chart(ctx, {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      plugins: { legend: { position: 'top' } },
      scales: {
        y: { beginAtZero: false, grid: { color: '#f3f4f6' }, title: { display: true, text: 'Weight (g)' } },
        x: { grid: { display: false } }
      }
    }
  });
}
</script>
</body>
</html>
