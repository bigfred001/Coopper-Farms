<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_price'])) {
        $pdo->prepare("INSERT INTO market_prices (product_type, price_per_unit, unit, market_name, location, recorded_date, notes, recorded_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([sanitize($_POST['product_type']), (float)$_POST['price_per_unit'],
                       sanitize($_POST['unit']), sanitize($_POST['market_name']??''),
                       sanitize($_POST['location']??''), $_POST['recorded_date'],
                       sanitize($_POST['notes']??''), $_SESSION['user_id']]);
        auditLog($pdo, 'add_market_price', 'market', null,
            sanitize($_POST['product_type']) . ' @ ' . formatCurrency((float)$_POST['price_per_unit']) . '/' . sanitize($_POST['unit']));
        $msg = 'success:Market price recorded!';
    }
    header('Location: market_prices.php?msg=' . urlencode($msg)); exit;
}

$urlMsg  = $_GET['msg'] ?? '';
$prices  = $pdo->query("
    SELECT mp.*, u.name as recorded_by_name
    FROM market_prices mp LEFT JOIN users u ON u.id=mp.recorded_by
    ORDER BY mp.recorded_date DESC LIMIT 100
")->fetchAll();

// Latest price per product type
$latest = $pdo->query("
    SELECT product_type, unit, price_per_unit, market_name, recorded_date
    FROM market_prices mp1
    WHERE recorded_date = (SELECT MAX(recorded_date) FROM market_prices mp2 WHERE mp2.product_type=mp1.product_type)
    GROUP BY product_type, unit
")->fetchAll();

// Compare our prices
$ourProducts = $pdo->query("SELECT name, price, unit, category FROM products WHERE is_active=1")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Market Prices — Coopers Admin</title>
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
      <div><h1>Market Price Tracking</h1><p>Track market prices to stay competitive and optimize your pricing</p></div>
      <button class="btn btn-primary" onclick="document.getElementById('addPriceModal').classList.add('open')">
        <i class="fas fa-plus"></i> Record Market Price
      </button>
    </div>

    <?php if($urlMsg): [$t,$tx] = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $t; ?>"><?php echo htmlspecialchars($tx); ?></div>
    <?php endif; ?>

    <!-- Latest market prices vs our prices -->
    <?php if(!empty($latest) && !empty($ourProducts)): ?>
    <div class="card" style="margin-bottom:24px;">
      <div class="card-header"><h3>Market vs Your Prices</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Our Product</th><th>Our Price</th><th>Market Rate</th><th>Difference</th><th>Market Name</th><th>Date Recorded</th></tr></thead>
          <tbody>
            <?php foreach($ourProducts as $op):
              $match = null;
              foreach($latest as $lp) {
                  if (stripos($lp['product_type'], $op['category']) !== false ||
                      stripos($op['name'], $lp['product_type']) !== false) {
                      $match = $lp; break;
                  }
              }
            ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($op['name']); ?></strong></td>
              <td><strong style="color:var(--primary);"><?php echo formatCurrency($op['price']); ?>/<?php echo $op['unit']; ?></strong></td>
              <td>
                <?php if($match): ?>
                  <?php echo formatCurrency($match['price_per_unit']); ?>/<?php echo $match['unit']; ?>
                <?php else: echo '<span style="color:#aaa;">No data</span>'; endif; ?>
              </td>
              <td>
                <?php if($match): 
                  $diff = $op['price'] - $match['price_per_unit'];
                  $pct  = $match['price_per_unit'] > 0 ? round(($diff/$match['price_per_unit'])*100,1) : 0;
                ?>
                  <span style="color:<?php echo $diff>0?'#4ade80':($diff<0?'#ef4444':'#888'); ?>;font-weight:700;">
                    <?php echo $diff>=0?'+':''; echo formatCurrency($diff); ?> (<?php echo $pct; ?>%)
                  </span>
                  <br><small style="color:#888;"><?php echo $diff>0?'Above market — competitive!':($diff<0?'Below market — review pricing':'At market rate'); ?></small>
                <?php else: echo '—'; endif; ?>
              </td>
              <td><?php echo $match ? htmlspecialchars($match['market_name']??'—') : '—'; ?></td>
              <td><?php echo $match ? date('M d, Y',strtotime($match['recorded_date'])) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- All market price logs -->
    <div class="card">
      <div class="card-header"><h3>Price History (<?php echo count($prices); ?>)</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Product Type</th><th>Price</th><th>Unit</th><th>Market</th><th>Location</th><th>Recorded By</th><th>Notes</th></tr></thead>
          <tbody>
            <?php foreach($prices as $p): ?>
            <tr>
              <td><?php echo date('M d, Y',strtotime($p['recorded_date'])); ?></td>
              <td><strong><?php echo htmlspecialchars($p['product_type']); ?></strong></td>
              <td><strong style="color:var(--primary);"><?php echo formatCurrency($p['price_per_unit']); ?></strong></td>
              <td><?php echo htmlspecialchars($p['unit']); ?></td>
              <td><?php echo htmlspecialchars($p['market_name']??'—'); ?></td>
              <td><?php echo htmlspecialchars($p['location']??'—'); ?></td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars($p['recorded_by_name']??'—'); ?></td>
              <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars(substr($p['notes']??'',0,50)); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($prices)): ?><tr><td colspan="8" style="text-align:center;color:#888;padding:30px;">No market prices recorded yet</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="addPriceModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h3><i class="fas fa-chart-line" style="color:var(--primary);margin-right:8px;"></i>Record Market Price</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_price" value="1">
      <div class="form-row">
        <div class="form-group">
          <label>Product Type *</label>
          <input type="text" name="product_type" class="form-control" required list="productTypes" placeholder="e.g. Broiler, Eggs, Layer">
          <datalist id="productTypes">
            <option value="Broiler (Live)"><option value="Broiler (Dressed)">
            <option value="Layer (Live)"><option value="Eggs (Crate 30)">
            <option value="Day-Old Chicks (Broiler)"><option value="Day-Old Chicks (Layer)">
          </datalist>
        </div>
        <div class="form-group">
          <label>Price per Unit (₵) *</label>
          <input type="number" name="price_per_unit" class="form-control" required min="0" step="0.01">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Unit *</label>
          <input type="text" name="unit" class="form-control" required list="unitList" placeholder="kg, bird, crate">
          <datalist id="unitList"><option value="kg"><option value="bird"><option value="crate"><option value="chick"></datalist>
        </div>
        <div class="form-group">
          <label>Date Recorded *</label>
          <input type="date" name="recorded_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Market Name</label>
          <input type="text" name="market_name" class="form-control" placeholder="e.g. Makola Market, Kumasi Central">
        </div>
        <div class="form-group">
          <label>Location</label>
          <input type="text" name="location" class="form-control" placeholder="e.g. Accra, Kumasi">
        </div>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" class="form-control" rows="2" placeholder="Market conditions, source of information..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Price</button>
      </div>
    </form>
  </div>
</div>
<script src="../public/js/admin.js"></script>
</body>
</html>
