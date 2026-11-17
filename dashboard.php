<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

function time_ago(string $timestamp): string
{
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int)floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (int)floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return (int)floor($diff / 86400) . 'd ago';
    }
    return date('M j', strtotime($timestamp));
}

$totalMedicines = 0;
$totalUnits = 0;
$lowStock = [];
$expiredBatches = 0;
$expiringBatches = 0;
$normalBatches = 0;
$totalBatches = 0;
$expiringRows = [];
$stockRows = [];
$maxStock = 1;
$activities = [];
$unread = 0;
$dbError = '';

try {
    $pdo = get_db_connection();

    $totalMedicines = (int)$pdo->query('SELECT COUNT(*) FROM medicines')->fetchColumn();

    $medStmt = $pdo->query(
        'SELECT m.id, m.name, m.category, m.unit, m.low_stock_threshold,
                COALESCE(SUM(CASE WHEN b.expiration_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS current_stock
         FROM medicines m
         LEFT JOIN medicine_batches b ON b.medicine_id = m.id
         GROUP BY m.id, m.name, m.category, m.unit, m.low_stock_threshold
         ORDER BY m.name'
    );
    $medicines = $medStmt->fetchAll();

    $lowStock = [];
    foreach ($medicines as $medicine) {
        $stock = (int)$medicine['current_stock'];
        $totalUnits += $stock;
        if ($stock <= (int)$medicine['low_stock_threshold']) {
            $lowStock[] = $medicine;
        }
    }
    usort($lowStock, static fn(array $a, array $b): int => (int)$a['current_stock'] <=> (int)$b['current_stock']);

    $expiredBatches = (int)$pdo->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date < CURDATE()')->fetchColumn();
    $expiringBatches = (int)$pdo->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetchColumn();
    $normalBatches = (int)$pdo->query('SELECT COUNT(*) FROM medicine_batches WHERE expiration_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetchColumn();
    $totalBatches = $expiredBatches + $expiringBatches + $normalBatches;

    $expiringRows = $pdo->query(
        'SELECT m.name, m.unit, m.location, b.batch_no, b.quantity, b.expiration_date,
                DATEDIFF(b.expiration_date, CURDATE()) AS days_left
         FROM medicine_batches b
         JOIN medicines m ON m.id = b.medicine_id
         WHERE b.expiration_date >= CURDATE() AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY b.expiration_date ASC
         LIMIT 8'
    )->fetchAll();

    $stockRows = $medicines;
    usort($stockRows, static fn(array $a, array $b): int => (int)$b['current_stock'] <=> (int)$a['current_stock']);
    $stockRows = array_slice($stockRows, 0, 6);
    foreach ($stockRows as $row) {
        $maxStock = max($maxStock, (int)$row['current_stock']);
    }

    $activities = $pdo->query(
        'SELECT a.activity_type, a.quantity, a.note, a.performed_by, a.created_at, m.name
         FROM inventory_activities a
         LEFT JOIN medicines m ON m.id = a.medicine_id
         ORDER BY a.created_at DESC
         LIMIT 8'
    )->fetchAll();

    generate_inventory_alerts($pdo);
    $unread = (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
} catch (Throwable $exception) {
    error_log('Dashboard error: ' . $exception->getMessage());
    $dbError = 'Unable to load dashboard data right now. Please refresh or try again later.';
}

$fullName = (string)($_SESSION['full_name'] ?? 'Clinic Personnel');

$percent = static function (int $part, int $total): float {
    return $total > 0 ? ($part / $total) * 100 : 0.0;
};

$activityMeta = [
    'stock_in' => ['label' => 'Stock In', 'icon' => 'stock-in', 'class' => 'teal'],
    'stock_out' => ['label' => 'Stock Out', 'icon' => 'stock-out', 'class' => 'yellow'],
    'add_medicine' => ['label' => 'New Medicine', 'icon' => 'plus', 'class' => 'green'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'expired', 'class' => 'red'],
    'update_medicine' => ['label' => 'Updated', 'icon' => 'medicine', 'class' => 'teal'],
    'delete_medicine' => ['label' => 'Deleted', 'icon' => 'expired', 'class' => 'red'],
];

render_page_start('Dashboard', 'dashboard', $fullName, $unread);
?>
    <main class="content">
      <?php if ($dbError !== ''): ?>
        <div class="alert" role="alert"><?php echo e($dbError); ?></div>
      <?php endif; ?>

      <section class="stat-grid" aria-label="Inventory summary">
        <a class="stat-card" href="#stock-overview">
          <div class="stat-head">
            <span class="stat-label">Total Medicines</span>
            <span class="stat-icon"><?php echo icon('medicine'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format($totalMedicines); ?></span>
          <span class="stat-hint">Registered medicine items</span>
        </a>

        <a class="stat-card" href="#stock-overview">
          <div class="stat-head">
            <span class="stat-label">Total Stock Units</span>
            <span class="stat-icon"><?php echo icon('box'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format($totalUnits); ?></span>
          <span class="stat-hint">Usable units (non-expired)</span>
        </a>

        <a class="stat-card" href="#low-stock">
          <div class="stat-head">
            <span class="stat-label">Low Stock Medicines</span>
            <span class="stat-icon warn"><?php echo icon('alert'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format(count($lowStock)); ?></span>
          <span class="stat-hint">At or below threshold</span>
        </a>

        <a class="stat-card" href="#expiration-overview">
          <div class="stat-head">
            <span class="stat-label">Expired Batches</span>
            <span class="stat-icon danger"><?php echo icon('expired'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format($expiredBatches); ?></span>
          <span class="stat-hint">Ready for disposal</span>
        </a>

        <a class="stat-card" href="#expiring-soon">
          <div class="stat-head">
            <span class="stat-label">Expiring Soon</span>
            <span class="stat-icon warn"><?php echo icon('clock'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format($expiringBatches); ?></span>
          <span class="stat-hint">Within the next 30 days</span>
        </a>
      </section>

      <section class="quick-actions" aria-label="Quick actions">
        <a class="btn primary" href="add-medicine.php"><?php echo icon('plus'); ?>Add Medicine</a>
        <a class="btn" href="stock-in.php"><?php echo icon('stock-in'); ?>Stock In</a>
        <a class="btn" href="stock-out.php"><?php echo icon('stock-out'); ?>Stock Out</a>
        <a class="btn" href="reports.php"><?php echo icon('reports'); ?>View Reports</a>
      </section>

      <div class="grid-2">
        <section class="panel" id="expiration-overview">
          <div class="panel-head">
            <h2>Expiration Overview</h2>
            <span class="badge teal"><?php echo number_format($totalBatches); ?> batches</span>
          </div>
          <div class="exp-bar" role="img" aria-label="Expiration status of all batches">
            <span class="exp-safe" style="width:<?php echo number_format($percent($normalBatches, $totalBatches), 1, '.', ''); ?>%"></span>
            <span class="exp-soon" style="width:<?php echo number_format($percent($expiringBatches, $totalBatches), 1, '.', ''); ?>%"></span>
            <span class="exp-expired" style="width:<?php echo number_format($percent($expiredBatches, $totalBatches), 1, '.', ''); ?>%"></span>
          </div>
          <ul class="legend">
            <li><i class="dot green"></i>Normal <strong><?php echo number_format($normalBatches); ?></strong></li>
            <li><i class="dot yellow"></i>Expiring soon <strong><?php echo number_format($expiringBatches); ?></strong></li>
            <li><i class="dot red"></i>Expired <strong><?php echo number_format($expiredBatches); ?></strong></li>
          </ul>
        </section>

        <section class="panel" id="stock-overview">
          <div class="panel-head">
            <h2>Medicine Stock Overview</h2>
            <span class="badge teal">Top <?php echo count($stockRows); ?> by stock</span>
          </div>
          <?php if (!$stockRows): ?>
            <p class="empty">No medicines found.</p>
          <?php else: ?>
            <div class="stock-list">
              <?php foreach ($stockRows as $row): ?>
                <?php
                  $stock = (int)$row['current_stock'];
                  $isLow = $stock <= (int)$row['low_stock_threshold'];
                  $width = $maxStock > 0 ? max(3, ($stock / $maxStock) * 100) : 0;
                ?>
                <div class="stock-row">
                  <span class="stock-name"><?php echo e($row['name']); ?></span>
                  <span class="stock-qty"><?php echo number_format($stock); ?> <?php echo e($row['unit']); ?></span>
                  <span class="stock-track"><span class="stock-fill <?php echo $isLow ? 'low' : 'ok'; ?>" style="width:<?php echo number_format($width, 1, '.', ''); ?>%"></span></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>

      <div class="grid-2">
        <section class="panel" id="expiring-soon">
          <div class="panel-head">
            <h2>Medicines Nearing Expiration</h2>
            <span class="badge yellow"><?php echo number_format($expiringBatches); ?> within 30 days</span>
          </div>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>Medicine</th><th>Location</th><th>Qty</th><th>Expiry</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php if (!$expiringRows): ?>
                  <tr><td colspan="5" class="empty">No batches expiring within 30 days.</td></tr>
                <?php else: ?>
                  <?php foreach ($expiringRows as $row): ?>
                    <?php $days = (int)$row['days_left']; ?>
                    <tr>
                      <td class="strong"><?php echo e($row['name']); ?></td>
                      <td><?php echo $row['location'] !== '' ? e($row['location']) : '<span class="muted">&mdash;</span>'; ?></td>
                      <td><?php echo number_format((int)$row['quantity']); ?></td>
                      <td><?php echo date('M j, Y', strtotime($row['expiration_date'])); ?></td>
                      <td><span class="badge yellow"><?php echo $days === 0 ? 'Expires today' : $days . ' days left'; ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="panel" id="low-stock">
          <div class="panel-head">
            <h2>Low Stock Medicines</h2>
            <span class="badge red"><?php echo number_format(count($lowStock)); ?> need restocking</span>
          </div>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>Medicine</th><th>Category</th><th>Stock</th><th>Threshold</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php if (!$lowStock): ?>
                  <tr><td colspan="5" class="empty">All medicines are above their thresholds.</td></tr>
                <?php else: ?>
                  <?php foreach (array_slice($lowStock, 0, 8) as $row): ?>
                    <?php $stock = (int)$row['current_stock']; ?>
                    <tr>
                      <td class="strong"><?php echo e($row['name']); ?></td>
                      <td><?php echo e($row['category']); ?></td>
                      <td><?php echo number_format($stock) . ' ' . e($row['unit']); ?></td>
                      <td><?php echo number_format((int)$row['low_stock_threshold']); ?></td>
                      <td>
                        <?php if ($stock === 0): ?>
                          <span class="badge red">Out of stock</span>
                        <?php else: ?>
                          <span class="badge red">Low stock</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <section class="panel" id="recent-activity">
        <div class="panel-head">
          <h2>Recent Inventory Activities</h2>
          <span class="badge teal">Latest updates</span>
        </div>
        <?php if (!$activities): ?>
          <p class="empty">No inventory activities recorded yet.</p>
        <?php else: ?>
          <ul class="activity-list">
            <?php foreach ($activities as $activity): ?>
              <?php
                $type = (string)$activity['activity_type'];
                $meta = $activityMeta[$type] ?? ['label' => ucfirst(str_replace('_', ' ', $type)), 'icon' => 'box', 'class' => 'teal'];
                $qty = (int)$activity['quantity'];
                $qtyLabel = $qty > 0 ? '+' . number_format($qty) : number_format($qty);
              ?>
              <li class="activity">
                <span class="activity-icon <?php echo e($meta['class']); ?>"><?php echo icon($meta['icon']); ?></span>
                <div class="activity-main">
                  <div class="activity-title"><?php echo e($meta['label']); ?><?php echo $activity['name'] ? ' - ' . e($activity['name']) : ''; ?><?php if ($qty !== 0): ?> <span class="qty"><?php echo $qtyLabel; ?></span><?php endif; ?></div>
                  <div class="activity-meta"><?php echo e($activity['note'] ?? ''); ?> &middot; by <?php echo e($activity['performed_by']); ?></div>
                </div>
                <span class="activity-time"><?php echo time_ago((string)$activity['created_at']); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </main>
<?php render_page_end(); ?>
