<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

$id = (int)($_GET['id'] ?? 0);
$medicine = null;
$batches = [];
$error = '';

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare(
        'SELECT m.id, m.name, m.generic_name, m.category, m.unit, m.location, m.description, m.low_stock_threshold, m.created_at, m.updated_at,
                COALESCE(SUM(CASE WHEN b.expiration_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS current_stock,
                COALESCE(SUM(CASE WHEN b.expiration_date < CURDATE() THEN b.quantity ELSE 0 END), 0) AS expired_stock,
                MIN(CASE WHEN b.expiration_date >= CURDATE() THEN b.expiration_date END) AS nearest_expiry
         FROM medicines m
         LEFT JOIN medicine_batches b ON b.medicine_id = m.id
         WHERE m.id = :id
         GROUP BY m.id, m.name, m.generic_name, m.category, m.unit, m.location, m.description, m.low_stock_threshold, m.created_at, m.updated_at
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $medicine = $stmt->fetch() ?: null;

    if ($medicine) {
        $batchStmt = $pdo->prepare(
            'SELECT batch_no, supplier, quantity, received_date, expiration_date, DATEDIFF(expiration_date, CURDATE()) AS days_left
             FROM medicine_batches
             WHERE medicine_id = :id
             ORDER BY expiration_date ASC, batch_no ASC'
        );
        $batchStmt->execute(['id' => $id]);
        $batches = $batchStmt->fetchAll();
    }
} catch (Throwable $exception) {
    error_log('Medicine view error: ' . $exception->getMessage());
    $error = 'Unable to load this medicine right now. Please try again later.';
}

if (!$medicine && !$error) {
    $_SESSION['flash_error'] = 'The requested medicine could not be found.';
    header('Location: medicine-inventory.php');
    exit;
}

$stock = (int)($medicine['current_stock'] ?? 0);
$threshold = (int)($medicine['low_stock_threshold'] ?? 0);
$expiredStock = (int)($medicine['expired_stock'] ?? 0);
if ($stock === 0) {
    $statusClass = 'red';
    $statusLabel = 'Out of stock';
} elseif ($stock <= $threshold) {
    $statusClass = 'yellow';
    $statusLabel = 'Low stock';
} else {
    $statusClass = 'green';
    $statusLabel = 'In stock';
}

$identity = session_identity();
render_page_start('Medicine Details', 'inventory', $identity['full_name'], unread_notifications_count());
?>
    <main class="content">
      <?php if ($flash !== ''): ?>
        <div class="alert success" role="status"><?php echo e($flash); ?></div>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
        <div class="alert" role="alert"><?php echo e($error); ?></div>
      <?php endif; ?>

      <div class="page-head">
        <div>
          <a class="link back-link" href="medicine-inventory.php"><?php echo icon('back'); ?> Back to Medicine Inventory</a>
          <h2 class="page-title"><?php echo e($medicine['name'] ?? 'Medicine'); ?></h2>
          <p class="muted page-sub"><?php echo e($medicine['generic_name'] !== '' ? $medicine['generic_name'] . ' · ' : ''); ?><?php echo e($medicine['category'] ?? ''); ?></p>
        </div>
        <?php if ($medicine): ?>
          <div class="page-actions">
            <a class="btn" href="edit-medicine.php?id=<?php echo $id; ?>">Edit</a>
            <form method="POST" action="delete-medicine.php" class="inline-form" onsubmit="return confirm('Delete this medicine and all of its batches? This cannot be undone.');">
              <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="id" value="<?php echo $id; ?>">
              <button type="submit" class="btn danger">Delete</button>
            </form>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($medicine): ?>
        <section class="panel">
          <div class="panel-head">
            <h2>Overview</h2>
            <span class="badge <?php echo $statusClass; ?>"><?php echo e($statusLabel); ?></span>
          </div>
          <div class="detail-grid">
            <div class="detail-item">
              <span class="detail-label">Generic Name</span>
              <span class="detail-value"><?php echo $medicine['generic_name'] !== '' ? e($medicine['generic_name']) : '—'; ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Usable Stock</span>
              <span class="detail-value"><?php echo number_format($stock); ?> <?php echo e($medicine['unit']); ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Minimum Stock Level</span>
              <span class="detail-value"><?php echo number_format($threshold); ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Expired Units</span>
              <span class="detail-value"><?php echo number_format($expiredStock); ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Nearest Expiry</span>
              <span class="detail-value">
                <?php echo $medicine['nearest_expiry'] ? date('M j, Y', strtotime((string)$medicine['nearest_expiry'])) : '—'; ?>
              </span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Storage Location</span>
              <span class="detail-value"><?php echo $medicine['location'] !== '' ? e($medicine['location']) : '—'; ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Date Added</span>
              <span class="detail-value"><?php echo date('M j, Y', strtotime((string)$medicine['created_at'])); ?></span>
            </div>
            <?php if ($medicine['description'] !== ''): ?>
              <div class="detail-item full">
                <span class="detail-label">Description</span>
                <span class="detail-value"><?php echo e($medicine['description']); ?></span>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="panel" id="batches">
          <div class="panel-head">
            <h2>Batches</h2>
            <span class="badge teal"><?php echo number_format(count($batches)); ?> batch<?php echo count($batches) === 1 ? '' : 'es'; ?></span>
          </div>
          <?php if (!$batches): ?>
            <div class="empty-state">
              <span class="empty-icon"><?php echo icon('box'); ?></span>
              <h3>No batches recorded</h3>
              <p>Stock In entries for this medicine will appear here.</p>
              <a class="btn primary" href="stock-in.php"><?php echo icon('stock-in'); ?> Record Stock In</a>
            </div>
          <?php else: ?>
            <div class="table-wrap">
              <table class="data">
                <thead>
                  <tr><th>Batch Name</th><th>Quantity</th><th>Received</th><th>Expiration</th><th>Days Left</th><th>Supplier</th><th>Status</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($batches as $batch): ?>
                    <?php
                      $days = (int)$batch['days_left'];
                      if ($days < 0) {
                          $batchClass = 'red';
                          $batchLabel = 'Expired';
                      } elseif ($days <= 30) {
                          $batchClass = 'yellow';
                          $batchLabel = 'Expiring soon';
                      } else {
                          $batchClass = 'green';
                          $batchLabel = 'Normal';
                      }
                    ?>
                    <tr>
                      <td class="strong"><?php echo e((string)$batch['batch_no']); ?></td>
                      <td class="strong"><?php echo number_format((int)$batch['quantity']); ?></td>
                      <td><?php echo date('M j, Y', strtotime((string)$batch['received_date'])); ?></td>
                      <td><?php echo date('M j, Y', strtotime((string)$batch['expiration_date'])); ?></td>
                      <td><?php echo $days < 0 ? abs($days) . ' days ago' : $days . ' days'; ?></td>
                      <td><?php echo $batch['supplier'] !== '' ? e((string)$batch['supplier']) : '<span class="muted">&mdash;</span>'; ?></td>
                      <td><span class="badge <?php echo $batchClass; ?>"><?php echo e($batchLabel); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </main>
<?php render_page_end(); ?>
