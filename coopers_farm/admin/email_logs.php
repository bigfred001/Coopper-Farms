<?php
require_once '../config/config.php';
requireAdmin();

// Run this SQL once to create the table if it doesn't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(150) NOT NULL,
    recipient_name VARCHAR(100),
    subject VARCHAR(255) NOT NULL,
    email_type ENUM('welcome','order_confirmation','order_status','admin_new_order','admin_new_customer','admin_vaccination','admin_feed','admin_mortality','test') NOT NULL DEFAULT 'test',
    status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    error_message TEXT,
    order_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Filters
$typeFilter   = $_GET['type']   ?? '';
$statusFilter = $_GET['status'] ?? '';
$search       = $_GET['search'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = "WHERE 1=1";
$params = [];
if ($typeFilter)   { $where .= " AND email_type=?";   $params[] = $typeFilter; }
if ($statusFilter) { $where .= " AND status=?";        $params[] = $statusFilter; }
if ($search)       { $where .= " AND (recipient_email LIKE ? OR subject LIKE ? OR recipient_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

$totalRows = $pdo->prepare("SELECT COUNT(*) FROM email_logs $where");
$totalRows->execute($params);
$totalRows = $totalRows->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$stmt = $pdo->prepare("SELECT * FROM email_logs $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Summary stats
$totalSent   = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status='sent'")->fetchColumn();
$totalFailed = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status='failed'")->fetchColumn();
$todaySent   = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status='sent' AND DATE(created_at)=CURDATE()")->fetchColumn();
$byType      = $pdo->query("SELECT email_type, COUNT(*) as cnt, SUM(status='sent') as sent, SUM(status='failed') as failed FROM email_logs GROUP BY email_type ORDER BY cnt DESC")->fetchAll();

$typeLabels = [
    'welcome'            => ['👤 Welcome',          'badge-active'],
    'order_confirmation' => ['🛒 Order Confirm',     'badge-confirmed'],
    'order_status'       => ['📦 Status Update',     'badge-scheduled'],
    'admin_new_order'    => ['🛒 New Order Alert',   'badge-pending'],
    'admin_new_customer' => ['👤 New Customer Alert','badge-pending'],
    'admin_vaccination'  => ['💉 Vaccination Alert', 'badge-at-risk'],
    'admin_feed'         => ['🌾 Feed Alert',        'badge-at-risk'],
    'admin_mortality'    => ['💀 Mortality Alert',   'badge-cancelled'],
    'test'               => ['✅ Test Email',         'badge-scheduled'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Email Logs — Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css">
  <style>
    .type-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:24px}
    .type-card{background:white;border-radius:var(--radius);border:1px solid var(--border);padding:16px 18px}
    .type-card-label{font-size:13px;color:var(--text-mid);margin-bottom:6px}
    .type-card-nums{display:flex;gap:12px;align-items:baseline}
    .type-card-total{font-size:22px;font-weight:700;font-family:'Playfair Display',serif}
    .type-card-sub{font-size:12px;color:#888}
    .badge-at-risk{background:#fef3c7;color:#92400e}
  </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">

    <div class="page-header">
      <h1>Email Logs</h1>
      <p>Full history of all emails triggered by the system</p>
    </div>

    <!-- Summary Stats -->
    <div class="stats-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Total Sent</span><div class="stat-icon-small green"><i class="fas fa-check"></i></div></div>
        <h2 style="color:#4ade80;"><?php echo number_format($totalSent); ?></h2>
        <p>All time</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Total Failed</span><div class="stat-icon-small red"><i class="fas fa-times"></i></div></div>
        <h2 style="color:<?php echo $totalFailed>0?'#ef4444':'inherit'; ?>"><?php echo number_format($totalFailed); ?></h2>
        <p><?php echo $totalFailed>0?'Check errors below':'No failures 🎉'; ?></p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Sent Today</span><div class="stat-icon-small blue"><i class="fas fa-calendar-day"></i></div></div>
        <h2><?php echo number_format($todaySent); ?></h2>
        <p>Emails today</p>
      </div>
    </div>

    <!-- By Type Breakdown -->
    <div class="card" style="margin-bottom:24px;">
      <div class="card-header"><h3>Emails by Type</h3></div>
      <div class="card-body">
        <div class="type-grid">
          <?php foreach($byType as $t):
            [$lbl, $badge] = $typeLabels[$t['email_type']] ?? [$t['email_type'], 'badge-pending'];
          ?>
          <div class="type-card">
            <div class="type-card-label"><?php echo $lbl; ?></div>
            <div class="type-card-nums">
              <div class="type-card-total"><?php echo $t['cnt']; ?></div>
              <div class="type-card-sub">
                <span style="color:#4ade80;">✓ <?php echo $t['sent']; ?></span>
                <?php if($t['failed']>0): ?> · <span style="color:#ef4444;">✗ <?php echo $t['failed']; ?></span><?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if(empty($byType)): ?>
          <div style="grid-column:1/-1;text-align:center;color:#888;padding:20px;">
            No emails logged yet. Send a test email from <a href="settings.php">Settings</a> to start.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-body" style="padding:14px 20px;">
        <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <input type="text" name="search" class="form-control" placeholder="Search email, subject..." value="<?php echo htmlspecialchars($search); ?>" style="width:240px;">
          <select name="type" class="form-control" style="width:200px;">
            <option value="">All Types</option>
            <?php foreach($typeLabels as $key => [$lbl, $_]): ?>
            <option value="<?php echo $key; ?>" <?php echo $typeFilter===$key?'selected':''; ?>><?php echo $lbl; ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status" class="form-control" style="width:140px;">
            <option value="">All Statuses</option>
            <option value="sent"   <?php echo $statusFilter==='sent'  ?'selected':''; ?>>✓ Sent</option>
            <option value="failed" <?php echo $statusFilter==='failed'?'selected':''; ?>>✗ Failed</option>
          </select>
          <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
          <a href="email_logs.php" class="btn btn-secondary">Clear</a>
        </form>
      </div>
    </div>

    <!-- Logs Table -->
    <div class="card">
      <div class="card-header">
        <h3>Email Log (<?php echo number_format($totalRows); ?> total)</h3>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Type</th>
              <th>Recipient</th>
              <th>Subject</th>
              <th>Status</th>
              <th>Error</th>
              <th>Date & Time</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($logs as $log):
              [$lbl, $badge] = $typeLabels[$log['email_type']] ?? [$log['email_type'], 'badge-pending'];
            ?>
            <tr>
              <td style="color:#888;font-size:12px;">#<?php echo $log['id']; ?></td>
              <td><span class="status-badge <?php echo $badge; ?>" style="white-space:nowrap;"><?php echo $lbl; ?></span></td>
              <td>
                <strong><?php echo htmlspecialchars($log['recipient_name'] ?? ''); ?></strong>
                <?php if($log['recipient_name']): ?><br><?php endif; ?>
                <span style="font-size:12px;color:#888;"><?php echo htmlspecialchars($log['recipient_email']); ?></span>
              </td>
              <td style="max-width:220px;font-size:13px;"><?php echo htmlspecialchars($log['subject']); ?></td>
              <td>
                <?php if($log['status'] === 'sent'): ?>
                  <span class="status-badge badge-active"><i class="fas fa-check"></i> Sent</span>
                <?php else: ?>
                  <span class="status-badge badge-cancelled"><i class="fas fa-times"></i> Failed</span>
                <?php endif; ?>
              </td>
              <td style="max-width:200px;font-size:12px;color:#ef4444;">
                <?php echo $log['error_message'] ? htmlspecialchars(substr($log['error_message'], 0, 80)) : '—'; ?>
              </td>
              <td style="font-size:12px;white-space:nowrap;">
                <?php echo date('M d, Y', strtotime($log['created_at'])); ?><br>
                <span style="color:#888;"><?php echo date('g:i:s A', strtotime($log['created_at'])); ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($logs)): ?>
            <tr><td colspan="7" style="text-align:center;color:#888;padding:40px;">
              <i class="fas fa-envelope" style="font-size:32px;margin-bottom:12px;display:block;color:#e5e7eb;"></i>
              No email logs yet. <?php if($search||$typeFilter||$statusFilter): ?>Try clearing filters.<?php else: ?>Send a test email from <a href="settings.php">Settings →</a><?php endif; ?>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if($totalPages > 1): ?>
      <div class="pagination">
        <?php for($i=1;$i<=$totalPages;$i++): ?>
        <a href="?page=<?php echo $i; ?>&type=<?php echo urlencode($typeFilter); ?>&status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($search); ?>"
           class="page-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
<script src="../public/js/admin.js"></script>
</body>
</html>
