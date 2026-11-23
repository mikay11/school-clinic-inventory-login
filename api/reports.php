<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

$reportTypes = [
    'inventory' => 'Inventory Summary',
    'movement' => 'Stock Movement',
    'expiration' => 'Expiration Report',
    'low-stock' => 'Low Stock Report',
];

$reportType = (string)($_GET['report_type'] ?? 'inventory');
if (!isset($reportTypes[$reportType])) {
    $reportType = 'inventory';
}

$reportFormat = (string)($_GET['report_format'] ?? 'screen');
if (!in_array($reportFormat, ['screen', 'csv', 'print'], true)) {
    $reportFormat = 'screen';
}

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
if ($dateFrom !== '' && !DateTime::createFromFormat('Y-m-d', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !DateTime::createFromFormat('Y-m-d', $dateTo)) {
    $dateTo = '';
}

$summary = ['medicines' => 0, 'batches' => 0, 'usable_units' => 0, 'expired_batches' => 0];
$snapshot = [];
$reportRows = [];
$reportHeaders = [];
$dbError = '';

function stock_status(int $usable, int $threshold): string
{
    if ($usable <= 0) {
        return 'Out of stock';
    }
    if ($usable <= $threshold) {
        return 'Low stock';
    }
    return 'In stock';
}

try {
    $pdo = get_db_connection();

    $summary['medicines'] = (int)$pdo->query('SELECT COUNT(*) FROM medicines')->fetchColumn();
    $summary['batches'] = (int)$pdo->query('SELECT COUNT(*) FROM medicine_batches')->fetchColumn();
    $summary['usable_units'] = (int)$pdo->query(
        'SELECT COALESCE(SUM(quantity), 0) FROM medicine_batches WHERE expiration_date >= CURDATE()'
    )->fetchColumn();
    $summary['expired_batches'] = (int)$pdo->query(
        'SELECT COUNT(*) FROM medicine_batches WHERE expiration_date < CURDATE()'
    )->fetchColumn();

    $snapshot = $pdo->query(
        'SELECT m.name, m.category, m.unit, m.low_stock_threshold,
                COUNT(b.id) AS batch_count,
                COALESCE(SUM(b.quantity), 0) AS total_quantity,
                COALESCE(SUM(CASE WHEN b.expiration_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS usable_quantity
         FROM medicines m
         LEFT JOIN medicine_batches b ON b.medicine_id = m.id
         GROUP BY m.id, m.name, m.category, m.unit, m.low_stock_threshold
         ORDER BY m.name ASC
         LIMIT 10'
    )->fetchAll();

    if ($reportType === 'inventory' || $reportType === 'low-stock') {
        $sql = 'SELECT m.name, m.category, m.unit, m.low_stock_threshold,
                       COUNT(b.id) AS batch_count,
                       COALESCE(SUM(b.quantity), 0) AS total_quantity,
                       COALESCE(SUM(CASE WHEN b.expiration_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS usable_quantity
                FROM medicines m
                LEFT JOIN medicine_batches b ON b.medicine_id = m.id
                GROUP BY m.id, m.name, m.category, m.unit, m.low_stock_threshold';
        if ($reportType === 'low-stock') {
            $sql .= ' HAVING usable_quantity <= m.low_stock_threshold';
        }
        $sql .= ' ORDER BY m.name';

        $reportHeaders = ['Medicine', 'Category', 'Unit', 'Batches', 'Total Qty', 'Usable Qty', 'Min Level', 'Status'];
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $usable = (int)$row['usable_quantity'];
            $threshold = (int)$row['low_stock_threshold'];
            $reportRows[] = [
                $row['name'],
                $row['category'],
                $row['unit'],
                (int)$row['batch_count'],
                (int)$row['total_quantity'],
                $usable,
                $threshold,
                stock_status($usable, $threshold),
            ];
        }
    } elseif ($reportType === 'movement') {
        $conditions = [];
        $params = [];
        $conditions[] = 'a.medicine_id IS NOT NULL';
        if ($dateFrom !== '') {
            $conditions[] = 'a.created_at >= :from';
            $params['from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $conditions[] = 'a.created_at <= :to';
            $params['to'] = $dateTo . ' 23:59:59';
        }
        $sql = 'SELECT a.created_at, a.activity_type, COALESCE(m.name, \'-\') AS medicine,
                       a.quantity, a.note, a.performed_by
                FROM inventory_activities a
                LEFT JOIN medicines m ON m.id = a.medicine_id';
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY a.id DESC LIMIT 500';

        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $reportHeaders = ['Date', 'Type', 'Medicine', 'Quantity', 'Details', 'Recorded By'];
        foreach ($statement->fetchAll() as $row) {
            $reportRows[] = [
                date('Y-m-d H:i', strtotime((string)$row['created_at'])),
                ucwords(str_replace('_', ' ', (string)$row['activity_type'])),
                $row['medicine'],
                (int)$row['quantity'],
                (string)($row['note'] ?? ''),
                $row['performed_by'],
            ];
        }
    } else {
        $conditions = [];
        $params = [];
        if ($dateFrom !== '') {
            $conditions[] = 'b.expiration_date >= :from';
            $params['from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $conditions[] = 'b.expiration_date <= :to';
            $params['to'] = $dateTo;
        }
        $sql = 'SELECT m.name, b.batch_no, b.quantity, b.expiration_date,
                       DATEDIFF(b.expiration_date, CURDATE()) AS days_left, b.supplier
                FROM medicine_batches b
                JOIN medicines m ON m.id = b.medicine_id';
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY b.expiration_date ASC';

        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $reportHeaders = ['Medicine', 'Batch', 'Quantity', 'Expiry', 'Days Left', 'Supplier', 'Status'];
        foreach ($statement->fetchAll() as $row) {
            $days = (int)$row['days_left'];
            $status = $days < 0 ? 'Expired' : ($days <= 30 ? 'Expiring soon' : 'Valid');
            $reportRows[] = [
                $row['name'],
                $row['batch_no'],
                (int)$row['quantity'],
                $row['expiration_date'],
                $days,
                $row['supplier'],
                $status,
            ];
        }
    }
} catch (Throwable $exception) {
    error_log('Reports error: ' . $exception->getMessage());
    $dbError = 'Unable to load report data right now. Please try again later.';
}

$reportTitle = $reportTypes[$reportType] . ' - School Clinic';
$filterLabel = ($dateFrom !== '' || $dateTo !== '')
    ? 'Period: ' . ($dateFrom !== '' ? $dateFrom : 'beginning') . ' to ' . ($dateTo !== '' ? $dateTo : 'present')
    : 'All recorded data';

$queryFor = static function (string $format) use ($reportType, $dateFrom, $dateTo): string {
    return http_build_query([
        'report_type' => $reportType,
        'report_format' => $format,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);
};

if ($reportFormat === 'csv' && !$dbError) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clinic-report-' . $reportType . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $handle = fopen('php://output', 'w');
    fputcsv($handle, [$reportTitle]);
    fputcsv($handle, [$filterLabel]);
    fputcsv($handle, []);
    fputcsv($handle, $reportHeaders);
    foreach ($reportRows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
    exit;
}

if ($reportFormat === 'print' && !$dbError) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?php echo e($reportTitle); ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body { background: #fff; padding: 24px; }
    .print-sheet { max-width: 1000px; margin: 0 auto; }
    .print-sheet h1 { color: #176B70; margin-bottom: 4px; }
    .print-sheet p { color: #555; margin-top: 0; }
    .print-sheet table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .print-sheet th, .print-sheet td { border: 1px solid #ccc; padding: 7px 9px; text-align: left; font-size: 13px; }
    .print-sheet th { background: #eef6f6; }
    .print-actions { margin-top: 18px; }
    @media print { .print-actions { display: none; } body { padding: 0; } }
  </style>
</head>
<body>
  <div class="print-sheet">
    <h1><?php echo e($reportTitle); ?></h1>
    <p><?php echo e($filterLabel); ?> &middot; Generated <?php echo date('M j, Y g:i A'); ?></p>
    <table>
      <thead><tr><?php foreach ($reportHeaders as $heading): ?><th><?php echo e($heading); ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php if (!$reportRows): ?>
          <tr><td colspan="<?php echo count($reportHeaders); ?>">No records match the selected filters.</td></tr>
        <?php else: ?>
          <?php foreach ($reportRows as $row): ?>
            <tr><?php foreach ($row as $cell): ?><td><?php echo e((string)$cell); ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    <div class="print-actions">
      <button type="button" class="btn-primary" onclick="window.print()">Print</button>
      <a class="link" href="reports.php?<?php echo e($queryFor('screen')); ?>">Back to Reports</a>
    </div>
  </div>
  <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
    <?php
    exit;
}

$identity = session_identity();
render_page_start('Reports', 'reports', $identity['full_name'], unread_notifications_count(), 'Review inventory summaries and stock movement reports.');
?>
    <main class="content">
      <?php if ($dbError !== ''): ?>
        <div class="alert" role="alert"><?php echo e($dbError); ?></div>
      <?php endif; ?>

      <section class="panel form-panel">
        <div class="panel-head">
          <h2>Report Filters</h2>
        </div>
        <form class="form-grid" method="GET" action="reports.php" novalidate>
          <div class="field">
            <label for="report_type">Report Type</label>
            <select id="report_type" name="report_type">
              <?php foreach ($reportTypes as $key => $label): ?>
                <option value="<?php echo e($key); ?>"<?php echo $reportType === $key ? ' selected' : ''; ?>><?php echo e($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="report_format">Output Format</label>
            <select id="report_format" name="report_format">
              <option value="screen"<?php echo $reportFormat === 'screen' ? ' selected' : ''; ?>>On-screen table</option>
              <option value="csv"<?php echo $reportFormat === 'csv' ? ' selected' : ''; ?>>CSV file</option>
              <option value="print"<?php echo $reportFormat === 'print' ? ' selected' : ''; ?>>Printable page</option>
            </select>
          </div>
          <div class="field">
            <label for="date_from">Date From</label>
            <input type="date" id="date_from" name="date_from" value="<?php echo e($dateFrom); ?>">
          </div>
          <div class="field">
            <label for="date_to">Date To</label>
            <input type="date" id="date_to" name="date_to" value="<?php echo e($dateTo); ?>">
          </div>
          <div class="form-actions full">
            <button type="submit" class="btn primary"><?php echo icon('reports'); ?> Generate Report</button>
            <a class="btn" href="reports.php">Reset Filters</a>
          </div>
        </form>
      </section>

      <section class="stat-grid" aria-label="Inventory summary">
        <div class="stat-card">
          <div class="stat-head"><span class="stat-label">Registered Medicines</span><span class="stat-icon"><?php echo icon('medicine'); ?></span></div>
          <span class="stat-value"><?php echo number_format($summary['medicines']); ?></span>
          <span class="stat-hint">Medicine records on file</span>
        </div>
        <div class="stat-card">
          <div class="stat-head"><span class="stat-label">Total Batches</span><span class="stat-icon"><?php echo icon('box'); ?></span></div>
          <span class="stat-value"><?php echo number_format($summary['batches']); ?></span>
          <span class="stat-hint">Recorded medicine batches</span>
        </div>
        <div class="stat-card">
          <div class="stat-head"><span class="stat-label">Usable Units</span><span class="stat-icon"><?php echo icon('stock-in'); ?></span></div>
          <span class="stat-value"><?php echo number_format($summary['usable_units']); ?></span>
          <span class="stat-hint">Units that are not expired</span>
        </div>
        <div class="stat-card">
          <div class="stat-head"><span class="stat-label">Expired Batches</span><span class="stat-icon danger"><?php echo icon('expired'); ?></span></div>
          <span class="stat-value"><?php echo number_format($summary['expired_batches']); ?></span>
          <span class="stat-hint">Ready for disposal</span>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2><?php echo e($reportTypes[$reportType]); ?></h2>
          <div class="page-actions">
            <span class="badge teal"><?php echo number_format(count($reportRows)); ?> rows</span>
            <a class="btn-sm" href="reports.php?<?php echo e($queryFor('csv')); ?>">Export CSV</a>
            <a class="btn-sm" href="reports.php?<?php echo e($queryFor('print')); ?>" target="_blank" rel="noopener">Print</a>
          </div>
        </div>
        <?php if (!$reportRows): ?>
          <div class="empty-state">
            <span class="empty-icon"><?php echo icon('reports'); ?></span>
            <h3>No records match this report</h3>
            <p>Try a different report type or widen the date range.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><?php foreach ($reportHeaders as $heading): ?><th><?php echo e($heading); ?></th><?php endforeach; ?></tr>
              </thead>
              <tbody>
                <?php foreach ($reportRows as $row): ?>
                  <tr>
                    <?php foreach ($row as $index => $cell): ?>
                      <?php $heading = $reportHeaders[$index] ?? ''; ?>
                      <td<?php echo $heading === 'Status' ? ' class="strong"' : ''; ?>><?php echo e((string)$cell); ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2>Inventory Snapshot</h2>
          <span class="badge teal">Top 10</span>
        </div>
        <?php if (!$snapshot): ?>
          <div class="empty-state">
            <span class="empty-icon"><?php echo icon('reports'); ?></span>
            <h3>No report data yet</h3>
            <p>Add medicines and stock-in records to populate inventory reports.</p>
            <a class="btn primary" href="add-medicine.php"><?php echo icon('plus'); ?> Add Medicine</a>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr>
                  <th>Medicine</th><th>Category</th><th>Batches</th><th>Total Quantity</th>
                  <th>Usable Quantity</th><th>Minimum Level</th><th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($snapshot as $row): ?>
                  <?php
                  $usable = (int)$row['usable_quantity'];
                  $threshold = (int)$row['low_stock_threshold'];
                  $status = stock_status($usable, $threshold);
                  $badge = $status === 'Out of stock' ? 'red' : ($status === 'Low stock' ? 'yellow' : 'green');
                  ?>
                  <tr>
                    <td class="strong"><?php echo e((string)$row['name']); ?></td>
                    <td><?php echo e((string)$row['category']); ?></td>
                    <td><?php echo number_format((int)$row['batch_count']); ?></td>
                    <td><?php echo number_format((int)$row['total_quantity']); ?> <?php echo e((string)$row['unit']); ?></td>
                    <td><?php echo number_format($usable); ?> <?php echo e((string)$row['unit']); ?></td>
                    <td><?php echo number_format($threshold); ?></td>
                    <td><span class="badge <?php echo $badge; ?>"><?php echo e($status); ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </main>
<?php render_page_end(); ?>
