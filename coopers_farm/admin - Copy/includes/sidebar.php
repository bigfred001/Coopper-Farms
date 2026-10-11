<?php
// Admin sidebar include - determine active page
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<div class="sidebar" id="sidebar">
  <a href="index.php" class="logo">
    <i class="fas fa-drumstick-bite"></i>
    <span>Coopers Admin</span>
  </a>

  <nav class="nav-menu">
    <div class="nav-section-label">Main</div>
    <a href="index.php" class="nav-item <?php echo $currentPage=='index'?'active':''; ?>">
      <i class="fas fa-home"></i> Dashboard
    </a>
    <a href="walkin_order.php" class="nav-item <?php echo $currentPage=='walkin_order'?'active':''; ?>" style="background:<?php echo $currentPage=='walkin_order'?'':''; ?>">
      <i class="fas fa-store"></i> Walk-in Sale
    </a>
    <a href="orders.php" class="nav-item <?php echo $currentPage=='orders'?'active':''; ?>">
      <i class="fas fa-shopping-cart"></i> Orders
      <?php
        $pendingCount = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status='pending'")->fetchColumn();
        if($pendingCount > 0) echo "<span class='nav-badge'>$pendingCount</span>";
      ?>
    </a>
    <a href="products.php" class="nav-item <?php echo $currentPage=='products'?'active':''; ?>">
      <i class="fas fa-box"></i> Products
    </a>
    <a href="customers.php" class="nav-item <?php echo $currentPage=='customers'?'active':''; ?>">
      <i class="fas fa-users"></i> Customers
    </a>

    <div class="nav-section-label">Farm</div>
    <a href="flocks.php" class="nav-item <?php echo $currentPage=='flocks'?'active':''; ?>">
      <i class="fas fa-kiwi-bird"></i> Flocks
    </a>
    <a href="vaccinations.php" class="nav-item <?php echo $currentPage=='vaccinations'?'active':''; ?>">
      <i class="fas fa-syringe"></i> Vaccinations
      <?php
        $overdueVacc = $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE status='overdue'")->fetchColumn();
        if($overdueVacc > 0) echo "<span class='nav-badge'>$overdueVacc</span>";
      ?>
    </a>
    <a href="health.php" class="nav-item <?php echo $currentPage=='health'?'active':''; ?>">
      <i class="fas fa-heartbeat"></i> Health
    </a>
    <a href="mortality.php" class="nav-item <?php echo $currentPage=='mortality'?'active':''; ?>">
      <i class="fas fa-skull"></i> Mortality
    </a>
    <a href="egg_production.php" class="nav-item <?php echo $currentPage=='egg_production'?'active':''; ?>">
      <i class="fas fa-egg"></i> Egg Production
    </a>
    <a href="feed.php" class="nav-item <?php echo $currentPage=='feed'?'active':''; ?>">
      <i class="fas fa-seedling"></i> Feed Management
    </a>

    <div class="nav-section-label">Reports</div>
    <a href="reports.php" class="nav-item <?php echo $currentPage=='reports'?'active':''; ?>">
      <i class="fas fa-chart-bar"></i> Reports
    </a>
    <a href="email_logs.php" class="nav-item <?php echo $currentPage=='email_logs'?'active':''; ?>">
      <i class="fas fa-envelope-open-text"></i> Email Logs
    </a>
    <a href="audit_log.php" class="nav-item <?php echo $currentPage=='audit_log'?'active':''; ?>">
      <i class="fas fa-clipboard-list"></i> Audit Log
    </a>
    <a href="settings.php" class="nav-item <?php echo $currentPage=='settings'?'active':''; ?>">
      <i class="fas fa-cog"></i> Settings & Email
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="user-profile-sidebar">
      <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['name'] ?? 'Admin'); ?>&background=4A7C59&color=fff" alt="">
      <div class="user-info">
        <strong><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></strong>
        <span>Farm Manager</span>
      </div>
    </div>
  </div>
</div>
