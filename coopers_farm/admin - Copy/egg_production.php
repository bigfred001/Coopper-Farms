<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_production'])) {
        // Check for duplicate date/flock
        $check = $pdo->prepare("SELECT id FROM egg_production WHERE flock_id=? AND production_date=?");
        $check->execute([(int)$_POST['flock_id'], $_POST['production_date']]);
        $qty    = (int)$_POST['quantity'];
        $broken = (int)$_POST['broken_eggs'];
        $flockId = (int)$_POST['flock_id'];

        if ($check->fetch()) {
            $pdo->prepare("UPDATE egg_production SET quantity=?, broken_eggs=?, notes=? WHERE flock_id=? AND production_date=?")
                ->execute([$qty, $broken, sanitize($_POST['notes']), $flockId, $_POST['production_date']]);
            $msg = 'success:Production record updated!';
        } else {
            $pdo->prepare("INSERT INTO egg_production (flock_id, production_date, quantity, broken_eggs, notes, recorded_by) VALUES (?,?,?,?,?,?)")
                ->execute([$flockId, $_POST['production_date'], $qty, $broken, sanitize($_POST['notes']), $_SESSION['user_id']]);
            $newEggId = $pdo->lastInsertId();
            auditLog($pdo, 'log_egg_production', 'egg_production', (int)$newEggId, "Logged {$qty} eggs ({$broken} broken) for flock #{$flockId} on {$_POST['production_date']}");
            $msg = 'success:Production logged!';
        }

        // ── Auto-restock egg products based on new production ──
        $goodEggs = max(0, $qty - $broken);
        if ($goodEggs > 0) {
            $cratesProduced = floor($goodEggs / 30);
            $halfCrates     = floor(($goodEggs % 30) / 15);
            if ($cratesProduced > 0) {
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE category='eggs' AND (name LIKE '%Crate of 30%' OR name LIKE '%crate%') AND is_active=1 LIMIT 1")
                    ->execute([$cratesProduced]);
                $pdo->prepare("INSERT INTO inventory_logs (product_id, change_amount, reason, notes, created_by) SELECT id, ?, 'production', 'Auto-restock from egg production', ? FROM products WHERE category='eggs' AND (name LIKE '%Crate of 30%' OR name LIKE '%crate%') AND is_active=1 LIMIT 1")
                    ->execute([$cratesProduced, $_SESSION['user_id']]);
            }
            if ($halfCrates > 0) {
                $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE category='eggs' AND name LIKE '%Half%' AND is_active=1 LIMIT 1")
                    ->execute([$halfCrates]);
            }
        }
    }
    header('Location: egg_production.php?msg=' . urlencode($msg));
    exit;
}

$urlMsg = $_GET['msg'] ?? '';
$flocks = $pdo->query("SELECT * FROM flocks WHERE bird_type='layer' AND status='active' ORDER BY flock_name")->fetchAll();

// Summary stats
$todayTotal = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE production_date=CURDATE()")->fetchColumn();
$weekTotal = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE YEARWEEK(production_date)=YEARWEEK(CURDATE())")->fetchColumn();
$monthTotal = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE MONTH(production_date)=MONTH(CURDATE()) AND YEAR(production_date)=YEAR(CURDATE())")->fetchColumn();
$avgDaily = $pdo->query("SELECT COALESCE(AVG(daily_qty),0) FROM (SELECT production_date, SUM(quantity) as daily_qty FROM egg_production WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY production_date) t")->fetchColumn();

// Records with flock info
$records = $pdo->query("
    SELECT ep.*, f.flock_name, f.quantity as flock_qty
    FROM egg_production ep
    JOIN flocks f ON ep.flock_id = f.id
    ORDER BY ep.production_date DESC LIMIT 60
")->fetchAll();

// Chart data (last 14 days)
$chartData = $pdo->query("
    SELECT production_date as date, SUM(quantity) as qty, SUM(broken_eggs) as broken
    FROM egg_production 
    WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY production_date ORDER BY production_date
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Egg Production - Coopers Admin</title>
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
      <div><h1>Egg Production</h1><p>Track daily egg collection per flock</p></div>
      <button class="btn btn-primary" onclick="document.getElementById('addEggModal').classList.add('open')">
        <i class="fas fa-plus"></i> Log Production
      </button>
    </div>

    <?php if($urlMsg): list($type,$text) = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $type; ?>"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>

    <div class="stats-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Today</span><div class="stat-icon-small yellow"><i class="fas fa-egg"></i></div></div>
        <h2><?php echo number_format($todayTotal); ?></h2><p>Eggs collected</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>This Week</span><div class="stat-icon-small green"><i class="fas fa-calendar-week"></i></div></div>
        <h2><?php echo number_format($weekTotal); ?></h2><p>Weekly total</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>This Month</span><div class="stat-icon-small blue"><i class="fas fa-calendar-alt"></i></div></div>
        <h2><?php echo number_format($monthTotal); ?></h2><p>Monthly total</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Daily Average</span><div class="stat-icon-small purple"><i class="fas fa-chart-line"></i></div></div>
        <h2><?php echo number_format(round($avgDaily)); ?></h2><p>Last 30 days</p>
      </div>
    </div>

    <!-- Chart -->
    <div class="card" style="margin-bottom:24px;">
      <div class="card-header"><h3>Production Trend — Last 14 Days</h3></div>
      <div class="card-body">
        <canvas id="eggTrendChart" height="70"></canvas>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Production Records</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr><th>Date</th><th>Flock</th><th>Collected</th><th>Broken</th><th>Good Eggs</th><th>Productivity %</th><th>Notes</th></tr>
          </thead>
          <tbody>
            <?php foreach($records as $r):
              $goodEggs = $r['quantity'] - $r['broken_eggs'];
              $pct = $r['flock_qty'] > 0 ? round(($r['quantity'] / $r['flock_qty']) * 100, 1) : 0;
              $pctColor = $pct >= 70 ? '#4ade80' : ($pct >= 50 ? '#fbbf24' : '#f87171');
            ?>
            <tr>
              <td><?php echo date('M d, Y', strtotime($r['production_date'])); ?></td>
              <td><strong><?php echo htmlspecialchars($r['flock_name']); ?></strong></td>
              <td><strong><?php echo number_format($r['quantity']); ?></strong></td>
              <td style="color:#f87171;"><?php echo $r['broken_eggs']; ?></td>
              <td style="color:#4ade80;"><?php echo number_format($goodEggs); ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px;">
                  <div class="progress-bar" style="width:80px;">
                    <div class="progress-fill" style="width:<?php echo min(100,$pct); ?>%;background:<?php echo $pctColor; ?>;"></div>
                  </div>
                  <span style="font-size:12px;color:<?php echo $pctColor; ?>;font-weight:600;"><?php echo $pct; ?>%</span>
                </div>
              </td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars(substr($r['notes'] ?? '', 0, 50)); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Add Production Modal -->
<div class="modal-overlay" id="addEggModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">
      <h3>🥚 Log Egg Production</h3>
      <button class="modal-close" onclick="document.getElementById('addEggModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_production" value="1">
      <div class="form-group">
        <label>Flock (Layer) *</label>
        <select name="flock_id" class="form-control" required>
          <option value="">Select layer flock...</option>
          <?php foreach($flocks as $fl): ?>
          <option value="<?php echo $fl['id']; ?>"><?php echo htmlspecialchars($fl['flock_name']); ?> (<?php echo number_format($fl['quantity']); ?> birds)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="production_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-group">
          <label>Eggs Collected *</label>
          <input type="number" name="quantity" class="form-control" required min="0">
        </div>
      </div>
      <div class="form-group">
        <label>Broken Eggs</label>
        <input type="number" name="broken_eggs" class="form-control" min="0" value="0">
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" class="form-control" rows="2" placeholder="Any observations..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('addEggModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="../public/js/admin.js"></script>
<script>
const chartData = <?php echo json_encode($chartData); ?>;
const ctx = document.getElementById('eggTrendChart');
if(ctx) {
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: chartData.map(d => new Date(d.date).toLocaleDateString('en-GB',{month:'short',day:'numeric'})),
      datasets: [
        {
          label: 'Eggs Collected',
          data: chartData.map(d => parseInt(d.qty)),
          backgroundColor: '#7EAD6E',
          borderRadius: 6
        },
        {
          label: 'Broken Eggs',
          data: chartData.map(d => parseInt(d.broken)),
          backgroundColor: '#f87171',
          borderRadius: 6
        }
      ]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'top' } },
      scales: {
        y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
        x: { grid: { display: false } }
      }
    }
  });
}
</script>
</body>
</html>
