<?php
// ============================================================
// COOPERS FARM — Email Service
// Provider: Mailtrap (free — perfect for localhost dev)
//
// SETUP (2 minutes):
// 1. Sign up free at: https://mailtrap.io
// 2. Go to: Email Testing → Inboxes → My Inbox
// 3. Click "Show Credentials" → choose "PHP" or "SMTP"
// 4. Copy Username and Password into config.php
// ============================================================

class EmailService {

    private string $host;
    private string $username;
    private string $password;
    private int    $port;
    private string $fromEmail;
    private string $fromName;
    private string $adminEmail;
    private string $adminName;
    private bool   $enabled;

    public function __construct() {
        $this->host       = MAILTRAP_HOST;
        $this->username   = trim(MAILTRAP_USERNAME);
        $this->password   = trim(MAILTRAP_PASSWORD);
        $this->port       = MAILTRAP_PORT;
        $this->fromEmail  = EMAIL_FROM;
        $this->fromName   = EMAIL_FROM_NAME;
        $this->adminEmail = ADMIN_EMAIL;
        $this->adminName  = ADMIN_NAME;

        // Enabled when real credentials are set
        $this->enabled = !empty($this->username)
            && $this->username !== 'YOUR_MAILTRAP_USERNAME'
            && !empty($this->password)
            && $this->password !== 'YOUR_MAILTRAP_PASSWORD';
    }

    // -------------------------------------------------------
    // CORE SEND — uses PHP's mail via SMTP socket (no library)
    // -------------------------------------------------------
    private function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = '', string $emailType = 'test', ?int $orderId = null): bool {
        if (!$this->enabled) {
            error_log("[EmailService] Disabled — set MAILTRAP_USERNAME and MAILTRAP_PASSWORD in config.php");
            $this->logEmail($toEmail, $toName, $subject, $emailType, 'failed', 'Email system disabled', $orderId);
            return false;
        }

        $boundary = md5(uniqid(time()));
        $textBody = $textBody ?: strip_tags($htmlBody);

        // Build MIME message
        $message  = "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($textBody)) . "\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $message .= "--{$boundary}--";

        $headers  = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        $headers .= "To: {$toName} <{$toEmail}>\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "X-Mailer: CoopersFarm/1.0\r\n";

        // Send via SMTP socket to Mailtrap
        $result = $this->smtpSend($toEmail, $toName, $subject, $headers, $message);
        $errMsg = !$result ? ($_SESSION['email_last_error'] ?? 'SMTP failed') : null;
        $this->logEmail($toEmail, $toName, $subject, $emailType, $result ? 'sent' : 'failed', $errMsg, $orderId);
        return $result;
    }

    // -------------------------------------------------------
    // LOG EMAIL TO DATABASE
    // -------------------------------------------------------
    private function logEmail(string $toEmail, string $toName, string $subject, string $emailType, string $status, ?string $error = null, ?int $orderId = null): void {
        try {
            global $pdo;
            if (!$pdo) return;
            // Sanitize emailType to match ENUM
            $validTypes = ['welcome','order_confirmation','order_status','admin_new_order','admin_new_customer','admin_vaccination','admin_feed','admin_mortality','test'];
            if (!in_array($emailType, $validTypes)) $emailType = 'test';
            $pdo->prepare("INSERT INTO email_logs (recipient_email, recipient_name, subject, email_type, status, error_message, order_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$toEmail, $toName, $subject, $emailType, $status, $error, $orderId]);
        } catch (Exception $e) {
            error_log("[EmailService] Failed to log email: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------
    // RAW SMTP SOCKET — works on WAMP/XAMPP without extensions
    // -------------------------------------------------------
    private function smtpSend(string $toEmail, string $toName, string $subject, string $headers, string $body): bool {
        $errno  = 0;
        $errstr = '';

        // Try plain TCP on port 2525 first (most reliable on WAMP — no SSL needed)
        $socket = @fsockopen($this->host, 2525, $errno, $errstr, 15);

        if (!$socket) {
            // Fallback: port 587
            $socket = @fsockopen($this->host, 587, $errno, $errstr, 15);
        }

        if (!$socket) {
            error_log("[EmailService] SMTP connect failed: $errstr ($errno)");
            $_SESSION['email_last_error'] = "Cannot connect to Mailtrap ($errstr). Check internet connection.";
            return false;
        }

        stream_set_timeout($socket, 15);

        // Helper: read full server response (handles multi-line like EHLO)
        $read = function() use ($socket) {
            $response = '';
            while (!feof($socket)) {
                $line = fgets($socket, 1024);
                if ($line === false) break;
                $response .= $line;
                // A line with "XXX " (space after code) is the last line
                // A line with "XXX-" means more lines follow
                if (strlen($line) >= 4 && $line[3] === ' ') break;
            }
            return $response;
        };

        // Helper: send command and read response
        $cmd = function(string $command, string $expect) use ($socket, $read) {
            fwrite($socket, $command);
            $resp = $read();
            $code = substr(trim($resp), 0, 3);
            return [$code === $expect, $code, trim($resp)];
        };

        // --- SMTP conversation ---

        // 1. Read server greeting
        $greeting = $read();
        $greetCode = substr(trim($greeting), 0, 3);
        if ($greetCode !== '220') {
            error_log("[EmailService] Bad greeting: $greeting");
            $_SESSION['email_last_error'] = "Bad SMTP greeting: $greetCode";
            fclose($socket);
            return false;
        }

        // 2. EHLO
        [$ok, $code, $resp] = $cmd("EHLO coopersfarm.local\r\n", '250');
        if (!$ok) {
            // Try HELO as fallback
            [$ok, $code, $resp] = $cmd("HELO coopersfarm.local\r\n", '250');
        }
        if (!$ok) {
            error_log("[EmailService] EHLO/HELO failed: $resp");
            $_SESSION['email_last_error'] = "EHLO failed ($code): $resp";
            fclose($socket);
            return false;
        }

        // 3. AUTH LOGIN
        [$ok, $code, $resp] = $cmd("AUTH LOGIN\r\n", '334');
        if (!$ok) {
            error_log("[EmailService] AUTH LOGIN failed: $resp");
            $_SESSION['email_last_error'] = "AUTH failed ($code): $resp";
            fclose($socket);
            return false;
        }

        // 4. Username
        [$ok, $code, $resp] = $cmd(base64_encode($this->username) . "\r\n", '334');
        if (!$ok) {
            error_log("[EmailService] Username rejected: $resp");
            $_SESSION['email_last_error'] = "Username rejected ($code) — check MAILTRAP_USERNAME in config.php";
            fclose($socket);
            return false;
        }

        // 5. Password
        [$ok, $code, $resp] = $cmd(base64_encode($this->password) . "\r\n", '235');
        if (!$ok) {
            error_log("[EmailService] Password rejected: $resp");
            $_SESSION['email_last_error'] = "Password rejected ($code) — check MAILTRAP_PASSWORD in config.php";
            fclose($socket);
            return false;
        }

        // 6. MAIL FROM
        [$ok, $code, $resp] = $cmd("MAIL FROM:<{$this->fromEmail}>\r\n", '250');
        if (!$ok) {
            error_log("[EmailService] MAIL FROM rejected: $resp");
            $_SESSION['email_last_error'] = "MAIL FROM rejected ($code): $resp";
            fclose($socket);
            return false;
        }

        // 7. RCPT TO
        [$ok, $code, $resp] = $cmd("RCPT TO:<{$toEmail}>\r\n", '250');
        if (!$ok) {
            error_log("[EmailService] RCPT TO rejected: $resp");
            $_SESSION['email_last_error'] = "RCPT TO rejected ($code): invalid address?";
            fclose($socket);
            return false;
        }

        // 8. DATA
        [$ok, $code, $resp] = $cmd("DATA\r\n", '354');
        if (!$ok) {
            error_log("[EmailService] DATA rejected: $resp");
            $_SESSION['email_last_error'] = "DATA rejected ($code): $resp";
            fclose($socket);
            return false;
        }

        // 9. Send headers + body + end marker
        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $resp     = $read();
        $sentCode = substr(trim($resp), 0, 3);
        if ($sentCode !== '250') {
            error_log("[EmailService] Message rejected after DATA: $resp");
            $_SESSION['email_last_error'] = "Message rejected ($sentCode): $resp";
            fclose($socket);
            return false;
        }

        // 10. QUIT
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        unset($_SESSION['email_last_error']);
        error_log("[EmailService] Sent OK → $toEmail | $subject");
        return true; // logging done by send() wrapper
    }

    // -------------------------------------------------------
    // BASE HTML WRAPPER
    // -------------------------------------------------------
    private function wrap(string $content, string $preheader = ''): string {
        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Coopers Farm</title>
<style>
  body { margin:0; padding:0; background:#f0f4f0; font-family:"Segoe UI",Arial,sans-serif; }
  .wrapper { max-width:600px; margin:0 auto; padding:32px 16px; }
  .card { background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,0.08); }
  .header { background:linear-gradient(135deg,#2D5016,#4A7C59); padding:36px 40px; text-align:center; }
  .header-logo { font-size:28px; font-weight:700; color:#ffffff; }
  .header-tagline { font-size:13px; color:rgba(255,255,255,0.75); margin-top:4px; }
  .body { padding:36px 40px; }
  .body h2 { font-size:22px; color:#1A1A1A; margin:0 0 8px; }
  .body p { font-size:15px; color:#555; line-height:1.7; margin:0 0 16px; }
  .highlight-box { background:#EDF5E8; border-left:4px solid #4A7C59; border-radius:0 10px 10px 0; padding:16px 20px; margin:20px 0; }
  .highlight-box p { margin:0; color:#2D5016; font-weight:600; }
  .order-table { width:100%; border-collapse:collapse; margin:20px 0; }
  .order-table th { background:#f9f9f9; padding:10px 14px; text-align:left; font-size:12px; color:#888; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e5e7eb; }
  .order-table td { padding:12px 14px; font-size:14px; color:#333; border-bottom:1px solid #f3f4f6; }
  .order-table .total-row td { font-weight:700; color:#1A1A1A; border-top:2px solid #e5e7eb; }
  .btn { display:inline-block; background:#4A7C59; color:#ffffff !important; text-decoration:none; padding:14px 32px; border-radius:50px; font-size:15px; font-weight:700; margin:16px 0; }
  .btn-orange { background:#E87722; }
  .status-pill { display:inline-block; padding:5px 14px; border-radius:50px; font-size:12px; font-weight:700; }
  .status-pending { background:#fef3c7; color:#92400e; }
  .status-confirmed { background:#dbeafe; color:#1e40af; }
  .status-processing { background:#ede9fe; color:#5b21b6; }
  .status-out_for_delivery { background:#ffedd5; color:#c2410c; }
  .status-delivered { background:#d1fae5; color:#065f46; }
  .status-cancelled { background:#fee2e2; color:#991b1b; }
  .divider { border:none; border-top:1px solid #f3f4f6; margin:24px 0; }
  .footer { padding:24px 40px; background:#f9fafb; text-align:center; }
  .footer p { font-size:12px; color:#aaa; margin:0 0 6px; }
  .footer a { color:#4A7C59; text-decoration:none; }
</style>
</head>
<body>
' . ($preheader ? '<div style="display:none;max-height:0;overflow:hidden;">' . htmlspecialchars($preheader) . '</div>' : '') . '
<div class="wrapper">
  <div class="card">
    <div class="header">
      <div class="header-logo">🐔 Coopers Farm</div>
      <div class="header-tagline">Fresh from the farm, right to your door</div>
    </div>
    <div class="body">' . $content . '</div>
    <div class="footer">
      <p>© ' . date('Y') . ' Coopers Farm · 123 Farm Road, Accra, Ghana</p>
      <p><a href="mailto:' . EMAIL_FROM . '">' . EMAIL_FROM . '</a> · +233 24 123 4567</p>
    </div>
  </div>
</div>
</body>
</html>';
    }

    private function statusBadge(string $status): string {
        $labels = [
            'pending'          => 'Pending',
            'confirmed'        => 'Confirmed',
            'processing'       => 'Processing',
            'out_for_delivery' => 'Out for Delivery',
            'delivered'        => 'Delivered',
            'cancelled'        => 'Cancelled',
        ];
        $label = $labels[$status] ?? ucfirst($status);
        return "<span class=\"status-pill status-{$status}\">{$label}</span>";
    }

    // ================================================================
    // 1. CUSTOMER WELCOME EMAIL
    // ================================================================
    public function sendWelcome(string $toEmail, string $toName): bool {
        $subject = "Welcome to Coopers Farm, {$toName}! 🐔";
        $html = $this->wrap('
            <h2>Welcome, ' . htmlspecialchars($toName) . '! 🎉</h2>
            <p>Thank you for joining the Coopers Farm family. We\'re thrilled to have you on board.</p>
            <p>You can now browse our fresh products and place orders directly from our website.</p>
            <div class="highlight-box">
                <p>🥚 Fresh eggs collected daily<br>
                   🐔 Healthy, well-fed broilers &amp; layers<br>
                   🚚 Fast delivery across Accra &amp; beyond<br>
                   📱 MTN MoMo, Vodafone &amp; AirtelTigo accepted</p>
            </div>
            <a href="' . SITE_URL . '/customer/index.php" class="btn">Shop Now →</a>
            <hr class="divider">
            <p style="font-size:13px;color:#888;">Need help? Call us at <strong>+233 24 123 4567</strong>. Mon–Sat, 7AM–6PM.</p>
        ', "Welcome to Coopers Farm!");

        $this->sendAdminNewCustomer($toEmail, $toName);
        return $this->send($toEmail, $toName, $subject, $html, '', 'welcome');
    }

    // ================================================================
    // 2. ORDER CONFIRMATION
    // ================================================================
    public function sendOrderConfirmation(array $order, array $items): bool {
        $toEmail = $order['customer_email'] ?? '';
        $toName  = $order['customer_name'] ?? 'Customer';
        if (!$toEmail) return false;

        $itemRows = '';
        foreach ($items as $item) {
            $itemRows .= '<tr>
                <td>' . htmlspecialchars($item['product_name']) . '</td>
                <td style="text-align:center;">' . $item['quantity'] . '</td>
                <td style="text-align:right;">' . formatCurrency($item['unit_price']) . '</td>
                <td style="text-align:right;font-weight:600;">' . formatCurrency($item['subtotal']) . '</td>
            </tr>';
        }

        $deliveryDate = $order['delivery_date'] ? date('l, F j, Y', strtotime($order['delivery_date'])) : 'To be confirmed';
        $paymentLabel = ucwords(str_replace(['momo_', '_'], ['', ' '], $order['payment_method'] ?? 'cash'));

        $subject = "Order Confirmed! #{$order['order_number']} — Coopers Farm 🐔";
        $html = $this->wrap('
            <h2>Your Order is Placed! ✅</h2>
            <p>Hi ' . htmlspecialchars($toName) . ', we\'ve received your order and it\'s being prepared.</p>
            <div class="highlight-box">
                <p>Order: <strong>' . $order['order_number'] . '</strong><br>
                   Status: ' . $this->statusBadge('pending') . '<br>
                   Payment: ' . htmlspecialchars($paymentLabel) . '<br>
                   Delivery Date: ' . $deliveryDate . '</p>
            </div>
            <table class="order-table">
                <thead><tr><th>Product</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Price</th><th style="text-align:right;">Total</th></tr></thead>
                <tbody>
                    ' . $itemRows . '
                    <tr><td colspan="3">Delivery Fee</td><td style="text-align:right;">' . ($order['delivery_fee'] == 0 ? 'Free 🎉' : formatCurrency($order['delivery_fee'])) . '</td></tr>
                    <tr class="total-row"><td colspan="3">Total</td><td style="text-align:right;color:#4A7C59;">' . formatCurrency($order['total_amount']) . '</td></tr>
                </tbody>
            </table>
            <p><strong>Delivery Address:</strong><br>' . nl2br(htmlspecialchars($order['delivery_address'] ?? '')) . '</p>
            <a href="' . SITE_URL . '/customer/pages/profile.php" class="btn">Track Your Order →</a>
        ', "Order #{$order['order_number']} confirmed");

        $this->sendAdminNewOrder($order, $items);
        return $this->send($toEmail, $toName, $subject, $html, '', 'order_confirmation');
    }

    // ================================================================
    // 3. ORDER STATUS UPDATE
    // ================================================================
    public function sendOrderStatusUpdate(array $order, string $oldStatus): bool {
        $toEmail   = $order['customer_email'] ?? '';
        $toName    = $order['customer_name'] ?? 'Customer';
        $newStatus = $order['order_status'];
        if (!$toEmail) return false;

        $messages = [
            'confirmed'        => ['🎉 Order Confirmed!', 'Great news! Your order has been confirmed.'],
            'processing'       => ['⚙️ Order Being Processed', 'Your order is being actively prepared.'],
            'out_for_delivery' => ['🚚 Your Order is On Its Way!', 'Your order is out for delivery! Expect it today.'],
            'delivered'        => ['📦 Order Delivered!', 'Your order has been delivered. Enjoy your fresh Coopers Farm products!'],
            'cancelled'        => ['❌ Order Cancelled', 'Your order has been cancelled. Contact us if this was unexpected.'],
        ];
        [$headline, $message] = $messages[$newStatus] ?? ['Order Update', 'Your order status has been updated.'];

        $subject = "{$headline} — Order #{$order['order_number']}";
        $html = $this->wrap('
            <h2>' . $headline . '</h2>
            <p>Hi ' . htmlspecialchars($toName) . ', ' . $message . '</p>
            <div class="highlight-box">
                <p>Order: <strong>#' . $order['order_number'] . '</strong><br>
                   New Status: ' . $this->statusBadge($newStatus) . '<br>
                   Previous: ' . $this->statusBadge($oldStatus) . '</p>
            </div>
            ' . ($newStatus !== 'cancelled' ? '<a href="' . SITE_URL . '/customer/pages/profile.php" class="btn">View Order →</a>' : '') . '
        ', $headline);

        return $this->send($toEmail, $toName, $subject, $html, '', 'order_status');
    }

    // ================================================================
    // 4. ADMIN — NEW ORDER ALERT
    // ================================================================
    private function sendAdminNewOrder(array $order, array $items): bool {
        $itemList = implode(', ', array_map(fn($i) => $i['product_name'] . ' ×' . $i['quantity'], $items));
        $subject  = "🛒 New Order #{$order['order_number']} — " . formatCurrency($order['total_amount']);
        $html = $this->wrap('
            <h2>New Order Received! 🛒</h2>
            <div class="highlight-box">
                <p>Order: <strong>#' . $order['order_number'] . '</strong><br>
                   Customer: <strong>' . htmlspecialchars($order['customer_name']) . '</strong><br>
                   Phone: ' . htmlspecialchars($order['customer_phone'] ?? '') . '<br>
                   Total: <strong>' . formatCurrency($order['total_amount']) . '</strong><br>
                   Payment: ' . ucwords(str_replace(['momo_','_'], ['',''], $order['payment_method'] ?? '')) . '<br>
                   Delivery: ' . ($order['delivery_date'] ? date('M d, Y', strtotime($order['delivery_date'])) : 'Not set') . '</p>
            </div>
            <p><strong>Items:</strong> ' . htmlspecialchars($itemList) . '</p>
            <p><strong>Address:</strong> ' . nl2br(htmlspecialchars($order['delivery_address'] ?? '')) . '</p>
            <a href="' . SITE_URL . '/admin/orders.php" class="btn btn-orange">Manage Orders →</a>
        ');
        return $this->send($this->adminEmail, $this->adminName, $subject, $html, '', 'admin_new_order');
    }

    // ================================================================
    // 5. ADMIN — NEW CUSTOMER ALERT
    // ================================================================
    private function sendAdminNewCustomer(string $customerEmail, string $customerName): bool {
        $subject = "👤 New Customer — {$customerName}";
        $html = $this->wrap('
            <h2>New Customer Registered! 👤</h2>
            <div class="highlight-box">
                <p>Name: <strong>' . htmlspecialchars($customerName) . '</strong><br>
                   Email: <strong>' . htmlspecialchars($customerEmail) . '</strong><br>
                   Signed up: ' . date('F j, Y \a\t g:i A') . '</p>
            </div>
            <a href="' . SITE_URL . '/admin/customers.php" class="btn btn-orange">View Customers →</a>
        ');
        return $this->send($this->adminEmail, $this->adminName, $subject, $html, '', 'admin_new_customer');
    }

    // ================================================================
    // 6. ADMIN — VACCINATION ALERT
    // ================================================================
    public function sendAdminVaccinationAlert(array $vaccinations): bool {
        if (empty($vaccinations)) return false;
        $rows = '';
        foreach ($vaccinations as $v) {
            $daysUntil = (strtotime($v['next_due_date']) - time()) / 86400;
            $urgency   = $daysUntil <= 0 ? '🔴 OVERDUE' : ($daysUntil <= 1 ? '🟠 TODAY' : '🟡 Soon');
            $rows .= '<tr><td>' . htmlspecialchars($v['flock_name']) . '</td><td>' . htmlspecialchars($v['vaccine_name']) . '</td><td>' . date('M d, Y', strtotime($v['next_due_date'])) . '</td><td>' . $urgency . '</td></tr>';
        }
        $count   = count($vaccinations);
        $subject = "💉 {$count} Vaccination(s) Due — Coopers Farm";
        $html    = $this->wrap('
            <h2>Vaccination Alert 💉</h2>
            <p><strong>' . $count . ' vaccination(s)</strong> are due soon or overdue:</p>
            <table class="order-table">
                <thead><tr><th>Flock</th><th>Vaccine</th><th>Due Date</th><th>Urgency</th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table>
            <a href="' . SITE_URL . '/admin/vaccinations.php" class="btn btn-orange">Go to Vaccinations →</a>
        ');
        return $this->send($this->adminEmail, $this->adminName, $subject, $html, '', 'admin_vaccination');
    }

    // ================================================================
    // 7. ADMIN — LOW FEED ALERT
    // ================================================================
    public function sendAdminLowFeedAlert(array $feeds): bool {
        if (empty($feeds)) return false;
        $rows = '';
        foreach ($feeds as $f) {
            $rows .= '<tr><td>' . htmlspecialchars($f['feed_type']) . '</td><td>' . number_format($f['quantity_kg'], 1) . ' kg</td><td>' . number_format($f['reorder_level'], 0) . ' kg</td><td style="color:#ef4444;font-weight:700;">⚠ Restock</td></tr>';
        }
        $count   = count($feeds);
        $subject = "⚠️ {$count} Feed Stock(s) Low — Coopers Farm";
        $html    = $this->wrap('
            <h2>Low Feed Stock ⚠️</h2>
            <p><strong>' . $count . ' feed type(s)</strong> are below reorder level:</p>
            <table class="order-table">
                <thead><tr><th>Feed Type</th><th>Current</th><th>Reorder Level</th><th>Action</th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table>
            <a href="' . SITE_URL . '/admin/feed.php" class="btn btn-orange">Manage Feed →</a>
        ');
        return $this->send($this->adminEmail, $this->adminName, $subject, $html, '', 'admin_feed');
    }

    // ================================================================
    // 8. ADMIN — MORTALITY ALERT
    // ================================================================
    public function sendAdminMortalityAlert(string $flockName, int $count, string $cause = ''): bool {
        $subject = "💀 Mortality Alert — {$flockName} ({$count} birds)";
        $html = $this->wrap('
            <h2>Mortality Alert 💀</h2>
            <div class="highlight-box" style="background:#fff5f5;border-color:#ef4444;">
                <p style="color:#991b1b;">Flock: <strong>' . htmlspecialchars($flockName) . '</strong><br>
                   Deaths: <strong>' . $count . ' birds</strong><br>
                   Cause: ' . htmlspecialchars($cause ?: 'Unknown') . '<br>
                   Date: ' . date('F j, Y') . '</p>
            </div>
            <p>Please investigate immediately to prevent further losses.</p>
            <a href="' . SITE_URL . '/admin/mortality.php" class="btn btn-orange" style="background:#ef4444;">View Mortality Log →</a>
        ');
        return $this->send($this->adminEmail, $this->adminName, $subject, $html, '', 'admin_mortality');
    }

    // ================================================================
    // 9. TEST EMAIL
    // ================================================================
    public function sendTestEmail(string $toEmail, string $toName): bool {
        $subject = "✅ Test Email — Coopers Farm Email Working!";
        $html = $this->wrap('
            <h2>Test Email Successful! ✅</h2>
            <p>Hi ' . htmlspecialchars($toName) . ', your Coopers Farm email system is configured and working via <strong>Mailtrap</strong>.</p>
            <div class="highlight-box">
                <p>Provider: Mailtrap (testing inbox)<br>
                   Sent at: ' . date('F j, Y \a\t g:i A') . '<br>
                   SMTP Status: ✅ Connected</p>
            </div>
            <p>All automated emails are now active:</p>
            <ul style="color:#555;font-size:15px;line-height:2.2;padding-left:20px;">
                <li>✅ Customer welcome email on signup</li>
                <li>✅ Order confirmation on checkout</li>
                <li>✅ Order status updates</li>
                <li>✅ Admin new order &amp; customer alerts</li>
                <li>✅ Vaccination, feed &amp; mortality alerts</li>
            </ul>
            <a href="' . SITE_URL . '/admin/settings.php" class="btn">Back to Settings →</a>
        ');
        return $this->send($toEmail, $toName, $subject, $html, '', 'test');
    }

    public function isEnabled(): bool { return $this->enabled; }
}
