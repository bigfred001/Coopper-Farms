<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

// Batch for bird products (broiler / layer). Other categories have none.
function batchFromPost(array $post): ?int {
    if (!in_array($post['category'] ?? '', ['broiler', 'layer'], true)) return null;
    $id = (int)($post['flock_id'] ?? 0);
    return $id > 0 ? $id : null;
}
// Size of an egg product (small, medium or large). Other categories have none.
function eggSizeFromPost(array $post): ?string {
    if (($post['category'] ?? '') !== 'eggs') return null;
    $sz = $post['egg_size'] ?? '';
    return in_array($sz, ['small', 'medium', 'large'], true) ? $sz : null;
}
// Eggs per pack for egg products (12, 15 or 30). Other categories have none.
function eggUnitsFromPost(array $post): ?int {
    if (($post['category'] ?? '') !== 'eggs') return null;
    $u = (int)($post['eggs_per_crate'] ?? 0);
    return in_array($u, [12, 15, 30], true) ? $u : null;
}

$uploadDir = __DIR__ . '/../public/uploads/products/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ── POST handlers ────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    function handleImageUpload(string $field, string $dir): ?string {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
        $f = $_FILES[$field];
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        if (!in_array(mime_content_type($f['tmp_name']), $allowed)) return null;
        if ($f['size'] > 3*1024*1024) return null;
        $filename = 'product_' . uniqid() . '.' . strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        move_uploaded_file($f['tmp_name'], $dir . $filename);
        return 'uploads/products/' . $filename;
    }

    if (isset($_POST['add_product'])) {
        $img = handleImageUpload('product_image', $uploadDir);
        $pdo->prepare("INSERT INTO products (name, category, description, price, unit, stock_quantity, min_order, image_url) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([sanitize($_POST['name']), $_POST['category'], sanitize($_POST['description']),
                       (float)$_POST['price'], sanitize($_POST['unit']),
                       ($_POST['category'] ?? '') === 'eggs' ? 0 : (int)$_POST['stock_quantity'], (int)$_POST['min_order'], $img]);
        $newPid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE products SET egg_units=?, flock_id=? WHERE id=?")->execute([eggUnitsFromPost($_POST), batchFromPost($_POST), $newPid]);
        $pdo->prepare("UPDATE products SET egg_size=? WHERE id=?")->execute([eggSizeFromPost($_POST), $newPid]);
        auditLog($pdo, 'add_product', 'product', $newPid, 'Added product: ' . sanitize($_POST['name']));
        $msg = 'success:Product added successfully!';
    }

    if (isset($_POST['edit_product'])) {
        $pid = (int)$_POST['product_id'];
        $img = handleImageUpload('product_image', $uploadDir);
        if ($img) {
            $old = $pdo->prepare("SELECT image_url FROM products WHERE id=?"); $old->execute([$pid]);
            $oldImg = $old->fetchColumn();
            if ($oldImg && file_exists(__DIR__.'/../public/'.$oldImg)) @unlink(__DIR__.'/../public/'.$oldImg);
            $pdo->prepare("UPDATE products SET name=?,category=?,description=?,price=?,unit=?,min_order=?,image_url=? WHERE id=?")
                ->execute([sanitize($_POST['name']), $_POST['category'], sanitize($_POST['description']),
                           (float)$_POST['price'], sanitize($_POST['unit']), (int)$_POST['min_order'], $img, $pid]);
        } else {
            $pdo->prepare("UPDATE products SET name=?,category=?,description=?,price=?,unit=?,min_order=? WHERE id=?")
                ->execute([sanitize($_POST['name']), $_POST['category'], sanitize($_POST['description']),
                           (float)$_POST['price'], sanitize($_POST['unit']), (int)$_POST['min_order'], $pid]);
        }
        $pdo->prepare("UPDATE products SET egg_units=?, flock_id=? WHERE id=?")->execute([eggUnitsFromPost($_POST), batchFromPost($_POST), $pid]);
        $pdo->prepare("UPDATE products SET egg_size=? WHERE id=?")->execute([eggSizeFromPost($_POST), $pid]);
        auditLog($pdo, 'edit_product', 'product', $pid, 'Updated product: ' . sanitize($_POST['name']));
        $msg = 'success:Product updated!';
    }

    // Inline stock edit (AJAX)
    if (isset($_POST['inline_stock'])) {
        $pid = (int)$_POST['product_id'];
        $qty = (int)$_POST['new_stock'];
        $pr = $pdo->prepare("SELECT flock_id, stock_quantity, category FROM products WHERE id=?");
        $pr->execute([$pid]); $prow = $pr->fetch();
        if (($prow['category'] ?? '') === 'eggs' && $qty > (int)$prow['stock_quantity']) {
            echo json_encode(['ok' => false, 'error' => 'Egg stock comes from production. Log production to add eggs; you can only reduce it here.']); exit;
        }
        if (!empty($prow['flock_id'])) {
            $fq = $pdo->prepare("SELECT quantity FROM flocks WHERE id=?"); $fq->execute([(int)$prow['flock_id']]);
            $old = (int)$fq->fetchColumn();
            $pdo->prepare("UPDATE flocks SET quantity=? WHERE id=?")->execute([$qty, (int)$prow['flock_id']]);
        } else {
            $old = (int)$prow['stock_quantity'];
            $pdo->prepare("UPDATE products SET stock_quantity=? WHERE id=?")->execute([$qty, $pid]);
        }
        if ($qty !== $old) {
            $diff = $qty - $old;
            $pdo->prepare("INSERT INTO inventory_logs (product_id, change_amount, reason, notes, created_by) VALUES (?,?,?,?,?)")
                ->execute([$pid, $diff, $diff>0?'restock':'adjustment', 'Inline stock edit', $_SESSION['user_id']]);
        }
        echo json_encode(['ok'=>true,'new_stock'=>$qty]); exit;
    }

    if (isset($_POST['update_stock'])) {
        $pid = (int)$_POST['product_id']; $add = (int)$_POST['add_stock'];
        $catq = $pdo->prepare("SELECT category FROM products WHERE id=?"); $catq->execute([$pid]);
        if ($catq->fetchColumn() === 'eggs') {
            header('Location: products.php?msg=' . urlencode('error:Egg stock comes from production. Log production to add eggs.')); exit;
        }
        $pr = $pdo->prepare("SELECT flock_id FROM products WHERE id=?"); $pr->execute([$pid]); $fid = (int)$pr->fetchColumn();
        if ($fid > 0) $pdo->prepare("UPDATE flocks SET quantity=quantity+? WHERE id=?")->execute([$add, $fid]);
        else $pdo->prepare("UPDATE products SET stock_quantity=stock_quantity+? WHERE id=?")->execute([$add, $pid]);
        $pdo->prepare("INSERT INTO inventory_logs (product_id, change_amount, reason, notes, created_by) VALUES (?,?,'restock',?,?)")
            ->execute([$pid, $add, 'Manual restock', $_SESSION['user_id']]);
        $msg = 'success:Stock updated!';
    }

    if (isset($_POST['toggle_active'])) {
        $pid = (int)$_POST['product_id'];
        $pdo->prepare("UPDATE products SET is_active = NOT is_active WHERE id=?")->execute([$pid]);
        auditLog($pdo, 'toggle_product', 'product', $pid, 'Toggled visibility for product #'.$pid);
        // AJAX call — just return OK without redirect
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['ajax'])) {
            echo json_encode(['ok' => true]); exit;
        }
        $msg = 'success:Product visibility toggled!';
    }

    // Bulk actions
    if (isset($_POST['bulk_action']) && !empty($_POST['selected_ids'])) {
        $ids    = array_map('intval', $_POST['selected_ids']);
        $action = $_POST['bulk_action'];
        if ($action === 'delete') {
            $deleted = 0; $hidden = [];
            foreach ($ids as $pid) {
                $h = $pdo->prepare("SELECT name, image_url,
                        (SELECT COUNT(*) FROM order_items WHERE product_id = products.id)
                      + (SELECT COUNT(*) FROM egg_pack_log WHERE product_id = products.id) AS used
                    FROM products WHERE id=?");
                $h->execute([$pid]);
                $row = $h->fetch();
                if (!$row) continue;
                if ((int)$row['used'] > 0) {
                    // Sales or packing history points at this product: keep the record, hide it instead
                    $pdo->prepare("UPDATE products SET is_active=0 WHERE id=?")->execute([$pid]);
                    $hidden[] = $row['name'];
                    continue;
                }
                $pdo->prepare("DELETE FROM inventory_logs WHERE product_id=?")->execute([$pid]);
                $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$pid]);
                if (!empty($row['image_url']) && file_exists(__DIR__.'/../public/'.$row['image_url'])) @unlink(__DIR__.'/../public/'.$row['image_url']);
                auditLog($pdo, 'delete_product', 'product', $pid, 'Deleted product: ' . $row['name']);
                $deleted++;
            }
            $msg = "success:Deleted {$deleted} product(s).";
            if ($hidden) $msg .= ' Hidden instead (they have sales history): ' . implode(', ', $hidden) . '.';
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            match($action) {
                'activate'   => $pdo->prepare("UPDATE products SET is_active=1 WHERE id IN ($placeholders)")->execute($ids),
                'deactivate' => $pdo->prepare("UPDATE products SET is_active=0 WHERE id IN ($placeholders)")->execute($ids),
                default      => null
            };
            $msg = 'success:' . count($ids) . ' product(s) updated!';
        }
    }

    if (!isset($_POST['inline_stock'])) {
        header('Location: products.php?msg=' . urlencode($msg)); exit;
    }
}

// ── Filters ──────────────────────────────────────────────────
$catFilter    = $_GET['cat']    ?? '';
$stockFilter  = $_GET['stock']  ?? '';
$sortBy       = $_GET['sort']   ?? 'category';
$sortDir      = $_GET['dir']    ?? 'asc';
$search       = $_GET['search'] ?? '';
$urlMsg       = $_GET['msg']    ?? '';

$where  = "WHERE 1=1";
$params = [];
if ($catFilter)   { $where .= " AND category=?";   $params[] = $catFilter; }
if ($search)      { $where .= " AND (name LIKE ? OR description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($stockFilter === 'out')  { $where .= " AND live_stock <= 0"; }
if ($stockFilter === 'low')  { $where .= " AND live_stock > 0 AND live_stock < 10"; }
if ($stockFilter === 'ok')   { $where .= " AND live_stock >= 10"; }

$allowedSort = ['name','price','stock_quantity','category'];
$sortCol = in_array($sortBy, $allowedSort) ? $sortBy : 'category';
if ($sortCol === 'stock_quantity') $sortCol = 'live_stock';
$sortDirSql = $sortDir === 'desc' ? 'DESC' : 'ASC';

$SRC = "(SELECT p.*, COALESCE(f.quantity, p.stock_quantity) AS live_stock, f.flock_name AS batch_name
          FROM products p LEFT JOIN flocks f ON f.id = p.flock_id) t";
$products = $pdo->prepare("SELECT * FROM $SRC $where ORDER BY $sortCol $sortDirSql, name ASC");
$products->execute($params);
$products = $products->fetchAll();
foreach ($products as &$r0) { $r0['stock_quantity'] = (int)$r0['live_stock']; } unset($r0);

$eggsLooseInv = 0;   // eggs are logged by crate, so nothing is kept loose
$eggsOnHandInv = $eggsLooseInv + (int)$pdo->query("SELECT COALESCE(SUM(stock_quantity*egg_units),0) FROM products WHERE category='eggs' AND is_active=1 AND egg_units>0")->fetchColumn();
$allProducts    = $pdo->query("SELECT * FROM $SRC ORDER BY category, name")->fetchAll();
foreach ($allProducts as &$r1) { $r1['stock_quantity'] = (int)$r1['live_stock']; } unset($r1);
$batchOpts      = $pdo->query("SELECT id, flock_name, bird_type, quantity FROM flocks WHERE status IN ('active','quarantine') ORDER BY flock_name")->fetchAll();
$totalProducts  = count($allProducts);
$activeCount    = count(array_filter($allProducts, fn($p) => $p['is_active']));
$outOfStock     = count(array_filter($allProducts, fn($p) => $p['stock_quantity'] <= 0 && $p['is_active']));
$lowStockCount  = count(array_filter($allProducts, fn($p) => $p['stock_quantity'] > 0 && $p['stock_quantity'] < 10 && $p['is_active']));

// Inventory value
$inventoryValue = array_sum(array_map(fn($p) => $p['price'] * $p['stock_quantity'], $allProducts));

// Category colours
$catStyles = [
    'eggs'      => ['bg'=>'#fef3c7','color'=>'#92400e','border'=>'#fcd34d'],
    'broiler'   => ['bg'=>'#ffedd5','color'=>'#9a3412','border'=>'#fb923c'],
    'layer'     => ['bg'=>'#dcfce7','color'=>'#14532d','border'=>'#4ade80'],
    'byproduct' => ['bg'=>'#f3e8ff','color'=>'#581c87','border'=>'#c084fc'],
    'other'     => ['bg'=>'#f1f5f9','color'=>'#334155','border'=>'#94a3b8'],
];
$emojis = ['eggs'=>'🥚','broiler'=>'🍗','layer'=>'🐔','byproduct'=>'🌾','other'=>'📦'];

// Sort URL helper
function sortUrl(string $col): string {
    $dir = ($_GET['sort']??'category')===$col && ($_GET['dir']??'asc')==='asc' ? 'desc' : 'asc';
    $params = array_merge($_GET, ['sort'=>$col,'dir'=>$dir]);
    return '?' . http_build_query($params);
}
function sortIcon(string $col): string {
    if (($_GET['sort']??'category') !== $col) return '<i class="fas fa-sort" style="color:#d1d5db;margin-left:4px;"></i>';
    return ($_GET['dir']??'asc')==='asc'
        ? '<i class="fas fa-sort-up" style="color:var(--primary);margin-left:4px;"></i>'
        : '<i class="fas fa-sort-down" style="color:var(--primary);margin-left:4px;"></i>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Products — Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css?v=8">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">

    <!-- Header -->
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
      <div><h1>Products &amp; Inventory</h1><p>Manage your product catalogue, stock levels and pricing</p></div>
      <button class="btn btn-primary" style="font-size:15px;padding:12px 22px;" onclick="openAddModal()">
        <i class="fas fa-plus"></i> Add Product
      </button>
    </div>

    <?php if($urlMsg): [$type,$text] = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $type; ?>" style="margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>

    <?php if($outOfStock > 0 || $lowStockCount > 0): ?>
    <div class="alert alert-<?php echo $outOfStock>0?'error':'warning'; ?>" style="margin-bottom:16px;">
      <i class="fas fa-exclamation-triangle"></i>
      <?php if($outOfStock>0): ?><strong><?php echo $outOfStock; ?> product(s) out of stock</strong> · <?php endif; ?>
      <?php if($lowStockCount>0): ?><strong><?php echo $lowStockCount; ?> running low</strong><?php endif; ?>
      — <a href="?stock=<?php echo $outOfStock>0?'out':'low'; ?>" style="font-weight:700;">View →</a>
    </div>
    <?php endif; ?>

    <!-- Inventory summary strip -->
    <div class="inv-strip">
      <div class="inv-card">
        <div class="inv-card-lbl">Total Products</div>
        <div class="inv-card-val"><?php echo $totalProducts; ?></div>
        <div class="inv-card-sub"><?php echo $activeCount; ?> active · <?php echo $totalProducts-$activeCount; ?> hidden</div>
      </div>
      <div class="inv-card" style="<?php echo $outOfStock>0?'border-color:#fca5a5;background:#fff5f5;':''; ?>">
        <div class="inv-card-lbl">Out of Stock</div>
        <div class="inv-card-val" style="color:<?php echo $outOfStock>0?'#ef4444':'#4ade80'; ?>"><?php echo $outOfStock; ?></div>
        <div class="inv-card-sub"><?php echo $outOfStock>0?'Need restocking':'All stocked ✓'; ?></div>
      </div>
      <div class="inv-card" style="<?php echo $lowStockCount>0?'border-color:#fcd34d;background:#fffbf0;':''; ?>">
        <div class="inv-card-lbl">Low Stock</div>
        <div class="inv-card-val" style="color:<?php echo $lowStockCount>0?'#d97706':'inherit'; ?>"><?php echo $lowStockCount; ?></div>
        <div class="inv-card-sub">Below 10 units</div>
      </div>
      <div class="inv-card">
        <div class="inv-card-lbl">Inventory Value</div>
        <div class="inv-card-val" style="color:var(--primary);"><?php echo formatCurrency($inventoryValue); ?></div>
        <div class="inv-card-sub">Price × stock, all products</div>
      </div>
      <div class="inv-card">
        <div class="inv-card-lbl">Eggs on hand</div>
        <div class="inv-card-val"><?php echo number_format($eggsOnHandInv); ?></div>
        <div class="inv-card-sub">in crate stock</div>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
      <!-- Category chips -->
      <div class="cat-filter-chips">
        <?php
        $allCats = array_unique(array_column($allProducts,'category'));
        ?>
        <a class="cchip <?php echo !$catFilter?'active':''; ?>"
           style="<?php echo !$catFilter?'background:var(--primary);color:white;border-color:var(--primary);':''; ?>"
           href="?<?php echo http_build_query(array_merge($_GET,['cat'=>'','stock'=>$stockFilter,'search'=>$search])); ?>">
          All <span style="background:rgba(0,0,0,.1);border-radius:50px;padding:0 5px;font-size:10px;"><?php echo $totalProducts; ?></span>
        </a>
        <?php foreach($allCats as $cat):
          $cs = $catStyles[$cat] ?? $catStyles['other'];
          $cnt = count(array_filter($allProducts, fn($p) => $p['category']===$cat));
          $isActive = $catFilter === $cat;
        ?>
        <a class="cchip <?php echo $isActive?'active':''; ?>"
           style="<?php echo $isActive ? "background:{$cs['bg']};color:{$cs['color']};border-color:{$cs['border']};" : ''; ?>"
           href="?<?php echo http_build_query(array_merge($_GET,['cat'=>$isActive?'':$cat,'stock'=>$stockFilter,'search'=>$search])); ?>">
          <?php echo ($emojis[$cat]??'').' '.ucfirst($cat); ?>
          <span style="background:rgba(0,0,0,.08);border-radius:50px;padding:0 5px;font-size:10px;"><?php echo $cnt; ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Stock filter -->
      <div style="height:24px;width:1px;background:var(--border);flex-shrink:0;"></div>
      <a class="cchip <?php echo $stockFilter==='out'?'active':''; ?>"
         style="<?php echo $stockFilter==='out'?'background:#fee2e2;color:#991b1b;border-color:#fca5a5;':''; ?>"
         href="?<?php echo http_build_query(array_merge($_GET,['stock'=>$stockFilter==='out'?'':'out'])); ?>">
        ⛔ Out of Stock
      </a>
      <a class="cchip <?php echo $stockFilter==='low'?'active':''; ?>"
         style="<?php echo $stockFilter==='low'?'background:#fef3c7;color:#92400e;border-color:#fcd34d;':''; ?>"
         href="?<?php echo http_build_query(array_merge($_GET,['stock'=>$stockFilter==='low'?'':'low'])); ?>">
        ⚠ Low Stock
      </a>

      <!-- Search -->
      <div style="height:24px;width:1px;background:var(--border);flex-shrink:0;"></div>
      <form method="GET" style="display:flex;gap:6px;margin-left:auto;">
        <?php foreach(['cat'=>$catFilter,'stock'=>$stockFilter,'sort'=>$sortBy,'dir'=>$sortDir] as $k=>$v): ?>
        <input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars($v); ?>">
        <?php endforeach; ?>
        <input type="text" name="search" class="fld-ctrl" placeholder="🔍 Search products..." value="<?php echo htmlspecialchars($search); ?>" style="width:220px;">
        <?php if($search): ?><a href="products.php" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
      </form>
    </div>

    <!-- Bulk action bar -->
    <form method="POST" id="bulkForm">
      <div class="bulk-bar" id="bulkBar">
        <i class="fas fa-check-square" style="color:var(--primary);"></i>
        <span id="bulkCount" style="font-weight:700;font-size:14px;"></span> selected
        <select name="bulk_action" class="fld-ctrl" style="width:160px;">
          <option value="">Choose action...</option>
          <option value="activate">✅ Activate</option>
          <option value="deactivate">🙈 Deactivate</option>
          <option value="delete">🗑 Delete</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm" onclick="var a=document.querySelector('[name=bulk_action]').value; return a !== '' && (a !== 'delete' || confirm('Delete the selected products? Products with sales history will be hidden instead.'))">Apply</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelection()">Cancel</button>
        <div id="bulkInputs"></div>
      </div>

    <!-- Products table -->
    <div class="card" style="overflow:hidden;">
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:15px;font-weight:700;">
          <?php echo $catFilter ? ucfirst($catFilter) : 'All'; ?> Products
          <span style="font-size:13px;color:#9ca3af;font-weight:400;margin-left:6px;">(<?php echo count($products); ?>)</span>
        </div>
        <span style="font-size:12px;color:#9ca3af;">Click stock number to edit inline · Click image to zoom</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="prod-table">
          <thead>
            <tr>
              <th class="cb-cell"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
              <th style="width:64px;">Image</th>
              <th><a href="<?php echo sortUrl('name'); ?>">Name <?php echo sortIcon('name'); ?></a></th>
              <th>Category</th>
              <th><a href="<?php echo sortUrl('price'); ?>">Price <?php echo sortIcon('price'); ?></a></th>
              <th>Unit</th>
              <th><a href="<?php echo sortUrl('stock_quantity'); ?>">Stock <?php echo sortIcon('stock_quantity'); ?></a></th>
              <th>Min Order</th>
              <th>Status</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($products as $p):
              $isLow  = $p['stock_quantity'] > 0 && $p['stock_quantity'] < 10;
              $isOut  = $p['stock_quantity'] <= 0;
              $emoji  = $emojis[$p['category']] ?? '📦';
              $cs     = $catStyles[$p['category']] ?? $catStyles['other'];
              $maxS   = max($p['stock_quantity'], 20);
              $barPct = $isOut ? 0 : min(100, ($p['stock_quantity']/$maxS)*100);
              $barClr = $isOut ? '#ef4444' : ($isLow ? '#fbbf24' : '#4ade80');
              $stockNumColor = $isOut ? '#ef4444' : ($isLow ? '#d97706' : '#16a34a');
            ?>
            <tr class="<?php echo !$p['is_active']?'is-hidden':''; ?>" id="prod_row_<?php echo $p['id']; ?>">
              <td class="cb-cell">
                <input type="checkbox" class="prod-cb" value="<?php echo $p['id']; ?>" onchange="onCbChange()">
              </td>
              <td>
                <?php if(!empty($p['image_url'])): ?>
                <div class="prod-thumb" onclick="zoomImg('../public/<?php echo htmlspecialchars($p['image_url']); ?>')">
                  <img src="../public/<?php echo htmlspecialchars($p['image_url']); ?>"
                       onerror="this.parentNode.innerHTML='<span><?php echo $emoji; ?></span>'" alt="">
                </div>
                <?php else: ?>
                <div class="prod-thumb"><?php echo $emoji; ?></div>
                <?php endif; ?>
              </td>
              <td>
                <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                <?php if(!$p['is_active']): ?><span style="font-size:10px;font-weight:700;background:#f1f5f9;color:#64748b;padding:2px 6px;border-radius:4px;margin-left:6px;">HIDDEN</span><?php endif; ?>
                <?php if($p['description']): ?>
                <br><small style="color:#9ca3af;font-size:11px;"><?php echo htmlspecialchars(substr($p['description'],0,55)); ?><?php echo strlen($p['description'])>55?'…':''; ?></small>
                <?php endif; ?>
              </td>
              <td>
                <a class="cat-badge"
                   style="background:<?php echo $cs['bg']; ?>;color:<?php echo $cs['color']; ?>;border-color:<?php echo $cs['border']; ?>;"
                   href="?<?php echo http_build_query(array_merge($_GET,['cat'=>$p['category']])); ?>">
                  <?php echo ($emojis[$p['category']]??'').' '.ucfirst($p['category']); ?>
                </a>
              </td>
              <td style="font-size:15px;font-weight:700;color:var(--primary);"><?php echo formatCurrency($p['price']); ?></td>
              <td style="font-size:13px;color:#6b7280;"><?php echo htmlspecialchars($p['unit']); ?></td>
              <td>
                <div class="stock-cell">
                  <div class="stock-bar"><div class="stock-bar-fill" style="width:<?php echo $barPct; ?>%;background:<?php echo $barClr; ?>;"></div></div>
                  <!-- Inline editable stock number -->
                  <span class="stock-num" id="snum_<?php echo $p['id']; ?>"
                        style="color:<?php echo $stockNumColor; ?>;"
                        onclick="startStockEdit(<?php echo $p['id']; ?>)"
                        title="Click to edit stock">
                    <?php echo $isOut ? '0' : number_format($p['stock_quantity']); ?>
                  </span>
                  <input type="number" class="stock-edit-input" id="sedit_<?php echo $p['id']; ?>"
                         value="<?php echo $p['stock_quantity']; ?>" min="0"
                         onblur="saveStockEdit(<?php echo $p['id']; ?>)"
                         onkeydown="if(event.key==='Enter')saveStockEdit(<?php echo $p['id']; ?>);if(event.key==='Escape')cancelStockEdit(<?php echo $p['id']; ?>);">
                  <?php if($isOut): ?>
                    <span class="stock-label out">OUT</span>
                  <?php elseif($isLow): ?>
                    <span class="stock-label low">LOW</span>
                  <?php endif; ?>
                </div>
                <?php if($p['category']==='eggs' && (int)$p['egg_units']>0): ?>
                  <div class="stock-sub"><?php echo number_format($p['stock_quantity'] * (int)$p['egg_units']); ?> eggs</div>
                <?php elseif(!empty($p['flock_id'])): ?>
                  <div class="stock-sub">Batch: <?php echo htmlspecialchars($p['batch_name'] ?? ''); ?></div>
                <?php endif; ?>
              </td>
              <td style="text-align:center;color:#6b7280;"><?php echo $p['min_order']; ?></td>
              <td>
                <span class="status-badge-inline" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:50px;font-size:11px;font-weight:700;
                      background:<?php echo $p['is_active']?'#dcfce7':'#f1f5f9'; ?>;
                      color:<?php echo $p['is_active']?'#15803d':'#64748b'; ?>;">
                  <span style="width:6px;height:6px;border-radius:50%;background:<?php echo $p['is_active']?'#22c55e':'#94a3b8'; ?>;"></span>
                  <?php echo $p['is_active']?'Active':'Hidden'; ?>
                </span>
              </td>
              <td>
                <div style="display:flex;gap:5px;justify-content:flex-end;">
                  <button type="button" class="act-btn" title="Edit product"
                    onclick="prodOpenEditModal(<?php echo htmlspecialchars(json_encode($p),ENT_QUOTES); ?>)">
                    <i class="fas fa-pen"></i>
                  </button>
                  <button type="button" class="act-btn <?php echo $p['is_active']?'danger':''; ?>"
                          title="<?php echo $p['is_active']?'Hide from customers':'Show to customers'; ?>"
                          onclick="toggleProduct(<?php echo $p['id']; ?>, this)">
                    <i class="fas fa-eye<?php echo $p['is_active']?'-slash':''; ?>" id="toggle_icon_<?php echo $p['id']; ?>"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($products)): ?>
            <tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:48px;">
              <i class="fas fa-box-open" style="font-size:36px;color:#e5e7eb;margin-bottom:12px;display:block;"></i>
              <strong style="display:block;margin-bottom:4px;">No products match your filters</strong>
              <a href="products.php" style="color:var(--primary);font-size:13px;">Clear all filters →</a>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    </form>

  </div>
</div>

<!-- Image zoom overlay -->
<div class="img-zoom-overlay" id="imgZoom" onclick="this.classList.remove('show')">
  <img id="imgZoomEl" src="" alt="">
</div>

<!-- ── ADD MODAL ─────────────────────────────────────────── -->
<div class="modal-overlay" id="addProductModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header">
      <h3>Add New Product</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="add_product" value="1">
      <div class="form-row">
        <div class="form-group">
          <label>Product Name *</label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. Fresh Eggs (Crate of 30)">
        </div>
        <div class="form-group">
          <label>Category *</label>
          <select name="category" class="form-control" required id="addCategory">
            <option value="eggs">🥚 Eggs</option>
            <option value="broiler">🍗 Broiler</option>
            <option value="layer">🐔 Layer</option>
            <option value="byproduct">🌾 Byproduct</option>
            <option value="other">📦 Other</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" class="form-control" rows="2" placeholder="Brief product description..."></textarea>
      </div>
      <div class="form-row-3">
        <div class="form-group">
          <label>Price (₵) *</label>
          <input type="number" name="price" class="form-control" required min="0" step="0.01" placeholder="0.00">
        </div>
        <div class="form-group">
          <label>Unit <span id="addUnitHint" style="color:var(--primary);font-size:11px;"></span></label>
          <input type="text" name="unit" class="form-control" id="addUnitField" value="piece">
        </div>
        <div class="form-group" id="addStockGroup">
          <label id="addStockLabel">Initial Stock</label>
          <input type="number" name="stock_quantity" id="addStockInput" class="form-control" min="0" value="0">
          <div class="form-hint" id="addStockHint" style="display:none;">Egg stock comes from production. Log crates on the Egg Production page.</div>
        </div>
      </div>
      <div class="form-group" id="batchGroupAdd" style="display:none;">
        <label>Batch (birds come from this batch)</label>
        <select name="flock_id" id="addBatch" class="form-control">
          <option value="">Choose a batch…</option>
          <?php foreach($batchOpts as $bo): ?>
          <option value="<?php echo (int)$bo['id']; ?>" data-type="<?php echo htmlspecialchars($bo['bird_type']); ?>"><?php echo htmlspecialchars($bo['flock_name']); ?> — <?php echo number_format($bo['quantity']); ?> birds</option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Each sale takes birds from this batch until it is finished.</div>
      </div>
      <div class="form-group" id="eggsPerCrateGroup" style="display:none;">
        <label>Eggs per Crate</label>
        <select name="eggs_per_crate" class="form-control">
          <option value="30">30 eggs per crate (standard)</option>
          <option value="15">15 eggs per half-crate</option>
          <option value="12">12 eggs per tray</option>
        </select>
        <label style="margin-top:10px;display:block;">Size</label>
        <select name="egg_size" class="form-control" id="addEggSize">
          <option value="small">Small</option>
          <option value="medium">Medium</option>
          <option value="large">Large</option>
        </select>
      </div>
      <div class="form-group">
        <label>Minimum Order</label>
        <input type="number" name="min_order" class="form-control" min="1" value="1" style="width:110px;">
      </div>
      <div class="form-group">
        <label>Product Image <small style="color:#9ca3af;">(optional · JPG/PNG/WebP · max 3MB)</small></label>
        <div class="img-drop" onclick="document.getElementById('addImgInput').click()">
          <i class="fas fa-cloud-upload-alt" style="font-size:28px;color:#9CA3AF;margin-bottom:8px;"></i>
          <div style="font-size:13px;color:#888;">Click to upload · or drag & drop</div>
          <input type="file" id="addImgInput" name="product_image" accept="image/*" onchange="previewImg(this,'addPreview')">
        </div>
        <img id="addPreview" class="img-preview" alt="Preview">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Product</button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT MODAL ────────────────────────────────────────── -->
<div class="modal-overlay" id="editProductModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header">
      <h3>Edit Product</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="edit_product" value="1">
      <input type="hidden" name="product_id" id="editId">
      <div class="form-row">
        <div class="form-group">
          <label>Product Name *</label>
          <input type="text" name="name" id="editName" class="form-control" required>
        </div>
        <div class="form-group">
          <label>Category *</label>
          <select name="category" id="editCategory" class="form-control" required>
            <option value="eggs">🥚 Eggs</option>
            <option value="broiler">🍗 Broiler</option>
            <option value="layer">🐔 Layer</option>
            <option value="byproduct">🌾 Byproduct</option>
            <option value="other">📦 Other</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" id="editDesc" class="form-control" rows="2"></textarea>
      </div>
      <div class="form-row-3">
        <div class="form-group">
          <label>Price (₵) *</label>
          <input type="number" name="price" id="editPrice" class="form-control" required min="0" step="0.01">
        </div>
        <div class="form-group">
          <label>Unit <span id="editUnitHint" style="color:var(--primary);font-size:11px;"></span></label>
          <input type="text" name="unit" id="editUnit" class="form-control">
        </div>
        <div class="form-group">
          <label>Min Order</label>
          <input type="number" name="min_order" id="editMinOrder" class="form-control" min="1">
        </div>
      </div>
      <div class="form-group" id="batchGroupEdit" style="display:none;">
        <label>Batch (birds come from this batch)</label>
        <select name="flock_id" id="editBatch" class="form-control">
          <option value="">Choose a batch…</option>
          <?php foreach($batchOpts as $bo): ?>
          <option value="<?php echo (int)$bo['id']; ?>" data-type="<?php echo htmlspecialchars($bo['bird_type']); ?>"><?php echo htmlspecialchars($bo['flock_name']); ?> — <?php echo number_format($bo['quantity']); ?> birds</option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Each sale takes birds from this batch until it is finished.</div>
      </div>
      <div class="form-group" id="editEggUnitsGroup" style="display:none;">
        <label>Eggs per pack</label>
        <select name="eggs_per_crate" id="editEggUnits" class="form-control">
          <option value="30">30 eggs per crate</option>
          <option value="15">15 eggs per half-crate</option>
          <option value="12">12 eggs per tray</option>
        </select>
        <label style="margin-top:10px;display:block;">Size</label>
        <select name="egg_size" class="form-control" id="editEggSize">
          <option value="small">Small</option>
          <option value="medium">Medium</option>
          <option value="large">Large</option>
        </select>
      </div>
<div class="form-group">
        <label>Product Image</label>
        <div id="editCurrentImg" style="margin-bottom:10px;display:none;">
          <img id="editCurrentImgEl" src="" alt="" style="height:90px;border-radius:8px;object-fit:cover;border:1px solid var(--border);">
          <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Upload a new image to replace</div>
        </div>
        <div class="img-drop" onclick="document.getElementById('editImgInput').click()">
          <i class="fas fa-cloud-upload-alt" style="font-size:22px;color:#9CA3AF;margin-bottom:6px;"></i>
          <div style="font-size:13px;color:#888;">Click to upload new image</div>
          <input type="file" id="editImgInput" name="product_image" accept="image/*" onchange="previewImg(this,'editPreview')">
        </div>
        <img id="editPreview" class="img-preview" alt="Preview">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ── RESTOCK MODAL ─────────────────────────────────────── -->
<div class="modal-overlay" id="restockModal">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header">
      <h3><i class="fas fa-boxes" style="color:var(--primary);margin-right:8px;"></i>Adjust Stock</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="update_stock" value="1">
      <input type="hidden" name="product_id" id="restockProductId">
      <div class="form-group" style="padding:0 32px;">
        <label>Product</label>
        <div id="restockProductName" style="padding:10px 14px;background:var(--cream);border-radius:10px;font-weight:700;font-size:14px;"></div>
      </div>
      <div class="form-group" style="padding:0 32px;">
        <label>Quantity to Add *</label>
        <input type="number" name="add_stock" class="form-control" required min="1" placeholder="e.g. 50">
        <small style="color:#9ca3af;">This will be added on top of current stock</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Stock</button>
      </div>
    </form>
  </div>
</div>

<?php

$pageData = [];
?>
<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
