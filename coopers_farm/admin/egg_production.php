<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
requireDayIfPost($pdo);

define('HDEP_TARGET', 85);   // hen-day production target (%)
define('BREAK_ALERT', 1.0);  // breakage % that counts as high

$layerFlocks = $pdo->query("SELECT id, flock_name, bird_type, quantity, arrival_date, status FROM flocks
    WHERE bird_type='layer' AND status IN ('active','quarantine') ORDER BY flock_name")->fetchAll();
$flockById = [];
foreach ($layerFlocks as $f) { $flockById[(int)$f['id']] = $f; }

// ── Crate stock helpers ───────────────────────────────────────
// The egg product for a crate type (30, 15 or 12 eggs) and size. Found by type and size, active or not.
function eggProductFor(PDO $pdo, int $units, string $size): ?array {
    $s = $pdo->prepare("SELECT id, name FROM products WHERE category='eggs' AND egg_units=? AND egg_size=?
        ORDER BY is_active DESC, id LIMIT 1");
    $s->execute([$units, $size]);
    return $s->fetch() ?: null;
}

// Find the egg product for a crate type and size, or create it. A new product is hidden from sales
// and has a price of 0 until someone sets its price on the Products page.
function eggProductOrCreate(PDO $pdo, int $units, string $size): array {
    $found = eggProductFor($pdo, $units, $size);
    if ($found) return $found;
    $typeName = [30 => 'Standard crate (30)', 15 => 'Half crate (15)', 12 => 'Tray (12)'][$units];
    $unit     = [30 => 'crate', 15 => 'half crate', 12 => 'tray'][$units];
    $name     = ucfirst($size) . ' Eggs — ' . $typeName;
    $pdo->prepare("INSERT INTO products (name, category, description, price, unit, stock_quantity, min_order, is_active, egg_units, egg_size)
        VALUES (?, 'eggs', 'Created from egg production. Set the price, then make it active.', 0, ?, 0, 1, 0, ?, ?)")
        ->execute([$name, $unit, $units, $size]);
    $id = (int)$pdo->lastInsertId();
    $GLOBALS['eggCreatedProducts'][] = ['id' => $id, 'name' => $name];   // audited after the commit
    return ['id' => $id, 'name' => $name];
}

// Take back the crates a production record added (used before it is edited)
function reversePacks(PDO $pdo, int $productionId): void {
    $logs = $pdo->prepare("SELECT product_id, packs FROM egg_pack_log WHERE production_id=? AND packs>0");
    $logs->execute([$productionId]);
    foreach ($logs->fetchAll() as $l) {
        $pdo->prepare("UPDATE products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id=?")
            ->execute([(int)$l['packs'], (int)$l['product_id']]);
        $pdo->prepare("INSERT INTO inventory_logs (product_id, change_amount, reason, notes, created_by)
            VALUES (?,?,'adjustment',?,?)")
            ->execute([(int)$l['product_id'], -(int)$l['packs'], "Egg production #{$productionId} edited", $_SESSION['user_id']]);
    }
    $pdo->prepare("DELETE FROM egg_pack_log WHERE production_id=?")->execute([$productionId]);
}

// Add the crate lines from a production record to their egg products
function addPacks(PDO $pdo, int $productionId, array $lines, int $userId): array {
    $out = [];
    foreach ($lines as $ln) {
        $prod = eggProductOrCreate($pdo, (int)$ln['units'], $ln['size']);
        $n = (int)$ln['count'];
        $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id=?")->execute([$n, (int)$prod['id']]);
        $pdo->prepare("INSERT INTO egg_pack_log (production_id, product_id, packs, eggs) VALUES (?,?,?,?)")
            ->execute([$productionId, (int)$prod['id'], $n, $n * (int)$ln['units']]);
        $pdo->prepare("INSERT INTO inventory_logs (product_id, change_amount, reason, notes, created_by)
            VALUES (?,?,'production',?,?)")
            ->execute([(int)$prod['id'], $n, "Egg production #{$productionId}", $userId]);
        $out[] = ['name' => $prod['name'], 'packs' => $n];
    }
    return $out;
}

// ── POST: add or edit a production record ─────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_production'])) {
    $rid     = (int)($_POST['record_id'] ?? 0);
    $fid     = (int)($_POST['flock_id'] ?? 0);
    $date    = $_POST['production_date'] ?? '';
    $hens    = (int)($_POST['hens_present'] ?? 0);
    $broken  = max(0, (int)($_POST['broken_eggs'] ?? 0));
    $rej     = max(0, (int)($_POST['rejected_eggs'] ?? 0));
    $notes   = sanitize($_POST['notes'] ?? '');
    $flock   = $flockById[$fid] ?? null;

    // Crate lines: crate type + size + count. Lines with the same type and size are merged.
    $lines = []; $badLine = false;
    $types  = $_POST['crate_units'] ?? [];
    $sizes  = $_POST['crate_size'] ?? [];
    $counts = $_POST['crate_count'] ?? [];
    foreach ($types as $i => $u) {
        $u  = (int)$u;
        $sz = $sizes[$i] ?? '';
        $n  = max(0, (int)($counts[$i] ?? 0));
        if ($n <= 0) continue;
        if (!in_array($u, [30, 15, 12], true) || !in_array($sz, ['small', 'medium', 'large'], true)) { $badLine = true; continue; }
        $key = $u . '|' . $sz;
        $lines[$key] = ['units' => $u, 'size' => $sz, 'count' => ($lines[$key]['count'] ?? 0) + $n];
    }
    $lines   = array_values($lines);
    $packed  = 0;
    $gradeEggs = ['small' => 0, 'medium' => 0, 'large' => 0];
    foreach ($lines as $ln) {
        $e = $ln['units'] * $ln['count'];
        $packed += $e;
        $gradeEggs[$ln['size']] += $e;
    }
    $eggs = $packed + $broken + $rej;   // everything collected that day
    $err = null;

    if (!$flock)                                   $err = 'Choose a laying flock.';
    elseif (!$date || $date > date('Y-m-d'))       $err = 'The date cannot be in the future.';
    elseif ($date < $flock['arrival_date'])        $err = 'That date is before this flock arrived on '.date('M d, Y', strtotime($flock['arrival_date'])).'.';
    elseif ($hens < 1 || $hens > (int)$flock['quantity'])
                                                   $err = "Hens present must be between 1 and {$flock['quantity']} (birds alive in {$flock['flock_name']}).";
    elseif ($badLine)                              $err = 'Each crate line needs a crate type and a size.';
    elseif ($packed < 1)                           $err = 'Add at least one crate.';
    elseif ($eggs > $hens)                         $err = "Eggs ({$eggs}) cannot be more than hens present ({$hens}). A hen lays at most one egg a day.";
    else {
        $dup = $pdo->prepare("SELECT id FROM egg_production WHERE flock_id=? AND production_date=? AND id<>?");
        $dup->execute([$fid, $date, $rid]);
        if ($dup->fetch()) $err = 'A record already exists for this flock on that day. Edit that record instead.';
    }
    if ($err) {
        header('Location: egg_production.php?msg=' . urlencode('error:' . $err)); exit;
    }

    $uid = (int)$_SESSION['user_id'];
    $pdo->beginTransaction();
    if ($rid) {
        $os = $pdo->prepare("SELECT * FROM egg_production WHERE id=?");
        $os->execute([$rid]);
        $old = $os->fetch();
        if (!$old) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header('Location: egg_production.php?msg=' . urlencode('error:Record not found.')); exit;
        }
        reversePacks($pdo, $rid);   // take back the crates this record added; they are added again below
        $pdo->prepare("UPDATE egg_production SET flock_id=?, production_date=?, hens_present=?, quantity=?, broken_eggs=?,
            rejected_eggs=?, grade_large=?, grade_medium=?, grade_small=?, notes=?, updated_by=? WHERE id=?")
            ->execute([$fid, $date, $hens, $eggs, $broken, $rej, $gradeEggs['large'], $gradeEggs['medium'], $gradeEggs['small'], $notes, $uid, $rid]);
        $savedId = $rid;
        $audit = ['edit_egg_production', $rid, "Edited {$flock['flock_name']} {$date}: {$packed} good eggs, {$broken} broken, {$rej} rejected"];
    } else {
        $pdo->prepare("INSERT INTO egg_production (flock_id, production_date, hens_present, quantity, broken_eggs, rejected_eggs,
            grade_large, grade_medium, grade_small, notes, recorded_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$fid, $date, $hens, $eggs, $broken, $rej, $gradeEggs['large'], $gradeEggs['medium'], $gradeEggs['small'], $notes, $uid]);
        $savedId = (int)$pdo->lastInsertId();
        $audit = ['log_egg_production', $savedId, "Logged {$flock['flock_name']} on {$date}: {$packed} good eggs, {$broken} broken, {$rej} rejected"];
    }
    $added = addPacks($pdo, $savedId, $lines, $uid);
    if ($pdo->inTransaction()) $pdo->commit();
    foreach ($GLOBALS['eggCreatedProducts'] ?? [] as $cp) {
        auditLog($pdo, 'create_egg_product', 'product', $cp['id'], "Created {$cp['name']} from egg production (hidden until priced)");
    }
    auditLog($pdo, $audit[0], 'egg_production', $audit[1], $audit[2]);

    $parts = array_map(fn($x) => $x['packs'] . ' × ' . $x['name'], $added);
    $msg = 'success:Saved. ' . ($parts ? 'Added to stock: ' . implode(', ', $parts) . '.' : 'No crates added to stock.');
    header('Location: egg_production.php?msg=' . urlencode($msg)); exit;
}

// ── Filters ───────────────────────────────────────────────────
$urlMsg      = $_GET['msg'] ?? '';
$range       = $_GET['range'] ?? '30';
if (!in_array($range, ['1','7','30','90'], true)) $range = '30';
$flockFilter = (int)($_GET['flock_id'] ?? 0);
$days        = (int)$range;
$whereRange  = "ep.production_date >= DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)";

$st = $pdo->prepare("SELECT ep.*, f.flock_name, f.bird_type, COALESCE(ep.hens_present, f.quantity) AS hens
    FROM egg_production ep JOIN flocks f ON f.id = ep.flock_id
    WHERE $whereRange AND (? = 0 OR ep.flock_id = ?)
    ORDER BY ep.production_date DESC, f.flock_name LIMIT 500");
$st->execute([$flockFilter, $flockFilter]);
$records = $st->fetchAll();

// Crate lines each record added (crate type, size, count) — used for showing and editing a record
$recLines = [];
foreach ($pdo->query("SELECT pl.production_id, p.egg_units AS units, p.egg_size AS size, SUM(pl.packs) AS n
        FROM egg_pack_log pl JOIN products p ON p.id = pl.product_id
        WHERE pl.production_id > 0 GROUP BY pl.production_id, p.egg_units, p.egg_size")->fetchAll() as $pm) {
    $recLines[(int)$pm['production_id']][] = ['units' => (int)$pm['units'], 'size' => $pm['size'], 'count' => (int)$pm['n']];
}
function crateText(array $lines): string {
    if (!$lines) return '—';
    return implode(', ', array_map(fn($l) => $l['count'] . ' × ' . $l['units'] . ' ' . ucfirst($l['size']), $lines));
}

// ── CSV export of the filtered records ────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="egg_production_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Flock','Hens','Eggs','Broken','Rejected','Good','HDEP %','Breakage %','Large','Medium','Small','Notes']);
    foreach ($records as $r) {
        $good = (int)$r['quantity'] - (int)$r['broken_eggs'] - (int)$r['rejected_eggs'];
        $hd   = $r['hens'] > 0 ? round($r['quantity'] / $r['hens'] * 100, 1) : '';
        $bk   = $r['quantity'] > 0 ? round(($r['broken_eggs'] + $r['rejected_eggs']) / $r['quantity'] * 100, 2) : '';
        fputcsv($out, [$r['production_date'], $r['flock_name'], $r['hens'], $r['quantity'], $r['broken_eggs'],
            $r['rejected_eggs'], $good, $hd, $bk, $r['grade_large'], $r['grade_medium'], $r['grade_small'], $r['notes']]);
    }
    fclose($out); exit;
}

// ── Today, yesterday and 7-day numbers ────────────────────────
$dayRow = function (string $when) use ($pdo) {
    return $pdo->query("SELECT COALESCE(SUM(ep.quantity),0) AS eggs, COALESCE(SUM(COALESCE(ep.hens_present,f.quantity)),0) AS hens,
        COALESCE(SUM(ep.broken_eggs + ep.rejected_eggs),0) AS bad
        FROM egg_production ep JOIN flocks f ON f.id = ep.flock_id WHERE ep.production_date = $when")->fetch();
};
$today     = $dayRow('CURDATE()');
$yesterday = $dayRow('DATE_SUB(CURDATE(), INTERVAL 1 DAY)');
$eggsToday = (int)$today['eggs'];
$hdepToday = (int)$today['hens'] > 0 ? round($eggsToday / (int)$today['hens'] * 100, 1) : null;
$eggsVsYest = (int)$yesterday['eggs'] > 0 ? round(($eggsToday - (int)$yesterday['eggs']) / (int)$yesterday['eggs'] * 100, 1) : null;

$liveLayers = (int)array_sum(array_column($layerFlocks, 'quantity'));
$targetEggs = (int)round($liveLayers * HDEP_TARGET / 100);
$targetPct  = $targetEggs > 0 ? round($eggsToday / $targetEggs * 100, 1) : null;

$b7 = $pdo->query("SELECT COALESCE(SUM(quantity),0) AS e, COALESCE(SUM(broken_eggs + rejected_eggs),0) AS b
    FROM egg_production WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetch();
$bp = $pdo->query("SELECT COALESCE(SUM(quantity),0) AS e, COALESCE(SUM(broken_eggs + rejected_eggs),0) AS b
    FROM egg_production WHERE production_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch();
$breakRate7 = (int)$b7['e'] > 0 ? round((int)$b7['b'] / (int)$b7['e'] * 100, 2) : null;
$breakPrev  = (int)$bp['e'] > 0 ? round((int)$bp['b'] / (int)$bp['e'] * 100, 2) : null;

$feed7 = (float)$pdo->query("SELECT COALESCE(SUM(fl.quantity_kg),0) FROM feed_logs fl JOIN flocks f ON f.id = fl.flock_id
    WHERE f.bird_type='layer' AND fl.log_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();
$feedPer100 = (int)$b7['e'] > 0 ? round($feed7 / (int)$b7['e'] * 100, 1) : null;

// Eggs on hand = what is in crate stock
$eggsOnHand = (int)$pdo->query("SELECT COALESCE(SUM(stock_quantity * egg_units),0) FROM products
    WHERE category='eggs' AND is_active=1 AND egg_units > 0")->fetchColumn();

// Grades in the selected range
$gradeTotals = ['large' => 0, 'medium' => 0, 'small' => 0];
foreach ($records as $r) {
    $gradeTotals['large']  += (int)$r['grade_large'];
    $gradeTotals['medium'] += (int)$r['grade_medium'];
    $gradeTotals['small']  += (int)$r['grade_small'];
}
$gradeAll = array_sum($gradeTotals);

// ── Per-flock performance (last 7 days) and insights ──────────
$qEggs7 = $pdo->prepare("SELECT COALESCE(SUM(ep.quantity),0) AS e, COALESCE(SUM(COALESCE(ep.hens_present,f.quantity)),0) AS h,
    COALESCE(SUM(ep.broken_eggs + ep.rejected_eggs),0) AS b
    FROM egg_production ep JOIN flocks f ON f.id = ep.flock_id
    WHERE ep.flock_id=? AND ep.production_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
$qFeed7 = $pdo->prepare("SELECT COALESCE(SUM(quantity_kg),0) FROM feed_logs WHERE flock_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
$qPrev7 = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM egg_production WHERE flock_id=?
    AND production_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
$qToday = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) AS eggs, COUNT(*) AS n FROM egg_production WHERE flock_id=? AND production_date=CURDATE()");
$qCases = $pdo->prepare("SELECT COUNT(*) FROM health_records WHERE flock_id=? AND status IN ('active','monitoring')");

$perf = [];
foreach ($layerFlocks as $f) {
    $fid = (int)$f['id'];
    $qEggs7->execute([$fid]); $e7 = $qEggs7->fetch();
    $qFeed7->execute([$fid]); $fd7 = (float)$qFeed7->fetchColumn();
    $qPrev7->execute([$fid]); $prevTotal = (int)$qPrev7->fetchColumn();
    $qToday->execute([$fid]); $tr = $qToday->fetch();
    $qCases->execute([$fid]); $cases = (int)$qCases->fetchColumn();

    $hdep  = (int)$e7['h'] > 0 ? round((int)$e7['e'] / (int)$e7['h'] * 100, 1) : null;
    $break = (int)$e7['e'] > 0 ? round((int)$e7['b'] / (int)$e7['e'] * 100, 2) : null;
    $fpe   = (int)$e7['e'] > 0 ? round($fd7 / (int)$e7['e'] * 100, 1) : null;
    $avg7  = $prevTotal / 7;
    $dropPct = ((int)$tr['n'] > 0 && $avg7 > 0) ? round(((int)$tr['eggs'] - $avg7) / $avg7 * 100, 1) : null;
    $status = $hdep === null ? 'none' : ($hdep >= HDEP_TARGET ? 'good' : ($hdep >= 70 ? 'watch' : 'low'));

    $perf[] = ['id'=>$fid, 'name'=>$f['flock_name'], 'qty'=>(int)$f['quantity'], 'eggs7'=>(int)$e7['e'],
               'hdep'=>$hdep, 'break'=>$break, 'feed7'=>round($fd7,1), 'fpe'=>$fpe,
               'drop'=>$dropPct, 'cases'=>$cases, 'status'=>$status];
}

$insights = [];
foreach ($perf as $p) {
    if ($p['drop'] !== null && $p['drop'] <= -15)
        $insights[] = ['red', "{$p['name']} produced ".abs($p['drop'])."% fewer eggs today than its 7-day average.", "#flock-{$p['id']}"];
    if ($p['status'] === 'low')
        $insights[] = ['red', "{$p['name']} HDEP is {$p['hdep']}% — below 70%. Check health, feed and lighting.", "#flock-{$p['id']}"];
    if ($p['cases'] > 0)
        $insights[] = ['amber', "{$p['name']} has an open health case — production may be affected.", 'health.php?flock_id='.$p['id']];
    if ($p['status'] === 'good' && $p['hdep'] >= HDEP_TARGET + 5)
        $insights[] = ['green', "{$p['name']} is performing well: HDEP {$p['hdep']}%.", "#flock-{$p['id']}"];
}
if ($breakRate7 !== null && $breakRate7 >= BREAK_ALERT && ($breakPrev === null || $breakRate7 > $breakPrev * 1.5))
    $insights[] = ['amber', "Breakage rose to {$breakRate7}% this week (was ".($breakPrev ?? 0)."%).", '#breakage'];
$feedFarm = $feedPer100;
foreach ($perf as $p) {
    if ($p['fpe'] !== null && $feedFarm && $p['fpe'] > $feedFarm * 1.2)
        $insights[] = ['amber', "{$p['name']} uses ".$p['fpe']." kg feed per 100 eggs — well above the farm average of {$feedFarm} kg.", '#flock-'.$p['id']];
}
usort($insights, fn($a, $b) => array_search($a[0], ['red','amber','green']) <=> array_search($b[0], ['red','amber','green']));

// ── Data for the page script ──────────────────────────────────
$series = $pdo->query("SELECT ep.production_date AS d, ep.flock_id AS fid, ep.quantity AS e,
        COALESCE(ep.hens_present, f.quantity) AS h, ep.broken_eggs AS b, ep.rejected_eggs AS r
    FROM egg_production ep JOIN flocks f ON f.id = ep.flock_id
    WHERE ep.production_date >= DATE_SUB(CURDATE(), INTERVAL 89 DAY) ORDER BY ep.production_date")->fetchAll();

$eggProducts = $pdo->query("SELECT id, name, egg_units AS units, egg_size AS size FROM products
    WHERE category='eggs' AND egg_units > 0 AND egg_size IS NOT NULL ORDER BY egg_units DESC, egg_size")->fetchAll();

$pageData = [
    'series'     => $series,
    'flocks'     => array_map(fn($f) => ['id'=>(int)$f['id'], 'name'=>$f['flock_name'], 'qty'=>(int)$f['quantity'], 'arrival'=>$f['arrival_date']], $layerFlocks),
    'records'    => array_map(function ($r) use ($recLines) {
                        return ['id'=>(int)$r['id'], 'flock_id'=>(int)$r['flock_id'], 'date'=>$r['production_date'],
                            'hens'=>(int)$r['hens'], 'lines'=>$recLines[(int)$r['id']] ?? [],
                            'broken'=>(int)$r['broken_eggs'], 'rejected'=>(int)$r['rejected_eggs'],
                            'notes'=>(string)$r['notes']];
                    }, $records),
    'products'   => array_map(fn($u) => ['id'=>(int)$u['id'], 'name'=>$u['name'], 'units'=>(int)$u['units'], 'size'=>$u['size']], $eggProducts),
    'hdepTarget' => HDEP_TARGET,
];

function hdepClass(?float $h): string {
    if ($h === null) return 'neutral';
    return $h >= HDEP_TARGET ? 'good' : ($h >= 70 ? 'watch' : 'low');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Egg Production — Coopers Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
    <h1>Egg Production</h1>
    <p>Log collections by crate. Crates go straight into stock.</p>
  </div>
  <div class="dash-quick">
    <a class="dq-btn" href="egg_production.php?<?php echo http_build_query(['range'=>$range,'flock_id'=>$flockFilter,'export'=>'csv']); ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
    <button class="dq-btn dq-primary" type="button" data-open-egg="0"><i class="fas fa-plus"></i> Log production</button>
  </div>
</div>

<?php if($urlMsg): [$t,$tx]=explode(':',$urlMsg,2); ?>
<div class="alert alert-<?php echo $t; ?>" style="margin-bottom:14px;"><i class="fas fa-<?php echo $t==='error'?'exclamation-circle':'check-circle'; ?>"></i> <?php echo htmlspecialchars($tx); ?></div>
<?php endif; ?>

<div class="carry-banner">
  <i class="fas fa-egg"></i>
  <div><strong>Eggs in crate stock: <?php echo number_format($eggsOnHand); ?></strong>
  <span>Sales reduce this. Crates logged here are added to stock at once.</span></div>
</div>

<!-- Filters -->
<form class="egg-filters" method="GET">
  <div class="period-switch">
    <?php foreach(['1'=>'Today','7'=>'7 days','30'=>'30 days','90'=>'90 days'] as $v=>$lbl): ?>
      <a class="period-btn <?php echo $range===$v?'active':''; ?>" href="?range=<?php echo $v; ?>&flock_id=<?php echo $flockFilter; ?>"><?php echo $lbl; ?></a>
    <?php endforeach; ?>
  </div>
  <select name="flock_id" class="fld-ctrl" data-autosubmit>
    <option value="0">All laying flocks</option>
    <?php foreach($layerFlocks as $lf): ?>
      <option value="<?php echo $lf['id']; ?>" <?php echo $flockFilter==$lf['id']?'selected':''; ?>><?php echo htmlspecialchars($lf['flock_name']); ?></option>
    <?php endforeach; ?>
  </select>
  <input type="hidden" name="range" value="<?php echo htmlspecialchars($range); ?>">
</form>

<!-- KPIs -->
<section class="dash-health">
  <div class="dh-tile">
    <span class="dh-lbl">Eggs today</span>
    <strong><?php echo number_format($eggsToday); ?></strong>
    <small><?php echo $eggsVsYest === null ? 'no record yesterday' : (($eggsVsYest >= 0 ? '▲ +' : '▼ ').$eggsVsYest.'% vs yesterday'); ?></small>
  </div>
  <div class="dh-tile <?php echo hdepClass($hdepToday); ?>">
    <span class="dh-lbl">Hen-day production</span>
    <strong><?php echo $hdepToday !== null ? $hdepToday.'%' : '—'; ?></strong>
    <small>target <?php echo HDEP_TARGET; ?>% · <?php echo number_format($liveLayers); ?> live layers</small>
  </div>
  <div class="dh-tile <?php echo ($targetPct !== null && $targetPct < 90) ? 'warn' : (($targetPct !== null) ? 'good' : ''); ?>">
    <span class="dh-lbl">Against target today</span>
    <strong><?php echo $targetPct !== null ? $targetPct.'%' : '—'; ?></strong>
    <small><?php echo number_format($targetEggs); ?> eggs expected · <?php echo ($eggsToday-$targetEggs>=0?'+':'').number_format($eggsToday-$targetEggs); ?> variance</small>
  </div>
  <div class="dh-tile <?php echo ($breakRate7 !== null && $breakRate7 >= BREAK_ALERT) ? 'warn' : ''; ?>">
    <span class="dh-lbl">Breakage · 7 days</span>
    <strong><?php echo $breakRate7 !== null ? $breakRate7.'%' : '—'; ?></strong>
    <small><?php echo $breakPrev !== null ? 'last week '.$breakPrev.'%' : 'broken + rejected'; ?></small>
  </div>
  <div class="dh-tile">
    <span class="dh-lbl">Feed per 100 eggs · 7 days</span>
    <strong><?php echo $feedPer100 !== null ? $feedPer100.' kg' : '—'; ?></strong>
    <small><?php echo number_format($feed7,1); ?> kg fed to layers</small>
  </div>
</section>

<!-- Trend chart -->
<section class="card dash-card">
  <div class="dc-head">
    <h3>Production trend</h3>
    <div class="egg-chart-controls">
      <select id="eggMetric" class="fld-ctrl">
        <option value="eggs">Eggs collected</option>
        <option value="good">Good eggs</option>
        <option value="hdep">Hen-day production %</option>
        <option value="break">Breakage %</option>
      </select>
      <select id="eggFlock" class="fld-ctrl">
        <option value="0">All flocks</option>
        <?php foreach($layerFlocks as $lf): ?><option value="<?php echo $lf['id']; ?>"><?php echo htmlspecialchars($lf['flock_name']); ?></option><?php endforeach; ?>
      </select>
      <div class="period-switch" id="eggRange">
        <button type="button" class="period-btn" data-days="7">7d</button>
        <button type="button" class="period-btn active" data-days="30">30d</button>
        <button type="button" class="period-btn" data-days="90">90d</button>
      </div>
    </div>
  </div>
  <canvas id="eggTrend" height="90"></canvas>
</section>

<!-- Flocks + insights -->
<section class="dash-grid-2">
  <div class="card dash-card">
    <div class="dc-head"><h3>Flock performance · 7 days</h3></div>
    <?php if(empty($perf)): ?>
      <div class="dc-empty">No laying flocks yet.</div>
    <?php else: ?>
    <table class="dash-table">
      <thead><tr><th>Flock</th><th>Eggs</th><th>HDEP</th><th>Breakage</th><th>Feed/100 eggs</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach($perf as $p): ?>
        <tr id="flock-<?php echo $p['id']; ?>">
          <td><a href="flocks.php" class="flock-name"><?php echo htmlspecialchars($p['name']); ?></a><br><small><?php echo number_format($p['qty']); ?> birds</small></td>
          <td><strong><?php echo number_format($p['eggs7']); ?></strong></td>
          <td><span class="var <?php echo hdepClass($p['hdep']); ?>"><?php echo $p['hdep'] !== null ? $p['hdep'].'%' : '—'; ?></span></td>
          <td><?php echo $p['break'] !== null ? $p['break'].'%' : '—'; ?></td>
          <td><?php echo $p['fpe'] !== null ? $p['fpe'].' kg' : '—'; ?></td>
          <td><span class="status-dot <?php echo ['good'=>'green','watch'=>'amber','low'=>'red','none'=>'grey'][$p['status']]; ?>"></span><?php echo ['good'=>'On target','watch'=>'Watch','low'=>'Investigate','none'=>'No data'][$p['status']]; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="card dash-card">
    <div class="dc-head"><h3>Farm insights</h3><span class="dc-pill <?php echo empty($insights)?'green':'red'; ?>"><?php echo empty($insights)?'Nothing flagged':count($insights).' to review'; ?></span></div>
    <?php if(empty($insights)): ?>
      <div class="dc-empty ok"><i class="fas fa-check-circle"></i> Production is in line with your targets.</div>
    <?php else: ?>
      <ul class="alert-list">
      <?php foreach($insights as $in): ?>
        <li class="alert-row <?php echo $in[0]==='red'?'red':($in[0]==='amber'?'amber':'green'); ?>"><a href="<?php echo htmlspecialchars($in[2]); ?>">
          <span class="alert-dot"></span><span class="alert-text"><?php echo htmlspecialchars($in[1]); ?></span><i class="fas fa-chevron-right"></i>
        </a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- Grades -->
<section class="card dash-card" id="breakage">
  <div class="dc-head"><h3>Egg sizes · selected period</h3><span class="dc-hint" style="margin:0;">Eggs by size, from the crates logged</span></div>
  <?php if($gradeAll === 0): ?>
    <div class="dc-empty">No sizes recorded in this period. Add crates when you log production.</div>
  <?php else: foreach(['large'=>'Large','medium'=>'Medium','small'=>'Small'] as $k=>$lbl):
    $pct = $gradeAll > 0 ? round($gradeTotals[$k] / $gradeAll * 100, 1) : 0; ?>
    <div class="grade-row">
      <span class="grade-lbl"><?php echo $lbl; ?></span>
      <div class="grade-bar"><div style="width:<?php echo $pct; ?>%;"></div></div>
      <span class="grade-num"><?php echo number_format($gradeTotals[$k]); ?> · <?php echo $pct; ?>%</span>
    </div>
  <?php endforeach; endif; ?>
</section>

<!-- Records -->
<section class="card dash-card">
  <div class="dc-head"><h3>Production records</h3><span class="dc-hint" style="margin:0;"><?php echo count($records); ?> record(s) · click a row to edit</span></div>
  <div style="overflow-x:auto;">
  <table class="dash-table">
    <thead><tr><th>Date</th><th>Flock</th><th>Hens</th><th>Crates</th><th>Eggs</th><th>HDEP</th><th>Breakage</th><th>Sizes L / M / S</th><th>Notes</th></tr></thead>
    <tbody>
    <?php if(empty($records)): ?>
      <tr><td colspan="9" class="dc-empty">No production recorded in this period.</td></tr>
    <?php else: foreach($records as $r):
      $hd = (int)$r['hens'] > 0 ? round((int)$r['quantity'] / (int)$r['hens'] * 100, 1) : null;
      $bk = (int)$r['quantity'] > 0 ? round(((int)$r['broken_eggs'] + (int)$r['rejected_eggs']) / (int)$r['quantity'] * 100, 2) : null;
    ?>
      <tr class="rec-row" data-edit="<?php echo (int)$r['id']; ?>">
        <td><?php echo date('M d', strtotime($r['production_date'])); ?></td>
        <td><strong><?php echo htmlspecialchars($r['flock_name']); ?></strong></td>
        <td><?php echo number_format($r['hens']); ?></td>
        <td><?php echo htmlspecialchars(crateText($recLines[(int)$r['id']] ?? [])); ?></td>
        <td><strong><?php echo number_format($r['quantity']); ?></strong></td>
        <td><span class="var <?php echo hdepClass($hd); ?>"><?php echo $hd !== null ? $hd.'%' : '—'; ?></span></td>
        <td><?php echo $bk !== null ? $bk.'%' : '—'; ?></td>
        <td><?php echo (int)$r['grade_large'].' / '.(int)$r['grade_medium'].' / '.(int)$r['grade_small']; ?></td>
        <td class="dc-hint" style="margin:0;"><?php echo htmlspecialchars(mb_substr((string)$r['notes'], 0, 40)); ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</section>

</div>
</div>

<!-- Log / edit production -->
<div class="modal-overlay" id="eggModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h3 id="eggTitle"><i class="fas fa-egg" style="color:#f59e0b;margin-right:8px;"></i>Log egg production</h3>
      <button class="modal-close" type="button" data-close-egg="1"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="eggForm">
      <input type="hidden" name="save_production" value="1">
      <input type="hidden" name="record_id" id="eRid" value="0">
      <div style="padding:16px 24px 0;">
        <div class="form-row">
          <div class="form-group"><label>Flock *</label>
            <select name="flock_id" id="eFlock" class="form-control" required>
              <option value="">Choose a flock…</option>
              <?php foreach($layerFlocks as $lf): ?>
                <option value="<?php echo $lf['id']; ?>" data-qty="<?php echo (int)$lf['quantity']; ?>" data-arrival="<?php echo $lf['arrival_date']; ?>"><?php echo htmlspecialchars($lf['flock_name']); ?> — <?php echo number_format($lf['quantity']); ?> birds</option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-group"><label>Date *</label>
            <input type="date" name="production_date" id="eDate" class="form-control" required value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
        </div>

        <div class="form-group"><label>Hens present *</label>
          <input type="number" name="hens_present" id="eHens" class="form-control" min="1" required>
          <div class="form-hint">Birds laying that day (defaults to the flock size)</div></div>

        <div class="form-group"><label>Crates collected — choose the type and size of each crate</label>
          <div id="crateLines"></div>
          <button type="button" class="btn btn-secondary btn-sm" id="addLineBtn"><i class="fas fa-plus"></i> Add another crate type</button>
          <div class="form-hint" id="ePack"></div>
        </div>

        <div class="form-row">
          <div class="form-group"><label>Broken</label><input type="number" name="broken_eggs" id="eBroken" class="form-control" min="0" value="0"></div>
          <div class="form-group"><label>Rejected (dirty, cracked)</label><input type="number" name="rejected_eggs" id="eRej" class="form-control" min="0" value="0"></div>
        </div>

        <div class="egg-live" id="eLive">
          <div><span>Total eggs</span><strong id="lTotal">0</strong></div>
          <div><span>HDEP</span><strong id="lHdep">—</strong></div>
          <div><span>Breakage</span><strong id="lBreak">—</strong></div>
        </div>

        <div class="form-group"><label>Notes</label>
          <textarea name="notes" id="eNotes" class="form-control" rows="2" placeholder="Anything unusual about today's collection"></textarea></div>
        <div class="lot-flag lot-expired" id="eWarn" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-egg="1">Cancel</button>
        <button type="submit" class="btn btn-primary" id="eSubmit"><i class="fas fa-save"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<div id="pageData" hidden data-page="<?php echo htmlspecialchars(json_encode($pageData), ENT_QUOTES); ?>"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
