<?php
// Admin topbar - requires $pendingVacc variable passed or computed
$pendingVaccCount = $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE status IN ('scheduled','overdue') AND next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
$newOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status='pending'")->fetchColumn();
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<div class="topbar">
  <button class="menu-toggle" id="menuToggle">
    <i class="fas fa-bars"></i>
  </button>
  <div class="search-bar">
    <i class="fas fa-search" style="color:#9CA3AF;"></i>
    <input type="text" placeholder="Search orders, flocks, products...">
  </div>
  <div class="topbar-actions">
    <a href="orders.php" class="icon-btn" title="New Orders">
      <i class="fas fa-shopping-cart"></i>
      <?php if($newOrders > 0): ?><span class="badge"><?php echo $newOrders; ?></span><?php endif; ?>
    </a>
    <a href="vaccinations.php" class="icon-btn" title="Vaccination Alerts">
      <i class="fas fa-syringe"></i>
      <?php if($pendingVaccCount > 0): ?><span class="badge"><?php echo $pendingVaccCount; ?></span><?php endif; ?>
    </a>
    <a href="<?php echo SITE_URL; ?>/customer/pages/logout.php" class="icon-btn" title="Logout">
      <i class="fas fa-sign-out-alt"></i>
    </a>
    <div class="user-avatar">
      <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['name'] ?? 'Admin'); ?>&background=4A7C59&color=fff" alt="">
    </div>
  </div>
</div>
