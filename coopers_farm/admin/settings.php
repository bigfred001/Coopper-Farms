<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_test'])) {
        $testEmail = sanitize($_POST['test_email']);
        $testName  = sanitize($_POST['test_name'] ?: 'Admin');
        if ($testEmail) {
            $ok = $mailer->sendTestEmail($testEmail, $testName);
            if ($ok) {
                $msg = "✅ Test email sent! Open your <strong><a href='https://mailtrap.io' target='_blank'>Mailtrap inbox</a></strong> to see it.";
            } else {
                $lastErr = $_SESSION['email_last_error'] ?? 'Unknown error';
                $msg = "❌ Send failed: <strong>{$lastErr}</strong> — Check your MAILTRAP_USERNAME and MAILTRAP_PASSWORD in config.php";
                $msgType = 'error';
            }
        }
    }

    if (isset($_POST['send_vacc_alert'])) {
        $upcoming = $pdo->query("SELECT v.*, f.flock_name FROM vaccinations v JOIN flocks f ON v.flock_id=f.id WHERE v.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND v.status IN ('scheduled','overdue') ORDER BY v.next_due_date")->fetchAll();
        if (empty($upcoming)) { $msg = 'No upcoming vaccinations found.'; $msgType = 'warning'; }
        else {
            $ok = $mailer->sendAdminVaccinationAlert($upcoming);
            $msg = $ok ? "Vaccination alert sent! Check Mailtrap inbox." : "Send failed: " . ($_SESSION['email_last_error'] ?? '');
            $msgType = $ok ? 'success' : 'error';
        }
    }

    if (isset($_POST['send_feed_alert'])) {
        $lowFeeds = $pdo->query("SELECT * FROM feed_stock WHERE quantity_kg < reorder_level")->fetchAll();
        if (empty($lowFeeds)) { $msg = 'All feed stocks are above reorder level.'; $msgType = 'warning'; }
        else {
            $ok = $mailer->sendAdminLowFeedAlert($lowFeeds);
            $msg = $ok ? "Low feed alert sent! Check Mailtrap inbox." : "Send failed: " . ($_SESSION['email_last_error'] ?? '');
            $msgType = $ok ? 'success' : 'error';
        }
    }
}

$emailEnabled    = $mailer->isEnabled();
$mtUser          = defined('MAILTRAP_USERNAME') ? MAILTRAP_USERNAME : '';
$mtPass          = defined('MAILTRAP_PASSWORD') ? MAILTRAP_PASSWORD : '';
$userSet         = !empty($mtUser) && $mtUser !== 'YOUR_MAILTRAP_USERNAME';
$passSet         = !empty($mtPass) && $mtPass !== 'YOUR_MAILTRAP_PASSWORD';

$upcomingVaccCnt = $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status IN ('scheduled','overdue')")->fetchColumn();
$lowFeedCnt      = $pdo->query("SELECT COUNT(*) FROM feed_stock WHERE quantity_kg < reorder_level")->fetchColumn();
$pendingOrderCnt = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Settings — Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css">
  <style>
    .settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
    .setting-section{background:var(--white);border-radius:var(--radius);border:1px solid var(--border);overflow:hidden}
    .setting-head{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
    .setting-head h3{font-size:16px;font-weight:600;margin:0}
    .setting-body{padding:24px}
    .check-row{display:flex;align-items:flex-start;gap:12px;padding:14px 0;border-bottom:1px solid var(--border)}
    .check-row:last-child{border-bottom:none;padding-bottom:0}
    .check-icon{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;margin-top:2px}
    .check-ok{background:#d1fae5;color:#065f46}
    .check-fail{background:#fee2e2;color:#991b1b}
    .check-label{font-size:14px;font-weight:700;margin-bottom:3px}
    .check-desc{font-size:12px;color:#888;line-height:1.6}
    .check-desc a{color:#4A7C59;font-weight:600}
    .check-desc code{background:#f3f4f6;padding:1px 6px;border-radius:4px;font-size:11px}
    .config-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border);font-size:14px}
    .config-row:last-child{border-bottom:none}
    .config-key{color:var(--text-mid);font-weight:500}
    .config-val{font-family:monospace;font-size:12px;background:var(--cream);padding:3px 10px;border-radius:6px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .pill{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:50px;font-size:11px;font-weight:700}
    .pill-green{background:#d1fae5;color:#065f46}
    .pill-red{background:#fee2e2;color:#991b1b}
    .pill-yellow{background:#fef3c7;color:#92400e}
    .trigger-box{background:var(--cream);border-radius:12px;padding:16px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:16px}
    .trigger-box:last-child{margin-bottom:0}
    .status-banner{border-radius:12px;padding:18px 24px;margin-bottom:24px;display:flex;align-items:center;gap:16px;border:1px solid}
    .banner-ok{background:#EDF5E8;border-color:#86efac}
    .banner-fail{background:#fff5f5;border-color:#fca5a5}
    .banner-icon{font-size:32px}
    .banner-title{font-weight:700;font-size:16px}
    .banner-ok .banner-title{color:#065f46}
    .banner-fail .banner-title{color:#991b1b}
    .banner-sub{font-size:13px;margin-top:3px}
    .banner-ok .banner-sub{color:#166534}
    .banner-fail .banner-sub{color:#7f1d1d}
    .mailtrap-badge{display:inline-flex;align-items:center;gap:6px;background:#1a1a2e;color:#fff;padding:6px 14px;border-radius:50px;font-size:13px;font-weight:700;text-decoration:none}
    .step-num{width:28px;height:28px;border-radius:50%;background:#4A7C59;color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}
    @media(max-width:960px){.settings-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">

    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
      <div>
        <h1>Settings &amp; Email</h1>
        <p>Mailtrap testing inbox — all emails caught safely during development</p>
      </div>
      <a href="https://mailtrap.io" target="_blank" class="mailtrap-badge">
        <i class="fas fa-inbox"></i> Open Mailtrap Inbox
      </a>
    </div>

    <?php if($msg): ?>
    <div class="alert alert-<?php echo $msgType==='error'?'error':($msgType==='warning'?'warning':'success'); ?>" style="margin-bottom:20px;">
      <?php echo $msg; ?>
    </div>
    <?php endif; ?>

    <!-- STATUS BANNER -->
    <div class="status-banner <?php echo $emailEnabled?'banner-ok':'banner-fail'; ?>">
      <div class="banner-icon"><?php echo $emailEnabled?'✅':'⚠️'; ?></div>
      <div>
        <div class="banner-title">
          <?php echo $emailEnabled ? 'Email System Active — Mailtrap Connected' : 'Email Disabled — Add Mailtrap Credentials'; ?>
        </div>
        <div class="banner-sub">
          <?php if($emailEnabled): ?>
            All emails go to your <strong>Mailtrap inbox</strong> safely — no real emails sent during development. 300 test emails/month free.
          <?php else: ?>
            Open <strong>config/config.php</strong> and set <code>MAILTRAP_USERNAME</code> and <code>MAILTRAP_PASSWORD</code> from your Mailtrap inbox.
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="settings-grid">

      <!-- LEFT -->
      <div style="display:flex;flex-direction:column;gap:24px;">

        <!-- CHECKLIST -->
        <div class="setting-section">
          <div class="setting-head">
            <i class="fas fa-clipboard-check" style="color:#4A7C59;font-size:18px;"></i>
            <h3>Configuration Checklist</h3>
          </div>
          <div class="setting-body">

            <div class="check-row">
              <div class="check-icon <?php echo $userSet?'check-ok':'check-fail'; ?>">
                <i class="fas fa-<?php echo $userSet?'check':'times'; ?>"></i>
              </div>
              <div>
                <div class="check-label">Mailtrap Username is set</div>
                <?php if($userSet): ?>
                  <div class="check-desc">✅ Username found: <code><?php echo htmlspecialchars(substr($mtUser,0,8)).'••••••••'; ?></code></div>
                <?php else: ?>
                  <div class="check-desc">
                    ❌ Open <code>config/config.php</code> and set <code>MAILTRAP_USERNAME</code><br>
                    Get it from: <a href="https://mailtrap.io" target="_blank">mailtrap.io → Inboxes → Show Credentials</a>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="check-row">
              <div class="check-icon <?php echo $passSet?'check-ok':'check-fail'; ?>">
                <i class="fas fa-<?php echo $passSet?'check':'times'; ?>"></i>
              </div>
              <div>
                <div class="check-label">Mailtrap Password is set</div>
                <?php if($passSet): ?>
                  <div class="check-desc">✅ Password is set</div>
                <?php else: ?>
                  <div class="check-desc">❌ Set <code>MAILTRAP_PASSWORD</code> in <code>config/config.php</code></div>
                <?php endif; ?>
              </div>
            </div>

            <div class="check-row">
              <div class="check-icon check-ok"><i class="fas fa-check"></i></div>
              <div>
                <div class="check-label">SMTP Host &amp; Port</div>
                <div class="check-desc">✅ <code>sandbox.smtp.mailtrap.io:2525</code> — configured automatically</div>
              </div>
            </div>

            <div class="check-row">
              <div class="check-icon <?php echo function_exists('fsockopen')?'check-ok':'check-fail'; ?>">
                <i class="fas fa-<?php echo function_exists('fsockopen')?'check':'times'; ?>"></i>
              </div>
              <div>
                <div class="check-label">PHP fsockopen available</div>
                <?php if(function_exists('fsockopen')): ?>
                  <div class="check-desc">✅ SMTP connections can be made</div>
                <?php else: ?>
                  <div class="check-desc">❌ fsockopen is disabled. Enable it in <code>php.ini</code></div>
                <?php endif; ?>
              </div>
            </div>

            <div class="check-row">
              <div class="check-icon check-ok"><i class="fas fa-check"></i></div>
              <div>
                <div class="check-label">PHP <?php echo phpversion(); ?></div>
                <div class="check-desc">✅ Compatible version running on WAMP</div>
              </div>
            </div>

          </div>
        </div>

        <!-- SETUP GUIDE -->
        <div class="setting-section">
          <div class="setting-head">
            <i class="fas fa-book" style="color:#4A7C59;"></i>
            <h3>Mailtrap Setup Guide</h3>
          </div>
          <div class="setting-body">
            <div style="display:flex;flex-direction:column;gap:16px;">
              <?php
              $steps = [
                ['Sign up free at mailtrap.io', 'Visit <a href="https://mailtrap.io" target="_blank" style="color:#4A7C59;font-weight:600;">mailtrap.io</a> and create a free account. No credit card needed.'],
                ['Open your inbox', 'Go to <strong>Email Testing → Inboxes → My Inbox</strong>'],
                ['Get your credentials', 'Click <strong>"Show Credentials"</strong> — you\'ll see Username and Password under the SMTP tab'],
                ['Paste into config.php', 'Open <code>config/config.php</code>, set <code>MAILTRAP_USERNAME</code> and <code>MAILTRAP_PASSWORD</code>'],
                ['Send a test email', 'Come back here and click <strong>Send Test Email</strong> — it appears in your Mailtrap inbox instantly'],
              ];
              foreach($steps as $i => [$title, $desc]):
              ?>
              <div style="display:flex;gap:14px;align-items:flex-start;">
                <div class="step-num"><?php echo $i+1; ?></div>
                <div>
                  <div style="font-weight:700;font-size:14px;margin-bottom:3px;"><?php echo $title; ?></div>
                  <div style="font-size:13px;color:#888;line-height:1.5;"><?php echo $desc; ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- FARM STATUS -->
        <div class="setting-section">
          <div class="setting-head">
            <i class="fas fa-bell" style="color:#E87722;"></i>
            <h3>Current Farm Status</h3>
          </div>
          <div class="setting-body" style="padding:0 24px;">
            <div class="config-row">
              <span class="config-key">Pending Orders</span>
              <span class="pill <?php echo $pendingOrderCnt>0?'pill-yellow':'pill-green'; ?>"><?php echo $pendingOrderCnt; ?> pending</span>
            </div>
            <div class="config-row">
              <span class="config-key">Vaccinations Due (7 days)</span>
              <span class="pill <?php echo $upcomingVaccCnt>0?'pill-yellow':'pill-green'; ?>"><?php echo $upcomingVaccCnt; ?> due</span>
            </div>
            <div class="config-row">
              <span class="config-key">Low Feed Stocks</span>
              <span class="pill <?php echo $lowFeedCnt>0?'pill-red':'pill-green'; ?>"><?php echo $lowFeedCnt; ?> below reorder</span>
            </div>
          </div>
        </div>

      </div><!-- /left -->

      <!-- RIGHT -->
      <div style="display:flex;flex-direction:column;gap:24px;">

        <!-- CONFIG VALUES -->
        <div class="setting-section">
          <div class="setting-head">
            <i class="fas fa-cog" style="color:#4A7C59;"></i>
            <h3>Current config.php Values</h3>
          </div>
          <div class="setting-body" style="padding:0 24px;">
            <div class="config-row">
              <span class="config-key">MAILTRAP_HOST</span>
              <span class="config-val">sandbox.smtp.mailtrap.io</span>
            </div>
            <div class="config-row">
              <span class="config-key">MAILTRAP_PORT</span>
              <span class="config-val">2525</span>
            </div>
            <div class="config-row">
              <span class="config-key">MAILTRAP_USERNAME</span>
              <span class="config-val"><?php echo $userSet ? htmlspecialchars(substr($mtUser,0,8)).'••••' : '❌ Not set'; ?></span>
            </div>
            <div class="config-row">
              <span class="config-key">MAILTRAP_PASSWORD</span>
              <span class="config-val"><?php echo $passSet ? '✅ Set' : '❌ Not set'; ?></span>
            </div>
            <div class="config-row">
              <span class="config-key">EMAIL_FROM</span>
              <span class="config-val"><?php echo EMAIL_FROM; ?></span>
            </div>
            <div class="config-row">
              <span class="config-key">ADMIN_EMAIL</span>
              <span class="config-val"><?php echo ADMIN_EMAIL; ?></span>
            </div>
            <div class="config-row">
              <span class="config-key">Status</span>
              <span class="pill <?php echo $emailEnabled?'pill-green':'pill-red'; ?>">
                <i class="fas fa-<?php echo $emailEnabled?'check':'times'; ?>-circle"></i>
                <?php echo $emailEnabled?'Active':'Disabled'; ?>
              </span>
            </div>
          </div>
        </div>

        <!-- TEST EMAIL -->
        <div class="setting-section">
          <div class="setting-head">
            <i class="fas fa-paper-plane" style="color:#4A7C59;"></i>
            <h3>Send Test Email</h3>
          </div>
          <div class="setting-body">
            <?php if(!$emailEnabled): ?>
            <div class="alert alert-warning" style="margin-bottom:16px;font-size:13px;">
              <i class="fas fa-exclamation-triangle"></i> Set your Mailtrap credentials in config.php first.
            </div>
            <?php endif; ?>
            <p style="font-size:14px;color:#888;margin-bottom:18px;">
              The email will appear in your <strong>Mailtrap inbox</strong> — not in a real inbox. Safe to test anytime.
            </p>
            <form method="POST">
              <div class="form-group">
                <label>Your Name</label>
                <input type="text" name="test_name" class="form-control" value="<?php echo htmlspecialchars($_SESSION['name']??'Farm Manager'); ?>">
              </div>
              <div class="form-group">
                <label>Send To (any email — goes to Mailtrap)</label>
                <input type="email" name="test_email" class="form-control" required value="<?php echo htmlspecialchars($_SESSION['email']??ADMIN_EMAIL); ?>">
              </div>
              <button type="submit" name="send_test" value="1" class="btn btn-primary" <?php echo !$emailEnabled?'disabled':''; ?>>
                <i class="fas fa-paper-plane"></i> Send Test Email
              </button>
            </form>
          </div>
        </div>

        <!-- MANUAL ALERTS -->
        <div class="setting-section">
          <div class="setting-head">
            <i class="fas fa-bolt" style="color:#E87722;"></i>
            <h3>Manual Alert Triggers</h3>
          </div>
          <div class="setting-body">
            <form method="POST" style="margin-bottom:12px;">
              <div class="trigger-box">
                <div>
                  <div style="font-weight:700;font-size:14px;"><i class="fas fa-syringe" style="color:#4A7C59;margin-right:6px;"></i>Vaccination Alert</div>
                  <div style="font-size:12px;color:#888;"><?php echo $upcomingVaccCnt; ?> due in next 7 days</div>
                </div>
                <button type="submit" name="send_vacc_alert" value="1" class="btn btn-sm btn-secondary" <?php echo !$emailEnabled?'disabled':''; ?>>
                  <i class="fas fa-paper-plane"></i> Send
                </button>
              </div>
            </form>
            <form method="POST">
              <div class="trigger-box">
                <div>
                  <div style="font-weight:700;font-size:14px;"><i class="fas fa-seedling" style="color:#E87722;margin-right:6px;"></i>Low Feed Alert</div>
                  <div style="font-size:12px;color:#888;"><?php echo $lowFeedCnt; ?> below reorder level</div>
                </div>
                <button type="submit" name="send_feed_alert" value="1" class="btn btn-sm btn-secondary" <?php echo !$emailEnabled?'disabled':''; ?>>
                  <i class="fas fa-paper-plane"></i> Send
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- EMAIL TRIGGERS -->
        <div class="setting-section">
          <div class="setting-head">
            <i class="fas fa-envelope" style="color:#4A7C59;"></i>
            <h3>All Email Triggers</h3>
          </div>
          <div class="setting-body" style="padding:0 24px;">
            <?php
            $triggers = [
              ['Customer Signs Up',    'Welcome → customer + alert → admin', 'auto',   'users'],
              ['Order Placed',         'Confirmation → customer + alert → admin', 'auto', 'shopping-cart'],
              ['Order Status Changes', 'Update → customer', 'auto', 'exchange-alt'],
              ['High Mortality',       'Alert → admin (≥' . MORTALITY_ALERT_THRESHOLD . ' birds)', 'auto', 'skull'],
              ['Vaccination Due',      'Alert → admin (manual)', 'manual', 'syringe'],
              ['Low Feed Stock',       'Alert → admin (manual)', 'manual', 'seedling'],
            ];
            foreach($triggers as [$name, $desc, $type, $icon]):
            ?>
            <div class="config-row">
              <div style="flex:1;">
                <div style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:2px;">
                  <i class="fas fa-<?php echo $icon; ?>" style="color:#4A7C59;width:14px;"></i>
                  <?php echo $name; ?>
                  <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:50px;<?php echo $type==='auto'?'background:#EDF5E8;color:#4A7C59;':'background:#dbeafe;color:#1e40af;'; ?>">
                    <?php echo $type==='auto'?'⚡ Auto':'👆 Manual'; ?>
                  </span>
                </div>
                <div style="font-size:12px;color:#888;padding-left:22px;"><?php echo $desc; ?></div>
              </div>
              <span class="pill <?php echo $emailEnabled?'pill-green':'pill-red'; ?>" style="flex-shrink:0;margin-left:12px;">
                <?php echo $emailEnabled?'On':'Off'; ?>
              </span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div><!-- /right -->
    </div>
  </div>
</div>
<script src="../public/js/admin.js"></script>
</body>
</html>
