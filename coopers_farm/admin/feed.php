<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

// Cost per kg is worked out from what was bought: GHS total ÷ kg.
// A restock blends the old stock and the new stock at an average cost per kg.

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_feed'])) {
        $name  = sanitize($_POST['feed_type'] ?? '');
        $brand = sanitize($_POST['brand'] ?? '');
        $kg    = (float)($_POST['quantity_kg'] ?? 0);
        $total = (float)($_POST['total_cost'] ?? 0);
        $reorder = (float)($_POST['reorder_level'] ?? 0);
        $supplier = sanitize($_POST['supplier'] ?? '');
        if ($name === '')                    $msg = 'error:Enter the feed name.';
        elseif ($kg < 0 || $total < 0)       $msg = 'error:Quantity and cost cannot be negative.';
        elseif ($kg == 0 && $total > 0)      $msg = 'error:Enter the kilograms bought for this cost.';
        else {
            $unit = $kg > 0 ? round($total / $kg, 4) : 0;
            $pdo->prepare("INSERT INTO feed_stock (feed_type, brand, quantity_kg, unit_cost, reorder_level, supplier)
                VALUES (?,?,?,?,?,?)")->execute([$name, $brand, $kg, $unit, $reorder, $supplier]);
            auditLog($pdo, 'add_feed', 'feed', (int)$pdo->lastInsertId(),
                "Added {$name}: {$kg} kg for ".number_format($total,2)." (".number_format($unit,4)."/kg)");
            $msg = 'success:Feed type added.';
        }
    }

    if (isset($_POST['restock'])) {
        $id    = (int)($_POST['feed_id'] ?? 0);
        $kg    = (float)($_POST['quantity_kg'] ?? 0);
        $total = (float)($_POST['total_cost'] ?? 0);
        if ($kg <= 0 || $total < 0) $msg = 'error:Enter the kilograms bought and the total paid.';
        else {
            $pdo->beginTransaction();
            $f = $pdo->prepare("SELECT * FROM feed_stock WHERE id=? FOR UPDATE");
            $f->execute([$id]); $feed = $f->fetch();
            if (!$feed) { $pdo->rollBack(); $msg = 'error:Feed not found.'; }
            else {
                $oldKg = (float)$feed['quantity_kg']; $oldUnit = (float)$feed['unit_cost'];
                $newKg = $oldKg + $kg;
                $newUnit = round((($oldKg * $oldUnit) + $total) / $newKg, 4);
                $pdo->prepare("UPDATE feed_stock SET quantity_kg=?, unit_cost=? WHERE id=?")->execute([$newKg, $newUnit, $id]);
                $pdo->commit();
                auditLog($pdo, 'restock_feed', 'feed', $id,
                    "Restocked {$feed['feed_type']}: +{$kg} kg for ".number_format($total,2)." → ".number_format($newUnit,4)."/kg average");
                $msg = 'success:Restocked. New average cost is '.formatCurrency($newUnit).' per kg.';
            }
        }
    }

    if (isset($_POST['log_usage'])) {
        $flockId = (int)($_POST['flock_id'] ?? 0);
        $id      = (int)($_POST['feed_id'] ?? 0);
        $kg      = (float)($_POST['quantity_kg'] ?? 0);
        $date    = $_POST['log_date'] ?? date('Y-m-d');
        $notes   = sanitize($_POST['notes'] ?? '');
        if ($flockId <= 0 || $id <= 0)       $msg = 'error:Choose the flock and the feed.';
        elseif ($kg <= 0)                    $msg = 'error:Enter the kilograms fed.';
        elseif ($date > date('Y-m-d'))       $msg = 'error:The date cannot be in the future.';
        else {
            $pdo->beginTransaction();
            $f = $pdo->prepare("SELECT * FROM feed_stock WHERE id=? FOR UPDATE");
            $f->execute([$id]); $feed = $f->fetch();
            if (!$feed) { $pdo->rollBack(); $msg = 'error:Feed not found.'; }
            elseif ($kg > (float)$feed['quantity_kg']) {
                $pdo->rollBack();
                $msg = "error:Only ".number_format((float)$feed['quantity_kg'],2)." kg of {$feed['feed_type']} left.";
            } else {
                $cost = round($kg * (float)$feed['unit_cost'], 2);
                $pdo->prepare("UPDATE feed_stock SET quantity_kg = quantity_kg - ? WHERE id=?")->execute([$kg, $id]);
                $pdo->prepare("INSERT INTO feed_logs (flock_id, feed_stock_id, feed_type, quantity_kg, log_date, cost, notes, recorded_by)
                    VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$flockId, $id, $feed['feed_type'], $kg, $date, $cost, $notes, $_SESSION['user_id']]);
                $logId = (int)$pdo->lastInsertId();
                $pdo->commit();
                auditLog($pdo, 'log_feed', 'feed_log', $logId, "Fed {$kg} kg of {$feed['feed_type']} costing ".number_format($cost,2));
                $msg = 'success:Logged '.number_format($kg,2).' kg — cost '.formatCurrency($cost).'.';
            }
        }
    }

    header('Location: feed.php?msg=' . urlencode($msg)); exit;
}

// ── Data ──────────────────────────────────────────────────────
$urlMsg = $_GET['msg'] ?? '';
$feeds  = $pdo->query("SELECT * FROM feed_stock ORDER BY feed_type, brand")->fetchAll();
$flocks = $pdo->query("SELECT id, flock_name FROM flocks WHERE status IN ('active','quarantine') ORDER BY flock_name")->fetchAll();
$recent = $pdo->query("SELECT fl.*, f.flock_name FROM feed_logs fl LEFT JOIN flocks f ON f.id = fl.flock_id
    ORDER BY fl.log_date DESC, fl.id DESC LIMIT 30")->fetchAll();

$stockKg   = array_sum(array_map(fn($f) => (float)$f['quantity_kg'], $feeds));
$stockVal  = array_sum(array_map(fn($f) => (float)$f['quantity_kg'] * (float)$f['unit_cost'], $feeds));
$used30    = (float)$pdo->query("SELECT COALESCE(SUM(quantity_kg),0) FROM feed_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$cost30    = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM feed_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$lowCount  = count(array_filter($feeds, fn($f) => (float)$f['quantity_kg'] <= (float)$f['reorder_level']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Feed Management — Coopers Admin</title>
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
    <h1>Feed Management</h1>
    <p>Enter what you bought and what you paid. The cost per kg is worked out for you.</p>
  </div>
  <div class="dash-quick">
    <button class="dq-btn dq-primary" type="button" data-open-modal="addFeedModal"><i class="fas fa-plus"></i> Add feed type</button>
  </div>
</div>

<?php if($urlMsg): [$t,$tx]=explode(':',$urlMsg,2); ?>
<div class="alert alert-<?php echo $t; ?>" style="margin-bottom:14px;"><i class="fas fa-<?php echo $t==='error'?'exclamation-circle':'check-circle'; ?>"></i> <?php echo htmlspecialchars($tx); ?></div>
<?php endif; ?>

<section class="dash-health">
  <div class="dh-tile"><span class="dh-lbl">Feed on hand</span><strong><?php echo number_format($stockKg, 1); ?> kg</strong><small><?php echo count($feeds); ?> feed type(s)</small></div>
  <div class="dh-tile"><span class="dh-lbl">Stock value</span><strong><?php echo formatCurrency($stockVal); ?></strong><small>kg on hand × cost per kg</small></div>
  <div class="dh-tile"><span class="dh-lbl">Fed · 30 days</span><strong><?php echo number_format($used30, 1); ?> kg</strong><small><?php echo formatCurrency($cost30); ?> cost</small></div>
  <div class="dh-tile <?php echo $lowCount>0?'warn':''; ?>"><span class="dh-lbl">Need reordering</span><strong><?php echo $lowCount; ?></strong><small>at or below reorder level</small></div>
</section>

<section class="card dash-card">
  <div class="dc-head"><h3>Feed types</h3></div>
  <?php if(empty($feeds)): ?>
    <div class="dc-empty">No feed yet. Add a feed type to start.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="dash-table">
    <thead><tr><th>Feed</th><th>Brand</th><th>On hand</th><th>Cost per kg</th><th>Value</th><th>Reorder at</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach($feeds as $f):
      $kg = (float)$f['quantity_kg']; $unit = (float)$f['unit_cost']; $re = (float)$f['reorder_level'];
      $status = $kg <= 0 ? ['red','Out'] : ($kg <= $re ? ['amber','Reorder'] : ['green','OK']);
    ?>
      <tr>
        <td><strong><?php echo htmlspecialchars($f['feed_type']); ?></strong><?php if($f['supplier']): ?><br><small><?php echo htmlspecialchars($f['supplier']); ?></small><?php endif; ?></td>
        <td><?php echo htmlspecialchars($f['brand'] ?? '—'); ?></td>
        <td><strong><?php echo number_format($kg, 2); ?> kg</strong></td>
        <td><?php echo formatCurrency($unit); ?> / kg</td>
        <td><?php echo formatCurrency($kg * $unit); ?></td>
        <td><?php echo number_format($re, 0); ?> kg</td>
        <td><span class="status-dot <?php echo $status[0]; ?>"></span><?php echo $status[1]; ?></td>
        <td style="text-align:right;white-space:nowrap;">
          <button class="btn btn-sm btn-secondary" type="button" data-restock
            data-id="<?php echo (int)$f['id']; ?>" data-name="<?php echo htmlspecialchars($f['feed_type']); ?>"
            data-kg="<?php echo $kg; ?>" data-unit="<?php echo $unit; ?>">Restock</button>
          <button class="btn btn-sm btn-primary" type="button" data-usage
            data-id="<?php echo (int)$f['id']; ?>" data-kg="<?php echo $kg; ?>">Log usage</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<section class="card dash-card">
  <div class="dc-head"><h3>Recent feeding</h3></div>
  <?php if(empty($recent)): ?>
    <div class="dc-empty">No feeding logged yet.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="dash-table">
    <thead><tr><th>Date</th><th>Flock</th><th>Feed</th><th>Kg</th><th>Cost</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach($recent as $r): ?>
      <tr>
        <td><?php echo date('M d, Y', strtotime($r['log_date'])); ?></td>
        <td><?php echo htmlspecialchars($r['flock_name'] ?? '—'); ?></td>
        <td><?php echo htmlspecialchars($r['feed_type']); ?></td>
        <td><?php echo number_format($r['quantity_kg'], 2); ?></td>
        <td><?php echo formatCurrency((float)$r['cost']); ?></td>
        <td class="dc-hint" style="margin:0;"><?php echo htmlspecialchars(mb_substr((string)$r['notes'],0,50)); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

</div>
</div>

<!-- Add feed type -->
<div class="modal-overlay" id="addFeedModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header"><h3>Add feed type</h3><button class="modal-close" type="button" data-close-modal><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <input type="hidden" name="add_feed" value="1">
      <div style="padding:16px 24px 0;">
        <div class="form-row">
          <div class="form-group"><label>Feed name *</label><input type="text" name="feed_type" class="form-control" required placeholder="e.g. Starter Feed"></div>
          <div class="form-group"><label>Brand</label><input type="text" name="brand" class="form-control" placeholder="e.g. Big Fred"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Kilograms bought *</label><input type="number" name="quantity_kg" class="form-control" min="0" step="0.01" required data-cost-qty></div>
          <div class="form-group"><label>Total paid (GHS) *</label><input type="number" name="total_cost" class="form-control" min="0" step="0.01" data-cost-total></div>
        </div>
        <div class="feed-calc" data-cost-preview>Enter the kilograms and the total paid to see the cost per kg.</div>
        <div class="form-row">
          <div class="form-group"><label>Reorder when below (kg)</label><input type="number" name="reorder_level" class="form-control" min="0" step="0.01" value="50"></div>
          <div class="form-group"><label>Supplier</label><input type="text" name="supplier" class="form-control"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add feed type</button>
      </div>
    </form>
  </div>
</div>

<!-- Restock -->
<div class="modal-overlay" id="restockModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><h3 id="rsTitle">Restock</h3><button class="modal-close" type="button" data-close-modal><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <input type="hidden" name="restock" value="1">
      <input type="hidden" name="feed_id" id="rsId">
      <div style="padding:16px 24px 0;">
        <div class="form-row">
          <div class="form-group"><label>Kilograms bought *</label><input type="number" name="quantity_kg" class="form-control" min="0.01" step="0.01" required data-cost-qty></div>
          <div class="form-group"><label>Total paid (GHS) *</label><input type="number" name="total_cost" class="form-control" min="0" step="0.01" required data-cost-total></div>
        </div>
        <div class="feed-calc" data-cost-preview data-old-kg id="rsOld">—</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary">Restock</button>
      </div>
    </form>
  </div>
</div>

<!-- Log usage -->
<div class="modal-overlay" id="usageModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><h3>Log feeding</h3><button class="modal-close" type="button" data-close-modal><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <input type="hidden" name="log_usage" value="1">
      <input type="hidden" name="feed_id" id="ulId">
      <div style="padding:16px 24px 0;">
        <div class="form-group"><label>Flock *</label>
          <select name="flock_id" class="form-control" required>
            <option value="">Choose a flock…</option>
            <?php foreach($flocks as $fl): ?><option value="<?php echo (int)$fl['id']; ?>"><?php echo htmlspecialchars($fl['flock_name']); ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-row">
          <div class="form-group"><label>Kilograms fed *</label><input type="number" name="quantity_kg" id="ulKg" class="form-control" min="0.01" step="0.01" required></div>
          <div class="form-group"><label>Date *</label><input type="date" name="log_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
        </div>
        <div class="feed-calc" id="ulInfo">—</div>
        <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary">Log feeding</button>
      </div>
    </form>
  </div>
</div>

<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
