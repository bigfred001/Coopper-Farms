<?php
require_once '../config/config.php';
requireAdmin();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Add new vaccination record ────────────────────────────
    if (isset($_POST['add_vaccination'])) {
        $flockId  = (int)$_POST['flock_id'];
        $nextDue  = !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : null;
        $adminBy  = sanitize($_POST['administered_by'] ?? '');

        $pdo->prepare("INSERT INTO vaccinations (flock_id, vaccine_name, date_given, next_due_date, dosage, method, administered_by, birds_vaccinated, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,'completed',?)")
            ->execute([$flockId, sanitize($_POST['vaccine_name']), $_POST['date_given'], $nextDue,
                       sanitize($_POST['dosage']), $_POST['method'], $adminBy,
                       (int)$_POST['birds_vaccinated'], sanitize($_POST['notes']), $_SESSION['user_id']]);

        // Create a scheduled future entry if next due date given
        if ($nextDue) {
            $pdo->prepare("INSERT INTO vaccinations (flock_id, vaccine_name, date_given, next_due_date, dosage, method, status, created_by) VALUES (?,?,?,NULL,?,?,'scheduled',?)")
                ->execute([$flockId, sanitize($_POST['vaccine_name']), $nextDue,
                           sanitize($_POST['dosage']), $_POST['method'], $_SESSION['user_id']]);
        }

        auditLog($pdo, 'add_vaccination', 'vaccination', null,
            "Recorded " . sanitize($_POST['vaccine_name']) . " for flock #{$flockId}. Administered by: {$adminBy}");
        $msg = 'success:Vaccination recorded!';
    }

    // ── Sync overdue (auto-update scheduled past due date) ────
    if (isset($_POST['mark_overdue'])) {
        $updated = $pdo->exec("UPDATE vaccinations SET status='overdue' WHERE status='scheduled' AND next_due_date < CURDATE()");
        auditLog($pdo, 'sync_overdue', 'vaccination', null, "Synced overdue vaccinations. {$updated} records updated.");
        $msg = "success:{$updated} vaccination(s) marked as overdue.";
    }

    // ── Mark as completed ────────────────────────────────────
    if (isset($_POST['mark_complete'])) {
        $vid       = (int)$_POST['vacc_id'];
        $dateGiven = sanitize($_POST['date_given'] ?? date('Y-m-d'));
        $adminBy   = sanitize($_POST['administered_by'] ?? '');
        $birds     = max(0, (int)($_POST['birds_vaccinated'] ?? 0));
        $notes     = sanitize($_POST['notes'] ?? '');
        $nextDue2  = !empty($_POST['next_due_date']) ? sanitize($_POST['next_due_date']) : null;

        // Fetch the scheduled record to get flock + vaccine info
        $schedRow = $pdo->prepare("SELECT v.*, f.flock_name FROM vaccinations v JOIN flocks f ON f.id=v.flock_id WHERE v.id=?");
        $schedRow->execute([$vid]);
        $sched = $schedRow->fetch();

        if (!$sched) {
            $msg = 'error:Vaccination record not found.';
        } else {
            // Update the scheduled record to completed (NO duplicate insert)
            $pdo->prepare("UPDATE vaccinations SET status='completed', date_given=?, administered_by=?, birds_vaccinated=?, notes=?, completed_by=? WHERE id=?")
                ->execute([$dateGiven, $adminBy, $birds, $notes, $_SESSION['user_id'], $vid]);

            // If they set a next due date, create the next scheduled entry
            if ($nextDue2) {
                $pdo->prepare("INSERT INTO vaccinations (flock_id, vaccine_name, date_given, next_due_date, dosage, method, status, created_by) VALUES (?,?,?,NULL,?,?,'scheduled',?)")
                    ->execute([$sched['flock_id'], $sched['vaccine_name'], $nextDue2,
                               $sched['dosage'], $sched['method'], $_SESSION['user_id']]);
            }

            auditLog($pdo, 'complete_vaccination', 'vaccination', $vid,
                "Marked '{$sched['vaccine_name']}' as completed for flock '{$sched['flock_name']}'. Administered by: {$adminBy}. Birds: {$birds}");
            $msg = "success:{$sched['vaccine_name']} marked as completed for {$sched['flock_name']}!";
        }
    }

    // ── Reschedule overdue vaccination ───────────────────────
    if (isset($_POST['reschedule'])) {
        $vid      = (int)$_POST['vacc_id'];
        $newDate  = sanitize($_POST['new_due_date']);
        $reason   = sanitize($_POST['reason'] ?? '');

        $vaccRow = $pdo->prepare("SELECT v.*, f.flock_name FROM vaccinations v JOIN flocks f ON f.id=v.flock_id WHERE v.id=?");
        $vaccRow->execute([$vid]);
        $vacc = $vaccRow->fetch();

        if ($vacc && $newDate) {
            $oldDate = $vacc['next_due_date'];
            $pdo->prepare("UPDATE vaccinations SET next_due_date=?, status='scheduled', notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?")
                ->execute([$newDate, "\n[Rescheduled from {$oldDate} to {$newDate}" . ($reason ? " — {$reason}" : '') . "]", $vid]);

            auditLog($pdo, 'reschedule_vaccination', 'vaccination', $vid,
                "Rescheduled '{$vacc['vaccine_name']}' for '{$vacc['flock_name']}' from {$oldDate} to {$newDate}. Reason: {$reason}");
            $msg = "success:Vaccination rescheduled to " . date('M d, Y', strtotime($newDate)) . "!";
        } else {
            $msg = 'error:Could not reschedule — invalid date or record not found.';
        }
    }

    header('Location: vaccinations.php?msg=' . urlencode($msg));
    exit;
}

// ── Auto-sync overdue on every page load ─────────────────────
$pdo->exec("UPDATE vaccinations SET status='overdue' WHERE status='scheduled' AND next_due_date < CURDATE()");

$urlMsg      = $_GET['msg'] ?? '';
$flockFilter = (int)($_GET['flock_id'] ?? 0);
$statusFilter= $_GET['status'] ?? '';

$where  = "WHERE 1=1";
$params = [];
if ($flockFilter) { $where .= " AND v.flock_id=?"; $params[] = $flockFilter; }
if ($statusFilter){ $where .= " AND v.status=?";   $params[] = $statusFilter; }

$stmt = $pdo->prepare("
    SELECT v.*, f.flock_name, f.bird_type,
           cb.name as created_by_name,
           cpb.name as completed_by_name
    FROM vaccinations v
    JOIN flocks f ON f.id = v.flock_id
    LEFT JOIN users cb  ON cb.id  = v.created_by
    LEFT JOIN users cpb ON cpb.id = v.completed_by
    $where
    ORDER BY
        CASE v.status WHEN 'overdue' THEN 1 WHEN 'scheduled' THEN 2 ELSE 3 END,
        v.next_due_date ASC,
        v.date_given DESC
    LIMIT 200
");
$stmt->execute($params);
$vaccinations = $stmt->fetchAll();

$flocks = $pdo->query("SELECT * FROM flocks WHERE status='active' ORDER BY flock_name")->fetchAll();

$overdueCount   = $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE status='overdue'")->fetchColumn();
$scheduledCount = $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE status='scheduled'")->fetchColumn();
$completedCount = $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE status='completed'")->fetchColumn();
$todayCount     = $pdo->query("SELECT COUNT(*) FROM vaccinations WHERE DATE(next_due_date)=CURDATE() AND status IN ('scheduled','overdue')")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Vaccinations — Coopers Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../public/css/admin.css">
  <style>
    .row-overdue { background:#fff5f5 !important; }
    .row-today   { background:#fffbf0 !important; }
    .row-scheduled { background:#f0fdf4 !important; }
    .next-due-urgent { color:#ef4444; font-weight:700; }
    .next-due-today  { color:#e87722; font-weight:700; }
    .next-due-soon   { color:#fbbf24; font-weight:700; }
    .next-due-ok     { color:#4ade80; }
    .action-group { display:flex; gap:5px; flex-wrap:wrap; }
  </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
  <?php include 'includes/topbar.php'; ?>
  <div class="dashboard-content">

    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
      <div>
        <h1>Vaccination Tracker</h1>
        <p>Overdue vaccinations are automatically detected on every page load.</p>
      </div>
      <button class="btn btn-primary" onclick="document.getElementById('addVaccModal').classList.add('open')">
        <i class="fas fa-plus"></i> Record Vaccination
      </button>
    </div>

    <?php if($urlMsg): [$t,$tx] = explode(':',$urlMsg,2); ?>
    <div class="alert alert-<?php echo $t; ?>"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($tx); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
      <div class="stat-box">
        <div class="stat-header"><span>Overdue</span><div class="stat-icon-small red"><i class="fas fa-exclamation-triangle"></i></div></div>
        <h2 style="color:<?php echo $overdueCount>0?'#ef4444':'inherit'; ?>"><?php echo $overdueCount; ?></h2>
        <p>Past due date — act now</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Due Today</span><div class="stat-icon-small orange"><i class="fas fa-clock"></i></div></div>
        <h2 style="color:<?php echo $todayCount>0?'#e87722':'inherit'; ?>"><?php echo $todayCount; ?></h2>
        <p>Administer today</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Upcoming</span><div class="stat-icon-small blue"><i class="fas fa-calendar"></i></div></div>
        <h2><?php echo $scheduledCount; ?></h2>
        <p>Scheduled future doses</p>
      </div>
      <div class="stat-box">
        <div class="stat-header"><span>Completed</span><div class="stat-icon-small green"><i class="fas fa-check-circle"></i></div></div>
        <h2><?php echo $completedCount; ?></h2>
        <p>All-time administered</p>
      </div>
    </div>

    <!-- Info box explaining Sync Overdue -->
    <?php if($overdueCount > 0): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
      <i class="fas fa-skull-crossbones"></i>
      <strong><?php echo $overdueCount; ?> vaccination(s) overdue!</strong>
      Administer them now or reschedule using the <strong>Reschedule</strong> button. Missing vaccinations risks disease outbreaks.
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-body" style="padding:14px 20px;">
        <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <select name="flock_id" class="form-control" style="width:200px;" onchange="this.form.submit()">
            <option value="">All Flocks</option>
            <?php foreach($flocks as $fl): ?>
            <option value="<?php echo $fl['id']; ?>" <?php echo $flockFilter==$fl['id']?'selected':''; ?>>
              <?php echo htmlspecialchars($fl['flock_name']); ?>
            </option>
            <?php endforeach; ?>
          </select>
          <select name="status" class="form-control" style="width:160px;" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="overdue"   <?php echo $statusFilter==='overdue'   ?'selected':''; ?>>🔴 Overdue</option>
            <option value="scheduled" <?php echo $statusFilter==='scheduled' ?'selected':''; ?>>🟡 Scheduled</option>
            <option value="completed" <?php echo $statusFilter==='completed' ?'selected':''; ?>>✅ Completed</option>
          </select>
          <?php if($flockFilter || $statusFilter): ?>
          <a href="vaccinations.php" class="btn btn-secondary btn-sm">Clear Filters</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Vaccination Records (<?php echo count($vaccinations); ?>)</h3></div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>Flock</th>
              <th>Vaccine</th>
              <th>Date Given</th>
              <th>Next Due Date</th>
              <th>Method</th>
              <th>Birds</th>
              <th>Administered By</th>
              <th>Recorded By</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($vaccinations as $v):
              $rowClass = '';
              $daysUntil = null;
              if ($v['next_due_date']) {
                  $daysUntil = (strtotime($v['next_due_date']) - strtotime(date('Y-m-d'))) / 86400;
              }
              if ($v['status'] === 'overdue') $rowClass = 'row-overdue';
              elseif ($v['status'] === 'scheduled' && $daysUntil === 0) $rowClass = 'row-today';
              elseif ($v['status'] === 'scheduled') $rowClass = 'row-scheduled';
            ?>
            <tr class="<?php echo $rowClass; ?>">
              <td>
                <strong><?php echo htmlspecialchars($v['flock_name']); ?></strong><br>
                <small style="color:#888;"><?php echo ucfirst($v['bird_type']); ?></small>
              </td>
              <td><strong><?php echo htmlspecialchars($v['vaccine_name']); ?></strong></td>
              <td>
                <?php echo $v['date_given'] && $v['date_given'] !== '0000-00-00'
                    ? date('M d, Y', strtotime($v['date_given']))
                    : '<span style="color:#aaa;">—</span>'; ?>
              </td>
              <td>
                <?php if ($v['next_due_date']): ?>
                  <?php
                    $cls = 'next-due-ok';
                    $suffix = '';
                    if ($daysUntil < 0) {
                        $cls = 'next-due-urgent';
                        $suffix = ' (' . abs((int)$daysUntil) . 'd overdue)';
                    } elseif ($daysUntil == 0) {
                        $cls = 'next-due-today';
                        $suffix = ' (Today!)';
                    } elseif ($daysUntil <= 3) {
                        $cls = 'next-due-soon';
                        $suffix = ' (in ' . (int)$daysUntil . 'd)';
                    }
                  ?>
                  <span class="<?php echo $cls; ?>">
                    <?php echo date('M d, Y', strtotime($v['next_due_date'])); ?>
                    <?php echo $suffix; ?>
                  </span>
                <?php else: ?>
                  <span style="color:#aaa;">—</span>
                <?php endif; ?>
              </td>
              <td><?php echo ucwords(str_replace('_',' ',$v['method'])); ?></td>
              <td><?php echo $v['birds_vaccinated'] ? number_format($v['birds_vaccinated']) : '—'; ?></td>
              <td><?php echo htmlspecialchars($v['administered_by'] ?? '—'); ?></td>
              <td>
                <span style="font-size:12px;color:#888;">
                  <?php if($v['completed_by_name']): ?>
                    ✅ <?php echo htmlspecialchars($v['completed_by_name']); ?>
                  <?php elseif($v['created_by_name']): ?>
                    📝 <?php echo htmlspecialchars($v['created_by_name']); ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </span>
              </td>
              <td>
                <span class="status-badge badge-<?php echo $v['status']; ?>">
                  <?php echo ucfirst($v['status']); ?>
                </span>
              </td>
              <td>
                <div class="action-group">
                  <?php if(in_array($v['status'],['scheduled','overdue'])): ?>
                  <button class="btn btn-sm btn-primary"
                    onclick="openCompleteModal(<?php echo $v['id']; ?>,'<?php echo htmlspecialchars($v['vaccine_name'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($v['flock_name'],ENT_QUOTES); ?>',<?php echo $v['flock_id']; ?>)"
                    title="Mark as done">
                    <i class="fas fa-check"></i> Done
                  </button>
                  <button class="btn btn-sm btn-secondary"
                    onclick="openRescheduleModal(<?php echo $v['id']; ?>,'<?php echo htmlspecialchars($v['vaccine_name'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($v['flock_name'],ENT_QUOTES); ?>','<?php echo $v['next_due_date'] ?? ''; ?>')"
                    title="Reschedule">
                    <i class="fas fa-calendar-alt"></i>
                  </button>
                  <?php else: ?>
                  <span style="font-size:12px;color:#4ade80;">
                    <i class="fas fa-check-circle"></i> Done
                  </span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($vaccinations)): ?>
            <tr><td colspan="10" style="text-align:center;color:#888;padding:36px;">
              <i class="fas fa-syringe" style="font-size:32px;color:#e5e7eb;margin-bottom:10px;display:block;"></i>
              No vaccination records found.
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── ADD VACCINATION MODAL ──────────────────────────────── -->
<div class="modal-overlay" id="addVaccModal">
  <div class="modal" style="max-width:660px;">
    <div class="modal-header">
      <h3><i class="fas fa-syringe" style="color:var(--primary);margin-right:8px;"></i>Record Vaccination</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_vaccination" value="1">
      <div class="form-row">
        <div class="form-group">
          <label>Flock *</label>
          <select name="flock_id" class="form-control" required>
            <option value="">Select flock...</option>
            <?php foreach($flocks as $fl): ?>
            <option value="<?php echo $fl['id']; ?>" <?php echo $flockFilter==$fl['id']?'selected':''; ?>>
              <?php echo htmlspecialchars($fl['flock_name']); ?> (<?php echo ucfirst($fl['bird_type']); ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Vaccine Name *</label>
          <input type="text" name="vaccine_name" class="form-control" required placeholder="e.g. Newcastle Disease">
          <small style="color:#888;">Common: Newcastle, Gumboro, Marek's, Fowl Pox, Lasota</small>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Date Given *</label>
          <input type="date" name="date_given" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-group">
          <label>Next Due Date <small style="color:#888;">(creates scheduled reminder)</small></label>
          <input type="date" name="next_due_date" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Dosage</label>
          <input type="text" name="dosage" class="form-control" placeholder="e.g. 1 drop per bird">
        </div>
        <div class="form-group">
          <label>Method</label>
          <select name="method" class="form-control">
            <option value="drinking_water">Drinking Water</option>
            <option value="injection">Injection</option>
            <option value="eye_drop">Eye Drop</option>
            <option value="spray">Spray</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Birds Vaccinated</label>
          <input type="number" name="birds_vaccinated" class="form-control" min="0">
        </div>
        <div class="form-group">
          <label>Administered By</label>
          <input type="text" name="administered_by" class="form-control" value="<?php echo htmlspecialchars($_SESSION['name']); ?>" placeholder="Name or vet">
        </div>
      </div>
      <div class="form-group">
        <label>Notes / Observations</label>
        <textarea name="notes" class="form-control" rows="2" placeholder="Any reactions, conditions, observations..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Record</button>
      </div>
    </form>
  </div>
</div>

<!-- ── MARK COMPLETE MODAL ────────────────────────────────── -->
<div class="modal-overlay" id="completeModal">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header">
      <h3><i class="fas fa-check-circle" style="color:#4ade80;margin-right:8px;"></i>Mark Vaccination as Completed</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="mark_complete" value="1">
      <input type="hidden" name="vacc_id" id="completeVaccId">
      <div style="background:#EDF5E8;border-radius:10px;padding:12px 16px;margin:0 32px 18px;">
        <strong id="completeVaccineName" style="font-size:15px;display:block;"></strong>
        <span style="font-size:13px;color:#555;">Flock: <strong id="completeFlockName"></strong></span>
      </div>
      <div style="padding:0 32px;">
        <div class="form-row">
          <div class="form-group">
            <label>Date Administered *</label>
            <input type="date" name="date_given" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="form-group">
            <label>Birds Vaccinated</label>
            <input type="number" name="birds_vaccinated" class="form-control" min="0" placeholder="Number of birds">
          </div>
        </div>
        <div class="form-group">
          <label>Administered By</label>
          <input type="text" name="administered_by" class="form-control" value="<?php echo htmlspecialchars($_SESSION['name']); ?>" placeholder="Vet or staff name">
        </div>
        <div class="form-group">
          <label>Next Due Date <small style="color:#888;">(optional — schedules the next dose)</small></label>
          <input type="date" name="next_due_date" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
        </div>
        <div class="form-group">
          <label>Notes / Observations</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Any reactions, missed birds..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Mark as Completed</button>
      </div>
    </form>
  </div>
</div>

<!-- ── RESCHEDULE MODAL ───────────────────────────────────── -->
<div class="modal-overlay" id="rescheduleModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header">
      <h3><i class="fas fa-calendar-alt" style="color:#e87722;margin-right:8px;"></i>Reschedule Vaccination</h3>
      <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="reschedule" value="1">
      <input type="hidden" name="vacc_id" id="reschedVaccId">
      <div style="background:#fff3cd;border-radius:10px;padding:12px 16px;margin:0 32px 18px;border:1px solid #ffd06b;">
        <strong id="reschedVaccineName" style="display:block;font-size:15px;"></strong>
        <span style="font-size:13px;">Flock: <strong id="reschedFlockName"></strong></span><br>
        <span style="font-size:12px;color:#856404;">
          Currently due: <strong id="reschedCurrentDate"></strong>
        </span>
      </div>
      <div style="padding:0 32px;">
        <div class="form-group">
          <label>New Due Date *</label>
          <input type="date" name="new_due_date" id="reschedNewDate" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-group">
          <label>Reason for Rescheduling</label>
          <textarea name="reason" class="form-control" rows="2" placeholder="e.g. Vet unavailable, birds sick, supply delay..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background:#e87722;"><i class="fas fa-calendar-check"></i> Reschedule</button>
      </div>
    </form>
  </div>
</div>

<script src="../public/js/admin.js"></script>
<script>
function openCompleteModal(id, vaccineName, flockName) {
  document.getElementById('completeVaccId').value = id;
  document.getElementById('completeVaccineName').textContent = vaccineName;
  document.getElementById('completeFlockName').textContent   = flockName;
  document.getElementById('completeModal').classList.add('open');
}

function openRescheduleModal(id, vaccineName, flockName, currentDate) {
  document.getElementById('reschedVaccId').value = id;
  document.getElementById('reschedVaccineName').textContent = vaccineName;
  document.getElementById('reschedFlockName').textContent   = flockName;
  document.getElementById('reschedCurrentDate').textContent =
    currentDate ? new Date(currentDate).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : 'Not set';
  // Default new date to tomorrow
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  document.getElementById('reschedNewDate').value = tomorrow.toISOString().split('T')[0];
  document.getElementById('rescheduleModal').classList.add('open');
}
</script>
</body>
</html>
