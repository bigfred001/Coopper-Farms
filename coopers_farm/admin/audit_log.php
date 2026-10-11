<?php
require_once '../config/config.php';
requireAdmin();

// Ensure table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT, user_name VARCHAR(100),
    action VARCHAR(100) NOT NULL, entity_type VARCHAR(50) NOT NULL,
    entity_id INT, description TEXT, ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$search      = $_GET['search']      ?? '';
$entityFilter= $_GET['entity']      ?? '';
$userFilter  = $_GET['user']        ?? '';
$dateFrom    = $_GET['date_from']   ?? date('Y-m-01');
$dateTo      = $_GET['date_to']     ?? date('Y-m-d');
$page        = max(1,(int)($_GET['page']??1));
$perPage     = 30;
$offset      = ($page-1)*$perPage;

$where  = "WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'";
$params = [];
if ($search)       { $where .= " AND (description LIKE ? OR user_name LIKE ? OR action LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($entityFilter) { $where .= " AND entity_type=?"; $params[] = $entityFilter; }
if ($userFilter)   { $where .= " AND user_name=?";   $params[] = $userFilter; }

$total = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $where");
$total->execute($params);
$totalRows  = $total->fetchColumn();
$totalPages = ceil($totalRows/$perPage);

$stmt = $pdo->prepare("SELECT * FROM audit_logs $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$entityTypes = $pdo->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
$users       = $pdo->query("SELECT DISTINCT user_name FROM audit_logs WHERE user_name IS NOT NULL ORDER BY user_name")->fetchAll(PDO::FETCH_COLUMN);

$actionIcons = [
    'add_flock'           => ['fas fa-kiwi-bird',    '#4A7C59'],
    'update_flock_status' => ['fas fa-edit',          '#E87722'],
    'add_vaccination'     => ['fas fa-syringe',       '#3b82f6'],
    'complete_vaccination'=> ['fas fa-check-circle',  '#4ade80'],
    'reschedule_vaccination'=>['fas fa-calendar-alt', '#E87722'],
    'sync_overdue'        => ['fas fa-sync',          '#9ca3af'],
    'add_health_record'   => ['fas fa-heartbeat',     '#ef4444'],
    'update_health_record'=> ['fas fa-edit',          '#E87722'],
    'log_mortality'       => ['fas fa-skull',         '#991b1b'],
    'log_egg_production'  => ['fas fa-egg',           '#fbbf24'],
    'walkin_order'        => ['fas fa-store',         '#4A7C59'],
    'update_order_status' => ['fas fa-shopping-cart', '#3b82f6'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Audit Log — Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css?v=8">
  
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">

    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
      <div><h1>Audit Log</h1><p>Full record of who did what and when across the system</p></div>
      <a href="?export=csv&search=<?php echo urlencode($search); ?>&entity=<?php echo $entityFilter; ?>&user=<?php echo urlencode($userFilter); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="btn btn-secondary">
        <i class="fas fa-download"></i> Export CSV
      </a>
    </div>

    <?php
    // CSV export
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','User','Action','Entity Type','Entity ID','Description','IP','Date Time']);
        $allStmt = $pdo->prepare("SELECT * FROM audit_logs $where ORDER BY created_at DESC");
        $allStmt->execute($params);
        foreach ($allStmt->fetchAll() as $row) {
            fputcsv($out, [$row['id'],$row['user_name'],$row['action'],$row['entity_type'],$row['entity_id'],$row['description'],$row['ip_address'],date('Y-m-d H:i:s',strtotime($row['created_at']))]);
        }
        fclose($out);
        exit;
    }
    ?>

    <!-- Filters -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-body" style="padding:14px 20px;">
        <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <input type="text" name="search" class="form-control" placeholder="Search actions, users, descriptions..." value="<?php echo htmlspecialchars($search); ?>" style="width:260px;">
          <select name="entity" class="form-control" style="width:150px;">
            <option value="">All Types</option>
            <?php foreach($entityTypes as $et): ?>
            <option value="<?php echo $et; ?>" <?php echo $entityFilter===$et?'selected':''; ?>><?php echo ucfirst($et); ?></option>
            <?php endforeach; ?>
          </select>
          <select name="user" class="form-control" style="width:160px;">
            <option value="">All Users</option>
            <?php foreach($users as $u): ?>
            <option value="<?php echo htmlspecialchars($u); ?>" <?php echo $userFilter===$u?'selected':''; ?>><?php echo htmlspecialchars($u); ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>" style="width:140px;">
          <input type="date" name="date_to"   class="form-control" value="<?php echo $dateTo; ?>"   style="width:140px;">
          <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
          <a href="audit_log.php" class="btn btn-secondary">Clear</a>
        </form>
      </div>
    </div>

    <!-- Log entries -->
    <div class="card">
      <div class="card-header">
        <h3>Activity Log (<?php echo number_format($totalRows); ?> entries)</h3>
      </div>
      <div>
        <?php if(empty($logs)): ?>
        <div style="text-align:center;padding:48px;color:#888;">
          <i class="fas fa-clipboard-list" style="font-size:36px;color:#e5e7eb;margin-bottom:12px;display:block;"></i>
          <p>No audit logs yet. Actions on the system will appear here automatically.</p>
        </div>
        <?php else: ?>
        <?php foreach($logs as $log):
          [$icon, $color] = $actionIcons[$log['action']] ?? ['fas fa-circle', '#9ca3af'];
          $actionLabel = ucwords(str_replace('_',' ',$log['action']));
        ?>
        <div class="audit-row">
          <div class="audit-icon" style="background:<?php echo $color; ?>;">
            <i class="<?php echo $icon; ?>"></i>
          </div>
          <div class="audit-body">
            <div class="audit-action">
              <?php echo $actionLabel; ?>
              <span class="entity-badge"><?php echo $log['entity_type']; ?></span>
              <?php if($log['entity_id']): ?><span style="font-size:11px;color:#aaa;">ID #<?php echo $log['entity_id']; ?></span><?php endif; ?>
            </div>
            <?php if($log['description']): ?>
            <div class="audit-desc"><?php echo htmlspecialchars($log['description']); ?></div>
            <?php endif; ?>
            <div class="audit-meta">
              <span class="audit-user"><i class="fas fa-user"></i> <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></span>
              <span><i class="fas fa-clock"></i> <?php echo date('M d, Y \a\t g:i A', strtotime($log['created_at'])); ?></span>
              <?php if($log['ip_address']): ?><span><i class="fas fa-globe"></i> <?php echo $log['ip_address']; ?></span><?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php if($totalPages > 1): ?>
      <div class="pagination">
        <?php for($i=1;$i<=$totalPages;$i++): ?>
        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&entity=<?php echo $entityFilter; ?>&user=<?php echo urlencode($userFilter); ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>"
           class="page-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="../public/js/admin.js?v=8"></script>
</body>
</html>
