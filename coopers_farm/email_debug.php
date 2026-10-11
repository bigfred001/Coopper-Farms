<?php
/**
 * COOPERS FARM — Email Debug & Fix Tool
 * Visit: http://localhost/coopers_farm/email_debug.php
 * DELETE THIS FILE after fixing!
 */
// Safe session start - won't conflict if already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
error_reporting(E_ALL & ~E_NOTICE); // suppress session notice

$configPath = __DIR__ . '/config/config.php';
$msg = '';

// Handle key save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_key'])) {
    $newKey      = trim($_POST['new_key']);
    $newFrom     = trim($_POST['new_from'] ?? '');
    $newAdmin    = trim($_POST['new_admin'] ?? '');

    if (file_exists($configPath)) {
        $cfg = file_get_contents($configPath);

        // Replace BREVO_API_KEY line
        $cfg = preg_replace(
            "/define\s*\(\s*'BREVO_API_KEY'\s*,\s*'[^']*'\s*\)/",
            "define('BREVO_API_KEY', '" . addslashes($newKey) . "')",
            $cfg
        );

        if ($newFrom) {
            $cfg = preg_replace(
                "/define\s*\(\s*'EMAIL_FROM'\s*,\s*'[^']*'\s*\)/",
                "define('EMAIL_FROM', '" . addslashes($newFrom) . "')",
                $cfg
            );
        }

        if ($newAdmin) {
            $cfg = preg_replace(
                "/define\s*\(\s*'ADMIN_EMAIL'\s*,\s*'[^']*'\s*\)/",
                "define('ADMIN_EMAIL', '" . addslashes($newAdmin) . "')",
                $cfg
            );
        }

        file_put_contents($configPath, $cfg);
        $msg = 'saved';
    }
}

// Now load config fresh
$rawKey = '';
$emailFrom = '';
$adminEmail = '';
$apiValid = null;
$brevoAccount = '';
$curlError = '';

if (file_exists($configPath)) {
    // Read raw values with regex (don't execute PHP)
    $cfg = file_get_contents($configPath);
    preg_match("/define\s*\(\s*'BREVO_API_KEY'\s*,\s*'([^']*)'\s*\)/", $cfg, $m);
    $rawKey = isset($m[1]) ? trim($m[1]) : '';
    preg_match("/define\s*\(\s*'EMAIL_FROM'\s*,\s*'([^']*)'\s*\)/", $cfg, $m2);
    $emailFrom = $m2[1] ?? '';
    preg_match("/define\s*\(\s*'ADMIN_EMAIL'\s*,\s*'([^']*)'\s*\)/", $cfg, $m3);
    $adminEmail = $m3[1] ?? '';
}

// Live Brevo API test
if (!empty($rawKey) && $rawKey !== 'YOUR_BREVO_API_KEY_HERE' && strlen($rawKey) > 20) {
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.brevo.com/v3/account');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['accept: application/json', 'api-key: ' . $rawKey],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, // WAMP/XAMPP fix
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($code === 200) {
            $apiValid = true;
            $data = json_decode($resp, true);
            $brevoAccount = $data['email'] ?? '';
        } else {
            $apiValid = false;
            $brevoResponse = $resp;
        }
    }
}

$keySet     = !empty($rawKey) && $rawKey !== 'YOUR_BREVO_API_KEY_HERE';
$keyLong    = strlen($rawKey) > 20;
$keyOk      = $keySet && $keyLong && $apiValid === true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Email Debug & Fix — Coopers Farm</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#f0f4f0;padding:32px 16px;color:#1a1a1a}
.wrap{max-width:720px;margin:0 auto}
h1{font-size:24px;color:#2D5016;margin-bottom:6px}
.sub{color:#888;font-size:14px;margin-bottom:28px}
.card{background:#fff;border-radius:14px;border:1px solid #e5e7eb;overflow:hidden;margin-bottom:20px}
.card-head{padding:16px 22px;border-bottom:1px solid #e5e7eb;font-weight:700;font-size:15px;display:flex;align-items:center;gap:10px}
.card-body{padding:22px}
.row{display:flex;justify-content:space-between;align-items:center;padding:11px 0;border-bottom:1px solid #f3f4f6;font-size:14px}
.row:last-child{border-bottom:none}
.label{color:#555;font-weight:500}
.val{font-family:monospace;font-size:13px;background:#f9fafb;padding:3px 10px;border-radius:6px;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ok{color:#065f46;background:#d1fae5;padding:4px 12px;border-radius:50px;font-size:12px;font-weight:700}
.fail{color:#991b1b;background:#fee2e2;padding:4px 12px;border-radius:50px;font-size:12px;font-weight:700}
.warn{color:#92400e;background:#fef3c7;padding:4px 12px;border-radius:50px;font-size:12px;font-weight:700}
.alert{padding:14px 18px;border-radius:10px;font-size:14px;margin-bottom:20px}
.alert-ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.alert-fail{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.alert-warn{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}
label{display:block;font-size:13px;font-weight:600;color:#333;margin-bottom:7px}
input{width:100%;padding:12px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:monospace;outline:none;margin-bottom:16px}
input:focus{border-color:#4A7C59}
.btn{background:#4A7C59;color:#fff;border:none;padding:13px 28px;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;width:100%}
.btn:hover{background:#2D5016}
.status-banner{border-radius:12px;padding:18px 22px;margin-bottom:20px;border:1px solid;display:flex;align-items:center;gap:14px}
.banner-ok{background:#EDF5E8;border-color:#86efac}
.banner-fail{background:#fff5f5;border-color:#fca5a5}
.banner-title{font-weight:700;font-size:16px}
.banner-ok .banner-title{color:#065f46}
.banner-fail .banner-title{color:#991b1b}
.banner-sub{font-size:13px;margin-top:3px}
.banner-ok .banner-sub{color:#166534}
.banner-fail .banner-sub{color:#991b1b}
.delete-warn{background:#fff5f5;border:1px solid #fca5a5;border-radius:10px;padding:14px;font-size:13px;color:#991b1b;margin-top:20px}
code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <h1>🐔 Coopers Farm — Email Fix Tool</h1>
  <div class="sub">This tool diagnoses and fixes your email configuration directly. Delete it after use.</div>

  <?php if ($msg === 'saved'): ?>
  <div class="alert alert-ok">✅ config.php updated! Scroll down — running live API test now...</div>
  <?php endif; ?>

  <!-- STATUS BANNER -->
  <div class="status-banner <?php echo $keyOk ? 'banner-ok' : 'banner-fail'; ?>">
    <div style="font-size:32px"><?php echo $keyOk ? '✅' : '❌'; ?></div>
    <div>
      <div class="banner-title">
        <?php echo $keyOk ? 'Email System Ready — API Key Valid' : 'Email Not Working Yet'; ?>
      </div>
      <div class="banner-sub">
        <?php if ($keyOk): ?>
          Brevo account confirmed: <strong><?php echo htmlspecialchars($brevoAccount); ?></strong>
        <?php elseif (!$keySet): ?>
          No API key found in config.php — paste your key in the form below
        <?php elseif (!$keyLong): ?>
          Key is too short (<?php echo strlen($rawKey); ?> chars) — copy the full key from Brevo
        <?php elseif ($apiValid === false): ?>
          Key is set but Brevo rejected it — it may be revoked. Generate a new key.
        <?php elseif ($apiValid === null): ?>
          Key is set but not tested yet — cURL may be unavailable
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- DIAGNOSTICS -->
  <div class="card">
    <div class="card-head">🔍 Live Diagnostics</div>
    <div class="card-body" style="padding:0 22px">
      <div class="row">
        <span class="label">config.php found</span>
        <span class="<?php echo file_exists($configPath)?'ok':'fail'; ?>"><?php echo file_exists($configPath)?'YES ✅':'NOT FOUND ❌'; ?></span>
      </div>
      <div class="row">
        <span class="label">BREVO_API_KEY in file</span>
        <span class="<?php echo $keySet?'ok':'fail'; ?>"><?php echo $keySet ? htmlspecialchars(substr($rawKey,0,14)).'••••••••' : 'Not set ❌'; ?></span>
      </div>
      <div class="row">
        <span class="label">Key length</span>
        <span class="<?php echo $keyLong?'ok':'fail'; ?>"><?php echo strlen($rawKey); ?> characters <?php echo $keyLong?'✅':'(need >20) ❌'; ?></span>
      </div>
      <div class="row">
        <span class="label">Brevo API test</span>
        <?php if ($curlError): ?>
          <span class="fail">cURL error: <?php echo htmlspecialchars($curlError); ?></span>
        <?php elseif ($apiValid === true): ?>
          <span class="ok">HTTP 200 — Key Valid ✅</span>
        <?php elseif ($apiValid === false): ?>
          <span class="fail">HTTP 401 — Key Invalid/Revoked ❌</span>
        <?php else: ?>
          <span class="warn">Not tested</span>
        <?php endif; ?>
      </div>
      <div class="row">
        <span class="label">EMAIL_FROM</span>
        <span class="val"><?php echo htmlspecialchars($emailFrom ?: 'not set'); ?></span>
      </div>
      <div class="row">
        <span class="label">ADMIN_EMAIL</span>
        <span class="val"><?php echo htmlspecialchars($adminEmail ?: 'not set'); ?></span>
      </div>
      <div class="row">
        <span class="label">PHP cURL</span>
        <span class="<?php echo function_exists('curl_init')?'ok':'fail'; ?>"><?php echo function_exists('curl_init')?'Enabled ✅':'MISSING ❌'; ?></span>
      </div>
      <div class="row">
        <span class="label">PHP version</span>
        <span class="ok"><?php echo phpversion(); ?> ✅</span>
      </div>
      <div class="row">
        <span class="label">config.php writable</span>
        <span class="<?php echo is_writable($configPath)?'ok':'fail'; ?>"><?php echo is_writable($configPath)?'Yes ✅':'No — cannot save ❌'; ?></span>
      </div>
    </div>
  </div>

  <!-- FIX FORM -->
  <div class="card">
    <div class="card-head">🔧 Paste Your Brevo API Key Here</div>
    <div class="card-body">
      <?php if (!is_writable($configPath)): ?>
      <div class="alert alert-fail">❌ config.php is not writable. Right-click the file → Properties → give write permission, then refresh.</div>
      <?php endif; ?>

      <p style="font-size:14px;color:#555;margin-bottom:18px;">
        Get your key from <strong>app.brevo.com → Settings → API Keys</strong>.<br>
        Paste the <em>full key</em> — it should start with <code>xkeysib-</code> and be ~89 characters long.
      </p>

      <form method="POST">
        <label>Brevo API Key *</label>
        <input type="text" name="new_key" required
          placeholder="xkeysib-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-xxxxxxxx"
          value="<?php echo htmlspecialchars($rawKey !== 'YOUR_BREVO_API_KEY_HERE' ? $rawKey : ''); ?>">

        <label>Sender Email (EMAIL_FROM)</label>
        <input type="email" name="new_from"
          placeholder="your@gmail.com"
          value="<?php echo htmlspecialchars($emailFrom); ?>">

        <label>Admin Alert Email (ADMIN_EMAIL)</label>
        <input type="email" name="new_admin"
          placeholder="your@gmail.com"
          value="<?php echo htmlspecialchars($adminEmail); ?>">

        <button type="submit" class="btn">💾 Save to config.php &amp; Test</button>
      </form>

      <?php if ($keyOk): ?>
      <div style="margin-top:20px;padding:16px;background:#EDF5E8;border-radius:10px;font-size:14px;color:#065f46;">
        ✅ Everything looks good! Go to your
        <a href="admin/settings.php" style="color:#2D5016;font-weight:700;">Admin → Settings</a>
        page and click <strong>Send Test Email</strong> to confirm delivery.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="delete-warn">
    ⚠️ <strong>Delete this file after fixing!</strong>
    Remove <code>email_debug.php</code> from your server — it shows your API key.
  </div>
</div>
</body>
</html>
