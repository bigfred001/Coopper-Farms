<?php
// admin/includes/topbar.php
// Self-contained — inline critical styles so topbar always renders correctly
?>
<?php require_once __DIR__ . '/../includes/day.php'; $__day = currentDayRow($pdo); ?>
<a class="day-banner <?php echo $__day ? 'open' : 'closed'; ?>" id="dayBanner" data-open="<?php echo $__day ? 1 : 0; ?>" href="day.php">
  <i class="fas fa-<?php echo $__day ? 'sun' : 'moon'; ?>"></i>
  <?php if($__day): ?>Day open since <?php echo date('H:i', strtotime($__day['started_at'])); ?><?php else: ?>Day not started<?php endif; ?>
</a>
<div class="topbar">
  <button class="topbar-menu-btn">
    <i class="fas fa-bars"></i>
  </button>

  <div class="topbar-search-wrap">
    <i class="fas fa-search"></i>
    <input type="text" placeholder="Search orders, flocks, products..." id="globalSearch" autocomplete="off">
  </div>

  <div class="topbar-right">
    <a href="walkin_order.php" class="tb-icon-btn" title="New Walk-in Sale">
      <i class="fas fa-shopping-cart"></i>
    </a>
    <a href="egg_production.php" class="tb-icon-btn" title="Log Eggs">
      <i class="fas fa-egg"></i>
    </a>
    <a href="../logout.php" class="tb-icon-btn" title="Logout">
      <i class="fas fa-sign-out-alt"></i>
    </a>
    <div class="tb-avatar" title="<?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?>">
      <?php echo strtoupper(substr(str_replace(' ', '', $_SESSION['name'] ?? 'AD'), 0, 2)); ?>
    </div>
  </div>
</div>
