<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Log feed USAGE (no cost — cost is tracked at restock) ──
    if (isset($_POST['add_feed_log'])) {
        $feedType = sanitize($_POST['feed_type']);
        $qty      = (float)$_POST['quantity_kg'];
        $flockId  = (int)$_POST['flock_id'];

        // Get unit cost from stock to calculate cost automatically
        $stockRow = $pdo->prepare("SELECT unit_cost, quantity_kg FROM feed_stock WHERE feed_type = ?");
        $stockRow->execute([$feedType]);
        $stock = $stockRow->fetch();

        if ($stock && $stock['quantity_kg'] < $qty) {
            $msg = 'error:Not enough stock! Only ' . number_format($stock['quantity_kg'],1) . 'kg available.';
        } else {
            $autoCost = $stock ? round($stock['unit_cost'] * $qty, 2) : 0;

            $pdo->prepare("INSERT INTO feed_logs (flock_id, feed_type, quantity_kg, log_date, cost, notes, recorded_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$flockId, $feedType, $qty, $_POST['log_date'], $autoCost, sanitize($_POST['notes'] ?? ''), $_SESSION['user_id']]);

            // Deduct from stock
            $pdo->prepare("UPDATE feed_stock SET quantity_kg = GREATEST(0, quantity_kg - ?) WHERE feed_type = ?")
                ->execute([$qty, $feedType]);

            $msg = "success:Feed usage logged! Cost auto-calculated: " . formatCurrency($autoCost) . " (based on restock price of " . formatCurrency($stock['unit_cost'] ?? 0) . "/kg)";
        }
    }

    // ── ADD NEW FEED STOCK TYPE ──
    if (isset($_POST['add_new_stock'])) {
        $feedType = sanitize($_POST['new_feed_type']);
        // Check not duplicate
        $exists = $pdo->prepare("SELECT id FROM feed_stock WHERE feed_type = ?");
        $exists->execute([$feedType]);
        if ($exists->fetch()) {
            $msg = 'error:A feed type with that name already exists.';
        } else {
            $pdo->prepare("INSERT INTO feed_stock (feed_type, brand, quantity_kg, unit_cost, reorder_level, supplier) VALUES (?,?,?,?,?,?)")
                ->execute([$feedType, sanitize($_POST['brand'] ?? ''), (float)$_POST['initial_qty'],
                           (float)$_POST['unit_cost'], (float)$_POST['reorder_level'], sanitize($_POST['supplier'] ?? '')]);
            $msg = 'success:New feed stock added!';
        }
    }

    // ── RESTOCK (cost set here) ──
    if (isset($_POST['restock'])) {
        $sid      = (int)$_POST['stock_id'];
        $addQty   = (float)$_POST['add_qty'];
        $unitCost = (float)$_POST['unit_cost'];
        $supplier = sanitize($_POST['supplier'] ?? '');
        $notes    = sanitize($_POST['notes'] ?? '');

        // Update stock quantity and unit cost (latest price)
        $pdo->prepare("UPDATE feed_stock SET quantity_kg = quantity_kg + ?, unit_cost = ?, supplier = COALESCE(NULLIF(?,  ''), supplier) WHERE id = ?")
            ->execute([$addQty, $unitCost, $supplier, $sid]);

        // Log the restock as a feed_log entry with negative quantity (inflow)
        $feedTypeRow = $pdo->prepare("SELECT feed_type FROM feed_stock WHERE id = ?");
        $feedTypeRow->execute([$sid]);
        $ft = $feedTypeRow->fetchColumn();

        $totalCost = $addQty * $unitCost;
        $pdo->prepare("INSERT INTO feed_logs (flock_id, feed_type, quantity_kg, log_date, cost, notes, recorded_by) VALUES (0,?,?,CURDATE(),?,?,?)")
            ->execute([$ft, $addQty, $totalCost, "Restock" . ($notes ? ": $notes" : ''), $_SESSION['user_id']]);

        $msg = 'success:Stock restocked! ' . number_format($addQty,1) . 'kg added at ' . formatCurrency($unitCost) . '/kg. Total cost: ' . formatCurrency($totalCost);
    }

    // ── DELETE FEED STOCK TYPE ──
    if (isset($_POST['delete_stock'])) {
        $sid = (int)$_POST['stock_id'];
        $pdo->prepare("DELETE FROM feed_stock WHERE id = ?")->execute([$sid]);
        $msg = 'success:Feed stock removed!';
    }

    header('Location: feed.php?msg=' . urlencode($msg));
    exit;
}

$urlMsg   = $_GET['msg'] ?? '';
$feedStock = $pdo->query("SELECT * FROM feed_stock ORDER BY feed_type")->fetchAll();
$flocks    = $pdo->query("SELECT * FROM flocks WHERE status='active' ORDER BY flock_name")->fetchAll();

$recentLogs = $pdo->query("
    SELECT fl.*, f.flock_name
    FROM feed_logs fl
    LEFT JOIN flocks f ON f.id = fl.flock_id
    ORDER BY fl.log_date DESC, fl.created_at DESC
    LIMIT 50
")->fetchAll();

$totalCostMonth = $pdo->query("SELECT COALESCE(SUM(cost),0) FROM feed_logs WHERE flock_id > 0 AND MONTH(log_date)=MONTH(CURDATE())")->fetchColumn();
$totalUsedMonth = $pdo->query("SELECT COALESCE(SUM(quantity_kg),0) FROM feed_logs WHERE flock_id > 0 AND MONTH(log_date)=MONTH(CURDATE())")->fetchColumn();
$totalRestockCost = $pdo->query("SELECT COALESCE(SUM(cost),0) FROM feed_logs WHERE flock_id = 0")->fetchColumn();
$lowStockCount  = count(array_filter($feedStock, fn($f) => $f['quantity_kg'] < $f['reorder_level']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Feed Management — Coopers Admin</title>
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
      <div><h1>Feed Management</h1><p>Track stock, usage and costs. Cost is auto-calculated from restock price.</p></div>
      <div style="display:flex;gap:10px;">
        <button class="btn btn-secondary" onclick="document.getElementById('addStockModal').classList.add('open')">
          <i class="fas fa-boxes"></i> Add Feed Type
        </button>
        <button class="btn btn-primary" onclick="document.getElementById('addFeedModal').classList.add('open')">
          <i class="fas fa-seedling"></i> Log Usage
        </button>
      </div>
    </div>

    <?php if($urlMsg): [$type,$text] = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $type; ?>"><?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>
    <?php if($lowStockCount > 0): ?>
    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <strong><?php echo $lowStockCount; ?> feed type(s)</strong> below reorder level.</div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Usage Cost (Month)</span><div class="stat-icon-small orange"><i class="fas fa-coins"></i></div></div>
        <h2><?php echo formatCurrency($totalCostMonth); ?></h2>
        <p>Auto-calculated from restock price</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Feed Used (Month)</span><div class="stat-icon-small blue"><i class="fas fa-weight"></i></div></div>
        <h2><?php echo number_format($totalUsedMonth,1); ?>kg</h2>
        <p>Total consumed this month</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Total Restock Cost</span><div class="stat-icon-small purple"><i class="fas fa-truck"></i></div></div>
        <h2><?php echo formatCurrency($totalRestockCost); ?></h2>
        <p>All-time purchasing cost</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Low Stock</span><div class="stat-icon-small red"><i class="fas fa-exclamation-triangle"></i></div></div>
        <h2 style="color:<?php echo $lowStockCount>0?'#ef4444':'inherit'; ?>"><?php echo $lowStockCount; ?></h2>
        <p>Need restocking</p>
      </div>
    </div>

    <!-- Stock Cards -->
    <div class="card" style="margin-bottom:24px;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>Current Feed Stock (<?php echo count($feedStock); ?> types)</h3>
        <button class="btn btn-sm btn-secondary" onclick="document.getElementById('addStockModal').classList.add('open')">
          <i class="fas fa-plus"></i> Add New Type
        </button>
      </div>
      <?php if(empty($feedStock)): ?>
      <div style="text-align:center;padding:40px;color:#888;">
        <i class="fas fa-boxes" style="font-size:36px;margin-bottom:12px;display:block;color:#e5e7eb;"></i>
        <p>No feed stock added yet.</p>
        <button class="btn btn-primary" style="margin-top:12px;" onclick="document.getElementById('addStockModal').classList.add('open')">
          <i class="fas fa-plus"></i> Add First Feed Type
        </button>
      </div>
      <?php else: ?>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;">
          <?php foreach($feedStock as $fs):
            $pct    = min(100, ($fs['quantity_kg'] / max($fs['reorder_level']*2,1)) * 100);
            $isLow  = $fs['quantity_kg'] < $fs['reorder_level'];
            $barClr = $isLow ? 'red' : ($pct < 60 ? 'orange' : '');
          ?>
          <div style="background:<?php echo $isLow?'#fff5f5':'var(--cream)'; ?>;border-radius:12px;padding:18px;border:1px solid <?php echo $isLow?'#fca5a5':'var(--border)'; ?>;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
              <div>
                <div style="font-weight:700;font-size:15px;"><?php echo htmlspecialchars($fs['feed_type']); ?></div>
                <div style="font-size:11px;color:#888;"><?php echo htmlspecialchars($fs['brand']??''); ?> <?php echo $fs['supplier']?'· '.$fs['supplier']:''; ?></div>
              </div>
              <div style="display:flex;gap:6px;">
                <button class="btn btn-sm btn-primary" onclick="openRestock(<?php echo $fs['id']; ?>,'<?php echo htmlspecialchars($fs['feed_type'],ENT_QUOTES); ?>',<?php echo $fs['unit_cost']; ?>,'<?php echo htmlspecialchars($fs['supplier']??'',ENT_QUOTES); ?>')">
                  <i class="fas fa-plus"></i> Restock
                </button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this feed type?')">
                  <input type="hidden" name="delete_stock" value="1">
                  <input type="hidden" name="stock_id" value="<?php echo $fs['id']; ?>">
                  <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
              <span>Stock: <strong style="color:<?php echo $isLow?'#ef4444':'inherit'; ?>"><?php echo number_format($fs['quantity_kg'],1); ?>kg</strong></span>
              <span style="color:#888;">Reorder: <?php echo number_format($fs['reorder_level'],0); ?>kg</span>
            </div>
            <div class="progress-bar" style="margin-bottom:6px;">
              <div class="progress-fill <?php echo $barClr; ?>" style="width:<?php echo $pct; ?>%"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:#888;">
              <span>Unit cost: <strong style="color:var(--primary);"><?php echo formatCurrency($fs['unit_cost']); ?>/kg</strong></span>
              <?php if($isLow): ?><span style="color:#ef4444;font-weight:700;">⚠ Restock!</span><?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Feed Usage Logs -->
    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>Feed Logs</h3>
        <span style="font-size:13px;color:#888;font-style:italic;">💡 Cost is auto-calculated from unit cost set at restock</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr><th>Date</th><th>Type</th><th>Flock</th><th>Quantity</th><th>Cost (auto)</th><th>Notes</th></tr>
          </thead>
          <tbody>
            <?php foreach($recentLogs as $log):
              $isRestock = ($log['flock_id'] == 0);
            ?>
            <tr style="<?php echo $isRestock ? 'background:#f0fdf4;' : ''; ?>">
              <td><?php echo date('M d, Y', strtotime($log['log_date'])); ?></td>
              <td><?php echo htmlspecialchars($log['feed_type']); ?></td>
              <td>
                <?php if($isRestock): ?>
                  <span class="status-badge badge-active">📦 Restock</span>
                <?php else: ?>
                  <strong><?php echo htmlspecialchars($log['flock_name'] ?? '—'); ?></strong>
                <?php endif; ?>
              </td>
              <td>
                <?php if($isRestock): ?>
                  <span style="color:#4ade80;font-weight:700;">+<?php echo number_format($log['quantity_kg'],1); ?>kg</span>
                <?php else: ?>
                  <?php echo number_format($log['quantity_kg'],1); ?>kg
                <?php endif; ?>
              </td>
              <td>
                <?php if($log['cost']): ?>
                  <strong style="color:<?php echo $isRestock ? '#4ade80' : 'inherit'; ?>;">
                    <?php echo $isRestock ? '+' : ''; ?><?php echo formatCurrency($log['cost']); ?>
                  </strong>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars(substr($log['notes']??'',0,50)); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($recentLogs)): ?>
            <tr><td colspan="6" style="text-align:center;color:#888;padding:30px;">No logs yet</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── ADD NEW FEED TYPE MODAL ──────────────────────────── -->
<div class="modal-overlay" id="addStockModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">
      <h3><i class="fas fa-boxes" style="color:var(--primary);margin-right:8px;"></i>Add New Feed Type</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_new_stock" value="1">
      <div class="form-row">
        <div class="form-group">
          <label>Feed Type Name *</label>
          <input type="text" name="new_feed_type" class="form-control" required placeholder="e.g. Layer Mash, Broiler Starter">
        </div>
        <div class="form-group">
          <label>Brand</label>
          <input type="text" name="brand" class="form-control" placeholder="e.g. Accra Mills">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Initial Quantity (kg)</label>
          <input type="number" name="initial_qty" class="form-control" min="0" step="0.1" value="0">
        </div>
        <div class="form-group">
          <label>Unit Cost (₵/kg) *</label>
          <input type="number" name="unit_cost" class="form-control" required min="0" step="0.01" placeholder="Cost per kg">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Reorder Level (kg) *</label>
          <input type="number" name="reorder_level" class="form-control" required min="0" value="50" placeholder="Alert below this level">
        </div>
        <div class="form-group">
          <label>Supplier</label>
          <input type="text" name="supplier" class="form-control" placeholder="Supplier name">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Feed Type</button>
      </div>
    </form>
  </div>
</div>

<!-- ── LOG USAGE MODAL ──────────────────────────────────── -->
<div class="modal-overlay" id="addFeedModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">
      <h3><i class="fas fa-seedling" style="color:var(--primary);margin-right:8px;"></i>Log Feed Usage</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div style="background:#EDF5E8;border-radius:10px;padding:12px 16px;margin:0 32px 0;font-size:13px;color:#2D5016;">
      <i class="fas fa-info-circle"></i> Cost is <strong>automatically calculated</strong> using the unit cost set when you last restocked. No need to enter it manually.
    </div>
    <form method="POST" style="padding-top:4px;">
      <input type="hidden" name="add_feed_log" value="1">
      <div style="padding:0 32px;">
        <div class="form-row" style="margin-top:16px;">
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
            <label>Feed Type *</label>
            <select name="feed_type" class="form-control" required id="feedTypeSelect" onchange="updateUnitCost(this)">
              <option value="">Select feed...</option>
              <?php foreach($feedStock as $fs): ?>
              <option value="<?php echo htmlspecialchars($fs['feed_type']); ?>"
                      data-cost="<?php echo $fs['unit_cost']; ?>"
                      data-avail="<?php echo $fs['quantity_kg']; ?>">
                <?php echo htmlspecialchars($fs['feed_type']); ?> — <?php echo number_format($fs['quantity_kg'],1); ?>kg left
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Quantity (kg) *</label>
            <input type="number" name="quantity_kg" id="feedQtyInput" class="form-control" required min="0.1" step="0.1" oninput="calcEstCost()">
          </div>
        </div>
        <div id="costPreview" style="background:var(--cream);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px;display:none;">
          <span style="color:#888;">Estimated cost:</span>
          <strong id="estCost" style="color:var(--primary);"></strong>
          <span style="color:#888;margin-left:8px;">(at <span id="unitCostDisplay"></span>/kg)</span>
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Morning feeding, Pen A..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Log Usage</button>
      </div>
    </form>
  </div>
</div>

<!-- ── RESTOCK MODAL ────────────────────────────────────── -->
<div class="modal-overlay" id="restockModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <h3>Restock Feed</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div style="background:#EDF5E8;border-radius:10px;padding:12px 16px;margin:0 32px 16px;font-size:13px;color:#2D5016;">
      <i class="fas fa-lightbulb"></i> <strong>The unit cost you enter here</strong> will be used to automatically calculate the cost of all future usage logs for this feed type.
    </div>
    <form method="POST">
      <input type="hidden" name="restock" value="1">
      <input type="hidden" name="stock_id" id="restockId">
      <div style="padding:0 32px;">
        <div class="form-group">
          <label>Feed Type</label>
          <div id="restockName" style="padding:12px;background:var(--cream);border-radius:10px;font-weight:700;font-size:15px;"></div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Quantity to Add (kg) *</label>
            <input type="number" name="add_qty" id="restockQty" class="form-control" required min="0.1" step="0.1" oninput="calcRestockTotal()">
          </div>
          <div class="form-group">
            <label>Unit Cost (₵/kg) *</label>
            <input type="number" name="unit_cost" id="restockCost" class="form-control" required step="0.01" min="0" oninput="calcRestockTotal()">
          </div>
        </div>
        <div id="restockTotal" style="background:var(--cream);border-radius:8px;padding:10px 14px;font-size:14px;margin-bottom:14px;display:none;">
          Total restock cost: <strong id="restockTotalVal" style="color:var(--primary);font-size:18px;"></strong>
        </div>
        <div class="form-group">
          <label>Supplier</label>
          <input type="text" name="supplier" id="restockSupplier" class="form-control" placeholder="Where did you buy it?">
        </div>
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Purchase notes, batch number..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Confirm Restock</button>
      </div>
    </form>
  </div>
</div>

<script src="../public/js/admin.js"></script>
<script>
function openRestock(id, name, cost, supplier) {
  document.getElementById('restockId').value       = id;
  document.getElementById('restockName').textContent = name;
  document.getElementById('restockCost').value     = cost;
  document.getElementById('restockSupplier').value = supplier || '';
  document.getElementById('restockQty').value      = '';
  document.getElementById('restockTotal').style.display = 'none';
  document.getElementById('restockModal').classList.add('open');
}

function calcRestockTotal() {
  const qty  = parseFloat(document.getElementById('restockQty').value) || 0;
  const cost = parseFloat(document.getElementById('restockCost').value) || 0;
  const box  = document.getElementById('restockTotal');
  if (qty > 0 && cost > 0) {
    document.getElementById('restockTotalVal').textContent = '₵' + (qty * cost).toFixed(2);
    box.style.display = 'block';
  } else {
    box.style.display = 'none';
  }
}

function updateUnitCost(sel) {
  const opt  = sel.options[sel.selectedIndex];
  const cost = parseFloat(opt.dataset.cost) || 0;
  document.getElementById('unitCostDisplay').textContent = '₵' + cost.toFixed(2);
  calcEstCost();
}

function calcEstCost() {
  const sel   = document.getElementById('feedTypeSelect');
  const qty   = parseFloat(document.getElementById('feedQtyInput').value) || 0;
  const opt   = sel.options[sel.selectedIndex];
  const cost  = parseFloat(opt?.dataset?.cost) || 0;
  const avail = parseFloat(opt?.dataset?.avail) || 0;
  const box   = document.getElementById('costPreview');

  if (qty > 0 && cost > 0 && sel.value) {
    document.getElementById('estCost').textContent = '₵' + (qty * cost).toFixed(2);
    document.getElementById('unitCostDisplay').textContent = '₵' + cost.toFixed(2);
    box.style.display = 'block';
    box.style.background = qty > avail ? '#fff5f5' : 'var(--cream)';
    if (qty > avail) {
      document.getElementById('estCost').textContent = '⚠ Exceeds stock! Only ' + avail.toFixed(1) + 'kg available';
      document.getElementById('estCost').style.color = '#ef4444';
    } else {
      document.getElementById('estCost').style.color = 'var(--primary)';
    }
  } else {
    box.style.display = 'none';
  }
}
</script>
</body>
</html>
