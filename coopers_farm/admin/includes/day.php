<?php
// Start and end of day. Nothing is logged unless a day is open.
// Included by every page that saves data.

function currentDayRow(PDO $pdo): ?array {
    $s = $pdo->query("SELECT * FROM day_log WHERE status='open' ORDER BY id DESC LIMIT 1");
    return $s->fetch() ?: null;
}

// True when the system date's day has already been started and ended. Each date runs once.
function dayEndedToday(PDO $pdo): bool {
    return (bool)$pdo->query("SELECT COUNT(*) FROM day_log WHERE day_date = CURDATE() AND status = 'closed'")->fetchColumn();
}

// Deliveries that are not yet delivered or cancelled. The day cannot end while any are open.
function dayPendingDeliveries(PDO $pdo): array {
    return $pdo->query("SELECT id, order_number, customer_name, customer_phone, total_amount, order_status, created_at
        FROM orders WHERE order_type = 'delivery' AND order_status NOT IN ('delivered','cancelled')
        ORDER BY created_at")->fetchAll();
}

function dayIsOpen(PDO $pdo): bool {
    return currentDayRow($pdo) !== null;
}

// Refuse a save while the day is closed. Background requests get JSON, other requests are redirected back.
function dayBlocked(bool $json = false): void {
    $m = 'The day has not been started. Ask an admin to start the day before logging anything.';
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $m]);
        exit;
    }
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?msg=' . urlencode('error:' . $m));
    exit;
}

// Call on every page that saves data, straight after requireAdmin()
function requireDayIfPost(PDO $pdo): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (dayIsOpen($pdo)) return;
    $json = isset($_POST['ajax']) || isset($_POST['complete_sale']) || isset($_POST['inline_stock']) || isset($_POST['toggle_active']);
    dayBlocked($json);
}

// ── End-of-day report ─────────────────────────────────────────
// Everything logged since the day was started, built from the records themselves.
function dayRows(PDO $pdo, string $sql, array $params = []): array {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

function buildDayReport(PDO $pdo, array $day): array {
    $since = $day['started_at'];
    $sinceDate = substr($since, 0, 10);
    $starter = dayRows($pdo, "SELECT name FROM users WHERE id=?", [(int)$day['started_by']]);

    return [
        'meta' => [
            'day_date'   => $day['day_date'],
            'started_at' => $since,
            'ended_at'   => date('Y-m-d H:i:s'),
            'started_by' => $starter[0]['name'] ?? '—',
            'ended_by'   => $_SESSION['name'] ?? 'admin',
        ],
        'eggs' => dayRows($pdo, "SELECT f.flock_name, COUNT(*) AS records, SUM(ep.quantity) AS eggs,
                SUM(ep.broken_eggs) AS broken, SUM(ep.rejected_eggs) AS rejected,
                SUM(COALESCE(ep.hens_present, f.quantity)) AS hens
            FROM egg_production ep JOIN flocks f ON f.id = ep.flock_id
            WHERE ep.created_at >= ? GROUP BY f.id, f.flock_name ORDER BY f.flock_name", [$since]),
        'crates' => dayRows($pdo, "SELECT p.name, SUM(pl.packs) AS packs, SUM(pl.eggs) AS eggs
            FROM egg_pack_log pl JOIN products p ON p.id = pl.product_id
            WHERE pl.created_at >= ? GROUP BY p.id, p.name ORDER BY p.name", [$since]),
        'stock_moves' => dayRows($pdo, "SELECT p.name, il.reason, SUM(il.change_amount) AS change_amt
            FROM inventory_logs il JOIN products p ON p.id = il.product_id
            WHERE il.created_at >= ? GROUP BY p.name, il.reason ORDER BY p.name, il.reason", [$since]),
        'sales_total' => dayRows($pdo, "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS amt
            FROM orders WHERE order_status='delivered' AND created_at >= ?", [$since]),
        'sales_by_payment' => dayRows($pdo, "SELECT payment_method, COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS amt
            FROM orders WHERE order_status='delivered' AND created_at >= ? GROUP BY payment_method", [$since]),
        'sales_by_type' => dayRows($pdo, "SELECT COALESCE(order_type,'online') AS type, COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS amt
            FROM orders WHERE order_status='delivered' AND created_at >= ? GROUP BY type", [$since]),
        'orders' => dayRows($pdo, "SELECT order_status, COUNT(*) AS n FROM orders WHERE created_at >= ? GROUP BY order_status", [$since]),
        'mortality' => dayRows($pdo, "SELECT f.flock_name, ml.cause, SUM(ml.count) AS deaths
            FROM mortality_logs ml JOIN flocks f ON f.id = ml.flock_id
            WHERE ml.created_at >= ? GROUP BY f.flock_name, ml.cause ORDER BY f.flock_name", [$since]),
        'health' => dayRows($pdo, "SELECT COALESCE(disease_name,'Other') AS condition_name, COUNT(*) AS n, SUM(affected_count) AS birds
            FROM health_records WHERE created_at >= ? GROUP BY condition_name", [$since]),
        'vaccinations' => dayRows($pdo, "SELECT vaccine_name, COUNT(*) AS n, COALESCE(SUM(birds_vaccinated),0) AS birds
            FROM vaccinations WHERE status='completed' AND date_given >= ? GROUP BY vaccine_name", [$sinceDate]),
        'feed' => dayRows($pdo, "SELECT feed_type, SUM(quantity_kg) AS kg, SUM(cost) AS cost
            FROM feed_logs WHERE created_at >= ? GROUP BY feed_type", [$since]),
        'stock' => [
            'eggs'    => dayRows($pdo, "SELECT name, stock_quantity, egg_units FROM products WHERE category='eggs' AND (is_active=1 OR stock_quantity>0) ORDER BY name"),
            'batches' => dayRows($pdo, "SELECT flock_name, quantity FROM flocks WHERE status IN ('active','quarantine') ORDER BY flock_name"),
            'feed'    => dayRows($pdo, "SELECT feed_type, brand, quantity_kg FROM feed_stock ORDER BY feed_type"),
        ],
        'activity' => dayRows($pdo, "SELECT COALESCE(user_name,'System') AS user_name, COUNT(*) AS n
            FROM audit_logs WHERE created_at >= ? GROUP BY user_name ORDER BY n DESC", [$since]),
    ];
}
