<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

$search = trim((string)($_GET['search'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'expired', 'soon', 'valid'], true)) {
    $status = 'all';
}

$batches = [];
$summary = ['total' => 0, 'expired' => 0, 'soon' => 0, 'monitor' => 0, 'valid' => 0];
$dbError = '';

try {
    $pdo = get_db_connection();

    $summary = $pdo->query(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(expiration_date < CURDATE()), 0) AS expired,
                COALESCE(SUM(expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0) AS soon,
                COALESCE(SUM(expiration_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)), 0) AS monitor,
                COALESCE(SUM(expiration_date > DATE_ADD(CURDATE(), INTERVAL 90 DAY)), 0) AS valid
         FROM medicine_batches'
    )->fetch() ?: $summary;

    $sql = 'SELECT b.id, b.medicine_id, b.batch_no, b.quantity, b.received_date, b.expiration_date, b.supplier,
                   m.name, m.unit,
                   DATEDIFF(b.expiration_date, CURDATE()) AS days_left
            FROM medicine_batches b
            JOIN medicines m ON m.id = b.medicine_id';
    $where = [];
    $params = [];

    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(m.name LIKE :search_name OR b.batch_no LIKE :search_batch)';
        $params['search_name'] = $like;
        $params['search_batch'] = $like;
    }

    if ($status === 'expired') {
        $where[] = 'b.expiration_date < CURDATE()';
    } elseif ($status === 'soon') {
        $where[] = 'b.expiration_date >= CURDATE() AND b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
    } elseif ($status === 'valid') {
        $where[] = 'b.expiration_date >= CURDATE()';
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY b.expiration_date ASC, m.name ASC LIMIT 100';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $batches = $stmt->fetchAll();
} catch (Throwable $exception) {
    error_log('Expiration load error: ' . $exception->getMessage());
    $dbError = 'Unable to load expiration data right now. Please try again later.';
}

$identity = session_identity();
render_page_start('Expiration Monitoring', 'expiration', $identity['full_name'], unread_notifications_count(), 'Track expired and soon-to-expire medicine batches.');
?>
    <main class="content">
      <?php if ($dbError !== ''): ?>
        <div class="alert" role="alert"><?php echo e($dbError); ?></div>
      <?php endif; ?>

      <section class="stat-grid" aria-label="Expiration summary">
        <div class="stat-card">
          <div class="stat-head">
            <span class="stat-label">Total Batches</span>
            <span class="stat-icon"><?php echo icon('box'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format((int)$summary['total']); ?></span>
          <span class="stat-hint">All recorded batches</span>
        </div>

        <div class="stat-card">
          <div class="stat-head">
            <span class="stat-label">Expired</span>
            <span class="stat-icon danger"><?php echo icon('expired'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format((int)$summary['expired']); ?></span>
          <span class="stat-hint">Past expiration date</span>
        </div>

        <div class="stat-card">
          <div class="stat-head">
            <span class="stat-label">Within 30 Days</span>
            <span class="stat-icon warn"><?php echo icon('clock'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format((int)$summary['soon']); ?></span>
          <span class="stat-hint">Expiring soon</span>
        </div>

        <div class="stat-card">
          <div class="stat-head">
            <span class="stat-label">Within 90 Days</span>
            <span class="stat-icon warn"><?php echo icon('clock'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format((int)$summary['monitor']); ?></span>
          <span class="stat-hint">Needs monitoring</span>
        </div>

        <div class="stat-card">
          <div class="stat-head">
            <span class="stat-label">Valid</span>
            <span class="stat-icon"><?php echo icon('medicine'); ?></span>
          </div>
          <span class="stat-value"><?php echo number_format((int)$summary['valid']); ?></span>
          <span class="stat-hint">More than 90 days left</span>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2>Batch Expiration List</h2>
          <span class="badge teal"><?php echo number_format(count($batches)); ?> shown</span>
        </div>

        <form class="inventory-toolbar" method="GET" action="expiration.php">
          <div class="search-field">
            <?php echo icon('search'); ?>
            <input type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search medicine or batch number">
          </div>
          <select name="status" class="filter-select">
            <option value="all"<?php echo $status === 'all' ? ' selected' : ''; ?>>All batches</option>
            <option value="expired"<?php echo $status === 'expired' ? ' selected' : ''; ?>>Expired</option>
            <option value="soon"<?php echo $status === 'soon' ? ' selected' : ''; ?>>Expiring within 30 days</option>
            <option value="valid"<?php echo $status === 'valid' ? ' selected' : ''; ?>>Not expired</option>
          </select>
          <button type="submit" class="btn primary">Apply</button>
          <a class="btn" href="expiration.php">Reset</a>
        </form>

        <?php if (!$batches): ?>
          <div class="empty-state">
            <span class="empty-icon"><?php echo icon('clock'); ?></span>
            <h3><?php echo $search !== '' || $status !== 'all' ? 'No batches match your filters' : 'No batches recorded yet'; ?></h3>
            <p><?php echo $search !== '' || $status !== 'all' ? 'Try a different search term or status filter.' : 'Batches added through Stock In will appear here with their expiration status.'; ?></p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr>
                  <th>Medicine</th>
                  <th>Batch No.</th>
                  <th>Quantity</th>
                  <th>Received</th>
                  <th>Expiration</th>
                  <th>Days Left</th>
                  <th>Supplier</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($batches as $batch): ?>
                  <?php $days = (int)$batch['days_left']; ?>
                  <tr>
                    <td class="strong"><a class="table-link" href="medicine-view.php?id=<?php echo (int)$batch['medicine_id']; ?>#batches"><?php echo e((string)$batch['name']); ?></a></td>
                    <td><?php echo e((string)$batch['batch_no']); ?></td>
                    <td><?php echo number_format((int)$batch['quantity']); ?> <?php echo e((string)$batch['unit']); ?></td>
                    <td><?php echo date('M j, Y', strtotime((string)$batch['received_date'])); ?></td>
                    <td><?php echo date('M j, Y', strtotime((string)$batch['expiration_date'])); ?></td>
                    <td>
                      <?php if ($days < 0): ?>
                        <?php echo number_format(abs($days)); ?> days ago
                      <?php elseif ($days === 0): ?>
                        Today
                      <?php else: ?>
                        <?php echo number_format($days); ?> days
                      <?php endif; ?>
                    </td>
                    <td><?php echo $batch['supplier'] !== '' ? e((string)$batch['supplier']) : '<span class="muted">&mdash;</span>'; ?></td>
                    <td>
                      <?php if ($days < 0): ?>
                        <span class="badge red">Expired</span>
                      <?php elseif ($days <= 30): ?>
                        <span class="badge yellow">Expiring soon</span>
                      <?php elseif ($days <= 90): ?>
                        <span class="badge teal">Monitor</span>
                      <?php else: ?>
                        <span class="badge green">Valid</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </main>
<?php render_page_end(); ?>
