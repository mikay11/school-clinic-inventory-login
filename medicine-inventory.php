<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

$flash = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$error = '';
$medicines = [];
$search = trim((string)($_GET['q'] ?? ''));
$categoryFilter = trim((string)($_GET['category'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

try {
    $pdo = get_db_connection();
    $medicines = $pdo->query(
        'SELECT m.id, m.name, m.generic_name, m.category, m.unit, m.location, m.low_stock_threshold,
                COALESCE(SUM(CASE WHEN b.expiration_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS current_stock
         FROM medicines m
         LEFT JOIN medicine_batches b ON b.medicine_id = m.id
         GROUP BY m.id, m.name, m.generic_name, m.category, m.unit, m.location, m.low_stock_threshold
         ORDER BY m.name'
    )->fetchAll();

    $batchRows = $pdo->query(
        'SELECT medicine_id, batch_no
         FROM medicine_batches
         ORDER BY expiration_date ASC, batch_no ASC'
    )->fetchAll();
    $batchesByMedicine = [];
    foreach ($batchRows as $batchRow) {
        $batchesByMedicine[(int)$batchRow['medicine_id']][] = (string)$batchRow['batch_no'];
    }
    foreach ($medicines as $index => $medicine) {
        $medicines[$index]['batch_list'] = $batchesByMedicine[(int)$medicine['id']] ?? [];
    }
} catch (Throwable $exception) {
    error_log('Medicine inventory error: ' . $exception->getMessage());
    $error = 'Unable to load the medicine inventory right now. Please try again later.';
}

$categoryOptions = ['Tablet', 'Capsule', 'Syrup', 'Liquid', 'Ointment', 'Other'];
foreach ($medicines as $medicine) {
    if ($medicine['category'] !== '' && !in_array($medicine['category'], $categoryOptions, true)) {
        $categoryOptions[] = $medicine['category'];
    }
}

foreach ($medicines as $index => $medicine) {
    $stock = (int)$medicine['current_stock'];
    $threshold = (int)$medicine['low_stock_threshold'];
    if ($stock === 0) {
        $medicines[$index]['status_class'] = 'red';
        $medicines[$index]['status_label'] = 'Out of Stock';
        $medicines[$index]['status_key'] = 'out';
    } elseif ($stock <= $threshold) {
        $medicines[$index]['status_class'] = 'yellow';
        $medicines[$index]['status_label'] = 'Low Stock';
        $medicines[$index]['status_key'] = 'low';
    } else {
        $medicines[$index]['status_class'] = 'green';
        $medicines[$index]['status_label'] = 'In Stock';
        $medicines[$index]['status_key'] = 'in';
    }
}

$filtered = array_values(array_filter($medicines, static function (array $medicine) use ($search, $categoryFilter, $statusFilter): bool {
    if ($search !== '') {
        $haystack = $medicine['name'] . ' ' . $medicine['generic_name'] . ' ' . implode(' ', $medicine['batch_list']);
        if (mb_stripos($haystack, $search) === false) {
            return false;
        }
    }
    if ($categoryFilter !== '') {
        $matchesCategory = strcasecmp((string)$medicine['category'], $categoryFilter) === 0;
        $matchesUnit = strcasecmp((string)$medicine['unit'], $categoryFilter) === 0;
        if (!$matchesCategory && !$matchesUnit) {
            return false;
        }
    }
    if ($statusFilter !== '' && $medicine['status_key'] !== $statusFilter) {
        return false;
    }
    return true;
}));

$total = count($filtered);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($filtered, $offset, $perPage);
$firstRow = $total > 0 ? $offset + 1 : 0;
$lastRow = min($offset + $perPage, $total);
$hasFilters = $search !== '' || $categoryFilter !== '' || $statusFilter !== '';

$queryParams = [];
if ($search !== '') {
    $queryParams['q'] = $search;
}
if ($categoryFilter !== '') {
    $queryParams['category'] = $categoryFilter;
}
if ($statusFilter !== '') {
    $queryParams['status'] = $statusFilter;
}

$pageUrl = static function (int $target) use ($queryParams): string {
    $params = $queryParams;
    if ($target > 1) {
        $params['page'] = $target;
    }
    return 'medicine-inventory.php' . ($params ? '?' . http_build_query($params) : '');
};

$identity = session_identity();
render_page_start('Medicine Inventory', 'inventory', $identity['full_name'], unread_notifications_count(), 'Manage and monitor all medicines available in the school clinic.');
?>
    <main class="content">
      <?php if ($flash !== ''): ?>
        <div class="alert success" role="status"><?php echo e($flash); ?></div>
      <?php endif; ?>

      <?php if ($flashError !== ''): ?>
        <div class="alert" role="alert"><?php echo e($flashError); ?></div>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
        <div class="alert" role="alert"><?php echo e($error); ?></div>
      <?php endif; ?>

      <form class="inventory-toolbar" method="GET" action="medicine-inventory.php">
        <div class="search-field">
          <?php echo icon('search'); ?>
          <input type="search" name="q" value="<?php echo e($search); ?>" placeholder="Search medicine, generic name, or batch..." aria-label="Search medicine, generic name, or batch">
        </div>

        <select name="category" class="filter-select" aria-label="Filter by category">
          <option value="">All Categories</option>
          <?php foreach ($categoryOptions as $category): ?>
            <option value="<?php echo e($category); ?>"<?php echo strcasecmp($categoryFilter, $category) === 0 ? ' selected' : ''; ?>><?php echo e($category); ?></option>
          <?php endforeach; ?>
        </select>

        <select name="status" class="filter-select" aria-label="Filter by stock status">
          <option value="">All Stock Status</option>
          <option value="in"<?php echo $statusFilter === 'in' ? ' selected' : ''; ?>>In Stock</option>
          <option value="low"<?php echo $statusFilter === 'low' ? ' selected' : ''; ?>>Low Stock</option>
          <option value="out"<?php echo $statusFilter === 'out' ? ' selected' : ''; ?>>Out of Stock</option>
        </select>

        <button type="submit" class="btn">Apply</button>
        <?php if ($hasFilters): ?>
          <a class="btn" href="medicine-inventory.php">Reset</a>
        <?php endif; ?>

        <a class="btn primary toolbar-add" href="add-medicine.php"><?php echo icon('plus'); ?> Add Medicine</a>
      </form>

      <section class="panel">
        <?php if (!$medicines): ?>
          <div class="empty-state">
            <span class="empty-icon"><?php echo icon('medicine'); ?></span>
            <h3>No medicines registered</h3>
            <p>Start by adding your first medicine record to the inventory.</p>
            <a class="btn primary" href="add-medicine.php"><?php echo icon('plus'); ?> Add Medicine</a>
          </div>
        <?php elseif (!$pageRows): ?>
          <div class="empty-state">
            <span class="empty-icon"><?php echo icon('search'); ?></span>
            <h3>No medicines found</h3>
            <p>No medicine matches your search or filters. Try different keywords.</p>
            <a class="btn" href="medicine-inventory.php">Clear Filters</a>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data inventory-table">
              <thead>
                <tr>
                  <th>Medicine ID</th>
                  <th>Medicine Name</th>
                  <th>Generic Name</th>
                  <th>Category</th>
                  <th>Unit</th>
                  <th>Batch Names</th>
                  <th>Total Available Stock</th>
                  <th>Stock Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pageRows as $medicine): ?>
                  <tr>
                    <td class="muted">MED-<?php echo str_pad((string)$medicine['id'], 3, '0', STR_PAD_LEFT); ?></td>
                    <td class="strong"><a class="table-link" href="medicine-view.php?id=<?php echo (int)$medicine['id']; ?>"><?php echo e($medicine['name']); ?></a></td>
                    <td><?php echo $medicine['generic_name'] !== '' ? e($medicine['generic_name']) : '<span class="muted">&mdash;</span>'; ?></td>
                    <td><?php echo e($medicine['category']); ?></td>
                    <td><?php echo e(ucfirst($medicine['unit'])); ?></td>
                    <td>
                      <?php if (!$medicine['batch_list']): ?>
                        <span class="muted">&mdash;</span>
                      <?php else: ?>
                        <div class="batch-chips" title="<?php echo e(implode(', ', $medicine['batch_list'])); ?>">
                          <?php foreach (array_slice($medicine['batch_list'], 0, 2) as $batchName): ?>
                            <a class="batch-chip" href="medicine-view.php?id=<?php echo (int)$medicine['id']; ?>#batches"><?php echo e($batchName); ?></a>
                          <?php endforeach; ?>
                          <?php if (count($medicine['batch_list']) > 2): ?>
                            <span class="batch-more">+<?php echo count($medicine['batch_list']) - 2; ?> more</span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td class="strong"><?php echo number_format((int)$medicine['current_stock']); ?></td>
                    <td><span class="badge <?php echo $medicine['status_class']; ?>"><?php echo e($medicine['status_label']); ?></span></td>
                    <td>
                      <div class="row-actions">
                        <a class="btn btn-sm" href="medicine-view.php?id=<?php echo (int)$medicine['id']; ?>">View Details</a>
                        <a class="btn btn-sm" href="edit-medicine.php?id=<?php echo (int)$medicine['id']; ?>">Edit</a>
                        <form method="POST" action="delete-medicine.php" class="inline-form" onsubmit="return confirm('Delete this medicine and all of its batches? This cannot be undone.');">
                          <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                          <input type="hidden" name="id" value="<?php echo (int)$medicine['id']; ?>">
                          <button type="submit" class="btn btn-sm danger">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-row">
            <span class="muted small">Showing <?php echo number_format($firstRow); ?>&ndash;<?php echo number_format($lastRow); ?> of <?php echo number_format($total); ?> medicine<?php echo $total === 1 ? '' : 's'; ?></span>
            <?php if ($totalPages > 1): ?>
              <nav class="pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                  <a class="page-link" href="<?php echo e($pageUrl($page - 1)); ?>" aria-label="Previous page">&laquo; Prev</a>
                <?php else: ?>
                  <span class="page-link disabled">&laquo; Prev</span>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                  <?php if ($p === $page): ?>
                    <span class="page-link current" aria-current="page"><?php echo $p; ?></span>
                  <?php else: ?>
                    <a class="page-link" href="<?php echo e($pageUrl($p)); ?>"><?php echo $p; ?></a>
                  <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                  <a class="page-link" href="<?php echo e($pageUrl($page + 1)); ?>" aria-label="Next page">Next &raquo;</a>
                <?php else: ?>
                  <span class="page-link disabled">Next &raquo;</span>
                <?php endif; ?>
              </nav>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
<?php render_page_end(); ?>
