<?php
require_once '../config/config.php';
requireAdmin();
require_once 'includes/day.php';
require_once 'includes/report_export.php';

$id = (int)($_GET['id'] ?? 0);
$r  = dayRows($pdo, "SELECT * FROM day_reports WHERE id=?", [$id]);
if (!$r) { header('Location: day.php?msg=' . urlencode('error:Report not found.')); exit; }
$r = $r[0];
$rep = json_decode($r['report_json'], true) ?: [];
$m = $rep['meta'] ?? [];
// Export: CSV, Excel or PDF. Each export is logged before the file is sent.
$format = $_GET['format'] ?? '';
if (in_array($format, ['csv', 'excel', 'pdf'], true)) {
    logReportExport($pdo, $id, $format);
    $meta = $rep['meta'] ?? [];
    $sections = reportSections($rep);
    $filename = 'day-report-' . ($meta['day_date'] ?? date('Y-m-d'));
    if ($format === 'csv')   exportCsv($meta, $sections, $filename);
    if ($format === 'excel') exportXlsx($meta, $sections, $filename);
    if ($format === 'pdf')   exportPdf($meta, $sections, $filename);
}

$isOpen  = dayIsOpen($pdo);
$exports = dayRows($pdo, "SELECT * FROM report_exports WHERE day_report_id=? ORDER BY id DESC LIMIT 20", [$id]);
$num = fn($v, $dp = 0) => number_format((float)$v, $dp);
$money = fn($v) => '₵' . number_format((float)$v, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Day Report — Coopers Admin</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../public/css/admin.css?v=8">
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
<?php include 'includes/topbar.php'; ?>
<div class="dashboard-content dash day-report">

<div class="dash-head">
  <div>
    <h1>Day report — <?php echo date('l, d M Y', strtotime($m['day_date'] ?? $r['generated_at'])); ?></h1>
    <?php if(isset($_GET['ended'])): ?><p><strong>The day for <?php echo htmlspecialchars($_GET['ended']); ?> has been ended.</strong></p><?php endif; ?>
    <p>Started <?php echo date('H:i', strtotime($m['started_at'] ?? $r['generated_at'])); ?> by <?php echo htmlspecialchars($m['started_by'] ?? '—'); ?> · ended <?php echo date('H:i', strtotime($r['generated_at'])); ?> by <?php echo htmlspecialchars($m['ended_by'] ?? '—'); ?></p>
  </div>
  <div class="dash-quick">
    <a class="dq-btn" href="day.php"><i class="fas fa-arrow-left"></i> Back</a>
    <a class="dq-btn dq-primary" href="day_report.php?id=<?php echo $id; ?>&format=excel"><i class="fas fa-file-excel"></i> Excel</a>
    <a class="dq-btn dq-primary" href="day_report.php?id=<?php echo $id; ?>&format=csv"><i class="fas fa-file-csv"></i> CSV</a>
    <a class="dq-btn dq-primary" href="day_report.php?id=<?php echo $id; ?>&format=pdf"><i class="fas fa-file-pdf"></i> PDF</a>
    <?php if($isOpen): ?>
    <button class="dq-btn" type="button" data-print><i class="fas fa-print"></i> Print</button>
    <?php endif; ?>
  </div>
</div>

<section class="card dash-card">
  <div class="dc-head"><h3>Eggs</h3></div>
  <table class="dash-table">
    <thead><tr><th>Flock</th><th>Records</th><th>Eggs</th><th>Broken</th><th>Rejected</th><th>HDEP</th></tr></thead>
    <tbody>
    <?php if(empty($rep['eggs'])): ?><tr><td colspan="6" class="dc-empty">No egg records today.</td></tr>
    <?php else: foreach($rep['eggs'] as $e): $hd = $e['hens'] > 0 ? round($e['eggs'] / $e['hens'] * 100, 1) : null; ?>
      <tr><td><strong><?php echo htmlspecialchars($e['flock_name']); ?></strong></td><td><?php echo $num($e['records']); ?></td>
          <td><?php echo $num($e['eggs']); ?></td><td><?php echo $num($e['broken']); ?></td><td><?php echo $num($e['rejected']); ?></td>
          <td><?php echo $hd !== null ? $hd.'%' : '—'; ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php if(!empty($rep['crates'])): ?>
  <div class="dc-hint" style="margin:14px 0 6px;">Crates added to stock</div>
  <table class="dash-table">
    <thead><tr><th>Product</th><th>Crates</th><th>Eggs</th></tr></thead>
    <tbody><?php foreach($rep['crates'] as $c): ?>
      <tr><td><?php echo htmlspecialchars($c['name']); ?></td><td><?php echo $num($c['packs']); ?></td><td><?php echo $num($c['eggs']); ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
</section>

<section class="card dash-card">
  <div class="dc-head"><h3>Sales</h3></div>
  <?php $st = $rep['sales_total'][0] ?? ['n'=>0,'amt'=>0]; ?>
  <div class="dh-tile" style="margin-bottom:12px;"><span class="dh-lbl">Delivered sales</span><strong><?php echo $money($st['amt']); ?></strong><small><?php echo $num($st['n']); ?> sale(s)</small></div>
  <table class="dash-table">
    <thead><tr><th>Payment</th><th>Sales</th><th>Amount</th></tr></thead>
    <tbody><?php if(empty($rep['sales_by_payment'])): ?><tr><td colspan="3" class="dc-empty">No sales today.</td></tr>
    <?php else: foreach($rep['sales_by_payment'] as $p): ?>
      <tr><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$p['payment_method']))); ?></td><td><?php echo $num($p['n']); ?></td><td><?php echo $money($p['amt']); ?></td></tr>
    <?php endforeach; endif; ?></tbody>
  </table>
  <div class="dc-hint" style="margin:14px 0 6px;">Orders by stage</div>
  <table class="dash-table">
    <thead><tr><th>Status</th><th>Orders</th></tr></thead>
    <tbody><?php if(empty($rep['orders'])): ?><tr><td colspan="2" class="dc-empty">No orders today.</td></tr>
    <?php else: foreach($rep['orders'] as $o): ?>
      <tr><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$o['order_status']))); ?></td><td><?php echo $num($o['n']); ?></td></tr>
    <?php endforeach; endif; ?></tbody>
  </table>
</section>

<section class="card dash-card">
  <div class="dc-head"><h3>Mortality and health</h3></div>
  <table class="dash-table">
    <thead><tr><th>Flock</th><th>Cause</th><th>Deaths</th></tr></thead>
    <tbody><?php if(empty($rep['mortality'])): ?><tr><td colspan="3" class="dc-empty">No deaths logged today.</td></tr>
    <?php else: foreach($rep['mortality'] as $d): ?>
      <tr><td><?php echo htmlspecialchars($d['flock_name']); ?></td><td><?php echo htmlspecialchars($d['cause'] ?: 'Unknown'); ?></td><td><?php echo $num($d['deaths']); ?></td></tr>
    <?php endforeach; endif; ?></tbody>
  </table>
  <div class="dc-hint" style="margin:14px 0 6px;">Health cases logged</div>
  <table class="dash-table">
    <thead><tr><th>Condition</th><th>Cases</th><th>Birds affected</th></tr></thead>
    <tbody><?php if(empty($rep['health'])): ?><tr><td colspan="3" class="dc-empty">No health cases logged today.</td></tr>
    <?php else: foreach($rep['health'] as $h): ?>
      <tr><td><?php echo htmlspecialchars($h['condition_name']); ?></td><td><?php echo $num($h['n']); ?></td><td><?php echo $num($h['birds']); ?></td></tr>
    <?php endforeach; endif; ?></tbody>
  </table>
</section>

<section class="card dash-card">
  <div class="dc-head"><h3>Vaccinations and feed</h3></div>
  <table class="dash-table">
    <thead><tr><th>Vaccine</th><th>Given</th><th>Birds</th></tr></thead>
    <tbody><?php if(empty($rep['vaccinations'])): ?><tr><td colspan="3" class="dc-empty">No vaccinations completed today.</td></tr>
    <?php else: foreach($rep['vaccinations'] as $v): ?>
      <tr><td><?php echo htmlspecialchars($v['vaccine_name']); ?></td><td><?php echo $num($v['n']); ?></td><td><?php echo $num($v['birds']); ?></td></tr>
    <?php endforeach; endif; ?></tbody>
  </table>
  <div class="dc-hint" style="margin:14px 0 6px;">Feed used</div>
  <table class="dash-table">
    <thead><tr><th>Feed</th><th>Kg</th><th>Cost</th></tr></thead>
    <tbody><?php if(empty($rep['feed'])): ?><tr><td colspan="3" class="dc-empty">No feed logged today.</td></tr>
    <?php else: foreach($rep['feed'] as $f): ?>
      <tr><td><?php echo htmlspecialchars($f['feed_type']); ?></td><td><?php echo $num($f['kg'],2); ?></td><td><?php echo $money($f['cost']); ?></td></tr>
    <?php endforeach; endif; ?></tbody>
  </table>
</section>

<section class="card dash-card">
  <div class="dc-head"><h3>Stock at close (system figures)</h3></div>
  <table class="dash-table">
    <thead><tr><th>Item</th><th>On hand</th></tr></thead>
    <tbody>
    <?php foreach($rep['stock']['eggs'] ?? [] as $e): ?>
      <tr><td><?php echo htmlspecialchars($e['name']); ?></td><td><?php echo $num($e['stock_quantity']); ?> crates (<?php echo $num($e['stock_quantity'] * $e['egg_units']); ?> eggs)</td></tr>
    <?php endforeach; foreach($rep['stock']['batches'] ?? [] as $b): ?>
      <tr><td>Batch — <?php echo htmlspecialchars($b['flock_name']); ?></td><td><?php echo $num($b['quantity']); ?> birds</td></tr>
    <?php endforeach; foreach($rep['stock']['feed'] ?? [] as $f): ?>
      <tr><td>Feed — <?php echo htmlspecialchars($f['feed_type'].($f['brand'] ? ' ('.$f['brand'].')' : '')); ?></td><td><?php echo $num($f['quantity_kg'],2); ?> kg</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="card dash-card">
  <div class="dc-head"><h3>Who logged what</h3></div>
  <table class="dash-table">
    <thead><tr><th>User</th><th>Entries</th></tr></thead>
    <tbody><?php if(empty($rep['activity'])): ?><tr><td colspan="2" class="dc-empty">No entries today.</td></tr>
    <?php else: foreach($rep['activity'] as $a): ?>
      <tr><td><?php echo htmlspecialchars($a['user_name']); ?></td><td><?php echo $num($a['n']); ?></td></tr>
    <?php endforeach; endif; ?></tbody>
  </table>
</section>

<section class="card dash-card">
  <div class="dc-head"><h3>Exports of this report</h3><span class="dc-hint" style="margin:0;"><?php echo count($exports); ?> export(s) logged</span></div>
  <table class="dash-table">
    <thead><tr><th>When</th><th>Format</th><th>By</th><th>From</th></tr></thead>
    <tbody>
    <?php if(empty($exports)): ?><tr><td colspan="4" class="dc-empty">Not exported yet.</td></tr>
    <?php else: foreach($exports as $x): ?>
      <tr><td><?php echo date('d M Y H:i', strtotime($x['exported_at'])); ?></td><td><?php echo strtoupper($x['format']); ?></td>
          <td><?php echo htmlspecialchars($x['exported_by_name'] ?? '—'); ?></td><td><?php echo htmlspecialchars($x['ip_address'] ?? '—'); ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</section>

</div>
</div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
