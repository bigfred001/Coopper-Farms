<?php
// admin/includes/sidebar.php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
function isActive(string $page): string {
    global $currentPage;
    return $currentPage === $page ? ' active' : '';
}
?>
<div class="sidebar" id="sidebar">
  <!-- Logo -->
  <a href="index.php" class="logo">
    <i class="fas fa-feather-alt" style="color:var(--primary);font-size:22px;"></i>
    <div>
      <div style="font-size:14px;font-weight:700;color:var(--primary);line-height:1;">Coopers</div>
      <div style="font-size:11px;color:#9ca3af;font-weight:400;">Admin</div>
    </div>
  </a>

  <nav class="nav-menu">
    <!-- MAIN -->
    <div class="nav-section-label">Main</div>
    <a href="index.php"        class="nav-item<?php echo isActive('index'); ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="walkin_order.php" class="nav-item<?php echo isActive('walkin_order'); ?>"><i class="fas fa-store"></i> Walk-in Sale</a>
    <a href="orders.php"       class="nav-item<?php echo isActive('orders'); ?>"><i class="fas fa-shopping-cart"></i> Orders</a>
    <a href="products.php"     class="nav-item<?php echo isActive('products'); ?>"><i class="fas fa-box"></i> Products</a>
    <a href="customers.php"    class="nav-item<?php echo isActive('customers'); ?>"><i class="fas fa-users"></i> Customers</a>

    <!-- FARM -->
    <div class="nav-section-label">Farm</div>
    <a href="flocks.php"       class="nav-item<?php echo isActive('flocks'); ?>"><i class="fas fa-kiwi-bird"></i> Flocks</a>
    <a href="vaccinations.php" class="nav-item<?php echo isActive('vaccinations'); ?>">
      <i class="fas fa-syringe"></i> Vaccinations
      <?php
        // Show overdue badge if any
        try {
            global $pdo;
            if (isset($pdo)) {
                $overdue = $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE status='overdue'")->fetchColumn();
                if ($overdue > 0) echo '<span class="nav-badge">'.$overdue.'</span>';
            }
        } catch (Exception $e) {}
      ?>
    </a>
    <a href="health.php"       class="nav-item<?php echo isActive('health'); ?>"><i class="fas fa-heartbeat"></i> Health</a>
    <a href="mortality.php"    class="nav-item<?php echo isActive('mortality'); ?>"><i class="fas fa-skull"></i> Mortality</a>
    <a href="egg_production.php" class="nav-item<?php echo isActive('egg_production'); ?>"><i class="fas fa-egg"></i> Egg Production</a>
    <a href="feed.php"         class="nav-item<?php echo isActive('feed'); ?>"><i class="fas fa-seedling"></i> Feed Management</a>

    <!-- REPORTS -->
    <div class="nav-section-label">Reports</div>
    <a href="reports.php"      class="nav-item<?php echo isActive('reports'); ?>"><i class="fas fa-chart-bar"></i> Reports</a>
    <a href="email_logs.php"   class="nav-item<?php echo isActive('email_logs'); ?>"><i class="fas fa-envelope"></i> Email Logs</a>
    <a href="audit_log.php"    class="nav-item<?php echo isActive('audit_log'); ?>"><i class="fas fa-history"></i> Audit Log</a>
    <a href="settings.php"     class="nav-item<?php echo isActive('settings'); ?>"><i class="fas fa-cog"></i> Settings &amp; Email</a>
  </nav>

  <!-- Footer: logged-in user -->
  <div class="sidebar-footer">
    <div class="user-profile-sidebar">
      <div style="width:34px;height:34px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;">
        <?php echo strtoupper(substr(str_replace(' ','', $_SESSION['name'] ?? 'AD'), 0, 2)); ?>
      </div>
      <div class="user-info">
        <strong><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></strong>
        <span><?php echo htmlspecialchars(ucfirst($_SESSION['role'] ?? 'Admin')); ?></span>
      </div>
    </div>
  </div>
</div>
