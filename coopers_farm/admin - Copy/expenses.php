<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_expense'])) {
        $pdo->prepare("INSERT INTO expenses (category, description, amount, expense_date, supplier_id, flock_id, receipt_ref, notes, recorded_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$_POST['category'], sanitize($_POST['description']), (float)$_POST['amount'],
                       $_POST['expense_date'], $_POST['supplier_id']?:(null), $_POST['flock_id']?:(null),
                       sanitize($_POST['receipt_ref']??''), sanitize($_POST['notes']??''), $_SESSION['user_id']]);
        auditLog($pdo, 'add_expense', 'expense', null,
            sanitize($_POST['category']) . ': ' . sanitize($_POST['description']) . ' — ' . formatCurrency((float)$_POST['amount']));
        $msg = 'success:Expense recorded!';
    }
    header('Location: expenses.php?msg=' . urlencode($msg)); exit;
}

$urlMsg     = $_GET['msg']     ?? '';
$monthFrom  = $_GET['from']    ?? date('Y-m-01');
$monthTo    = $_GET['to']      ?? date('Y-m-d');

$flocks    = $pdo->query("SELECT * FROM flocks ORDER BY flock_name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers WHERE is_active=1 ORDER BY name")->fetchAll();

$expenses  = $pdo->query("
    SELECT e.*, s.name as supplier_name, f.flock_name, u.name as recorded_by_name
    FROM expenses e
    LEFT JOIN suppliers s ON s.id = e.supplier_id
    LEFT JOIN flocks f    ON f.id = e.flock_id
    LEFT JOIN users u     ON u.id = e.recorded_by
    WHERE e.expense_date BETWEEN '$monthFrom' AND '$monthTo'
    ORDER BY e.expense_date DESC
")->fetchAll();

// Revenue for the same period
$revenue   = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date BETWEEN '$monthFrom' AND '$monthTo'")->fetchColumn();
$totalExp  = array_sum(array_column($expenses,'amount'));
$profit    = $revenue - $totalExp;

// Breakdown by category
$byCategory = $pdo->query("
    SELECT category, SUM(amount) as total, COUNT(*) as count
    FROM expenses WHERE expense_date BETWEEN '$monthFrom' AND '$monthTo'
    GROUP BY category ORDER BY total DESC
")->fetchAll();

$catColors = ['feed'=>'#4A7C59','medicine'=>'#3b82f6','labour'=>'#E87722','equipment'=>'#a855f7','utilities'=>'#06b6d4','transport'=>'#fbbf24','other'=>'#9ca3af'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Expenses & Profit — Coopers Admin</title>
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
      <div><h1>Expenses &amp; Profit Analysis</h1><p>Track all farm costs and calculate profitability</p></div>
      <button class="btn btn-primary" onclick="document.getElementById('addExpModal').classList.add('open')">
        <i class="fas fa-plus"></i> Add Expense
      </button>
    </div>

    <?php if($urlMsg): [$t,$tx] = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $t; ?>"><?php echo htmlspecialchars($tx); ?></div>
    <?php endif; ?>

    <!-- Date filter -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-body" style="padding:14px 20px;">
        <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <label style="font-size:13px;font-weight:600;">Period:</label>
          <input type="date" name="from" class="form-control" value="<?php echo $monthFrom; ?>" style="width:150px;">
          <input type="date" name="to"   class="form-control" value="<?php echo $monthTo; ?>"   style="width:150px;">
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
          <a href="?from=<?php echo date('Y-m-01'); ?>&to=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary btn-sm">This Month</a>
          <a href="?from=<?php echo date('Y-01-01'); ?>&to=<?php echo date('Y-12-31'); ?>" class="btn btn-secondary btn-sm">This Year</a>
        </form>
      </div>
    </div>

    <!-- P&L Summary -->
    <div class="stats-row" style="margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Total Revenue</span><div class="stat-icon-small green"><i class="fas fa-arrow-up"></i></div></div>
        <h2 style="color:#4ade80;"><?php echo formatCurrency($revenue); ?></h2><p>Sales income</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Total Expenses</span><div class="stat-icon-small red"><i class="fas fa-arrow-down"></i></div></div>
        <h2 style="color:#ef4444;"><?php echo formatCurrency($totalExp); ?></h2><p><?php echo count($expenses); ?> expense entries</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Gross Profit</span><div class="stat-icon-small <?php echo $profit>=0?'blue':'red'; ?>"><i class="fas fa-coins"></i></div></div>
        <h2 style="color:<?php echo $profit>=0?'#3b82f6':'#ef4444'; ?>"><?php echo formatCurrency(abs($profit)); ?></h2>
        <p><?php echo $profit>=0?'Profit ✅':'Loss ⚠️'; ?></p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Profit Margin</span><div class="stat-icon-small purple"><i class="fas fa-percentage"></i></div></div>
        <h2 style="color:<?php echo $profit>=0?'#4ade80':'#ef4444'; ?>">
          <?php echo $revenue>0 ? round(($profit/$revenue)*100,1).'%' : '—'; ?>
        </h2>
        <p>Net margin this period</p>
      </div>
    </div>

    <!-- Breakdown -->
    <?php if(!empty($byCategory)): ?>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px;">
      <div class="card">
        <div class="card-header"><h3>Expense Breakdown by Category</h3></div>
        <div class="card-body">
          <?php foreach($byCategory as $bc):
            $pct = $totalExp > 0 ? round(($bc['total']/$totalExp)*100) : 0;
            $color = $catColors[$bc['category']] ?? '#9ca3af';
          ?>
          <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:5px;">
              <span style="font-weight:600;"><?php echo ucfirst($bc['category']); ?> (<?php echo $bc['count']; ?>)</span>
              <span style="font-weight:700;color:<?php echo $color; ?>"><?php echo formatCurrency($bc['total']); ?> <small style="color:#888;"><?php echo $pct; ?>%</small></span>
            </div>
            <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><h3>P&amp;L Summary</h3></div>
        <div class="card-body"><canvas id="plChart" height="220"></canvas></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Expense log -->
    <div class="card">
      <div class="card-header"><h3>Expense Log (<?php echo count($expenses); ?>)</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Flock</th><th>Supplier</th><th>Ref</th><th>By</th></tr></thead>
          <tbody>
            <?php foreach($expenses as $e): ?>
            <tr>
              <td><?php echo date('M d, Y',strtotime($e['expense_date'])); ?></td>
              <td><span class="status-badge badge-confirmed" style="background:<?php echo ($catColors[$e['category']]??'#9ca3af').'22'; ?>;color:<?php echo $catColors[$e['category']]??'#555'; ?>;"><?php echo ucfirst($e['category']); ?></span></td>
              <td><?php echo htmlspecialchars($e['description']); ?></td>
              <td><strong style="color:#ef4444;"><?php echo formatCurrency($e['amount']); ?></strong></td>
              <td><?php echo htmlspecialchars($e['flock_name']??'—'); ?></td>
              <td><?php echo htmlspecialchars($e['supplier_name']??'—'); ?></td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars($e['receipt_ref']??'—'); ?></td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars($e['recorded_by_name']??'—'); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($expenses)): ?><tr><td colspan="8" style="text-align:center;color:#888;padding:30px;">No expenses recorded in this period</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Add Expense Modal -->
<div class="modal-overlay" id="addExpModal">
  <div class="modal" style="max-width:600px;">
    <div class="modal-header">
      <h3><i class="fas fa-receipt" style="color:var(--primary);margin-right:8px;"></i>Add Expense</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_expense" value="1">
      <div class="form-row">
        <div class="form-group">
          <label>Category *</label>
          <select name="category" class="form-control" required>
            <option value="feed">Feed</option>
            <option value="medicine">Medicine / Vaccines</option>
            <option value="labour">Labour / Wages</option>
            <option value="equipment">Equipment</option>
            <option value="utilities">Utilities (Water, Power)</option>
            <option value="transport">Transport</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="expense_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      <div class="form-group">
        <label>Description *</label>
        <input type="text" name="description" class="form-control" required placeholder="e.g. Broiler starter feed purchase, Vet visit...">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Amount (₵) *</label>
          <input type="number" name="amount" class="form-control" required min="0" step="0.01">
        </div>
        <div class="form-group">
          <label>Receipt / Reference</label>
          <input type="text" name="receipt_ref" class="form-control" placeholder="Invoice number">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Related Flock</label>
          <select name="flock_id" class="form-control">
            <option value="">General (not flock-specific)</option>
            <?php foreach($flocks as $fl): ?><option value="<?php echo $fl['id']; ?>"><?php echo htmlspecialchars($fl['flock_name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Supplier</label>
          <select name="supplier_id" class="form-control">
            <option value="">Unknown / Not Listed</option>
            <?php foreach($suppliers as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" class="form-control" rows="2"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Expense</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="../public/js/admin.js"></script>
<script>
const plCtx = document.getElementById('plChart');
if (plCtx) {
  new Chart(plCtx, {
    type: 'doughnut',
    data: {
      labels: ['Revenue', 'Expenses'],
      datasets: [{ data: [<?php echo $revenue; ?>, <?php echo $totalExp; ?>], backgroundColor: ['#4ade80','#ef4444'], borderWidth: 0 }]
    },
    options: {
      cutout: '60%',
      plugins: {
        legend: { position: 'bottom' },
        tooltip: { callbacks: { label: c => '₵' + parseFloat(c.raw).toFixed(2) } }
      }
    }
  });
}
</script>
</body>
</html>
