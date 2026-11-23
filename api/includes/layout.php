<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/deleted-data.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="4" rx="1.5"/><rect x="14" y="10" width="7" height="11" rx="1.5"/><rect x="3" y="13" width="7" height="8" rx="1.5"/>',
        'medicine' => '<rect x="3" y="8" width="18" height="8" rx="4"/><path d="M12 8v8"/>',
        'stock-in' => '<path d="M12 3v10"/><path d="m8 9 4 4 4-4"/><path d="M4 17v3h16v-3"/>',
        'stock-out' => '<path d="M12 14V4"/><path d="m8 8 4-4 4 4"/><path d="M4 17v3h16v-3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'bell' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'reports' => '<path d="M4 20V6M10 20v-8M16 20V9M22 20H2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'alert' => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
        'expired' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="m9.5 14.5 5 5M14.5 14.5l-5 5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'back' => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
    ];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Returns the unread notification count (0 when the database is unavailable).
 */
function unread_notifications_count(): int
{
    try {
        require_once __DIR__ . '/../config/db.php';
        return (int)get_db_connection()->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
    } catch (Throwable $exception) {
        error_log('Notification count error: ' . $exception->getMessage());
        return 0;
    }
}

function create_notification(PDO $pdo, string $message, bool $dedupe = true): void
{
    $message = mb_substr(trim($message), 0, 255);
    if ($message === '') {
        return;
    }

    if (notification_message_suppressed($pdo, $message)) {
        return;
    }

    if ($dedupe) {
        $existing = $pdo->prepare('SELECT id FROM notifications WHERE message = :message AND is_read = 0 LIMIT 1');
        $existing->execute(['message' => $message]);
        if ($existing->fetch()) {
            return;
        }
    }

    $insert = $pdo->prepare('INSERT INTO notifications (message) VALUES (:message)');
    $insert->execute(['message' => $message]);
}

function generate_inventory_alerts(PDO $pdo): void
{
    $lowStock = $pdo->query(
        'SELECT m.name, m.low_stock_threshold,
                COALESCE(SUM(CASE WHEN b.expiration_date >= CURDATE() THEN b.quantity ELSE 0 END), 0) AS usable
         FROM medicines m
         LEFT JOIN medicine_batches b ON b.medicine_id = m.id
         GROUP BY m.id, m.name, m.low_stock_threshold
         HAVING usable <= m.low_stock_threshold'
    )->fetchAll();

    foreach ($lowStock as $item) {
        $quantity = (int)$item['usable'];
        $label = $quantity === 0 ? 'is out of stock' : 'is low on stock';
        create_notification($pdo, $item['name'] . ' ' . $label . ' (' . $quantity . ' left, threshold ' . (int)$item['low_stock_threshold'] . ').');
    }

    $expiring = $pdo->query(
        'SELECT m.name, b.batch_no, b.quantity, b.expiration_date,
                DATEDIFF(b.expiration_date, CURDATE()) AS days_left
         FROM medicine_batches b
         JOIN medicines m ON m.id = b.medicine_id
         WHERE b.expiration_date < CURDATE()
            OR b.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY b.expiration_date ASC
         LIMIT 30'
    )->fetchAll();

    foreach ($expiring as $item) {
        $days = (int)$item['days_left'];
        if ($days < 0) {
            $message = 'Batch ' . $item['batch_no'] . ' of ' . $item['name'] . ' expired ' . abs($days) . ' day(s) ago (' . (int)$item['quantity'] . ' units).';
        } elseif ($days === 0) {
            $message = 'Batch ' . $item['batch_no'] . ' of ' . $item['name'] . ' expires today (' . (int)$item['quantity'] . ' units).';
        } else {
            $message = 'Batch ' . $item['batch_no'] . ' of ' . $item['name'] . ' expires in ' . $days . ' day(s) (' . (int)$item['quantity'] . ' units).';
        }
        create_notification($pdo, $message);
    }
}

function session_identity(): array
{
    $fullName = (string)($_SESSION['full_name'] ?? 'Clinic Personnel');
    $email = (string)($_SESSION['email'] ?? '');
    $initials = '';
    foreach (preg_split('/\s+/', trim($fullName)) ?: [] as $part) {
        if ($part !== '') {
            $initials .= strtoupper($part[0]);
        }
    }

    return [
        'full_name' => $fullName,
        'email' => $email,
        'role' => (string)($_SESSION['role'] ?? 'clinic_personnel'),
        'initials' => substr($initials !== '' ? $initials : 'CP', 0, 2),
    ];
}

/**
 * Emits the document head, sidebar and top header for authenticated app pages.
 */
function render_page_start(string $pageTitle, string $activeNav, string $welcomeName, int $unread, ?string $subtitle = null): void
{
    $identity = session_identity();
    $navItems = [
        'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
        'inventory' => ['label' => 'Medicine Inventory', 'href' => 'medicine-inventory.php', 'icon' => 'medicine'],
        'stock-in' => ['label' => 'Stock In', 'href' => 'stock-in.php', 'icon' => 'stock-in'],
        'stock-out' => ['label' => 'Stock Out', 'href' => 'stock-out.php', 'icon' => 'stock-out'],
        'expiration' => ['label' => 'Expiration Monitoring', 'href' => 'expiration.php', 'icon' => 'clock'],
        'notifications' => ['label' => 'Notifications', 'href' => 'notifications.php', 'icon' => 'bell'],
        'reports' => ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'reports'],
        'settings' => ['label' => 'Settings', 'href' => 'settings.php', 'icon' => 'settings'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo e($pageTitle); ?> - School Clinic Inventory System</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="app">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <img src="assets/img/logo-1.jpg" alt="" class="brand-logo">
      <div class="brand-text">
        <span class="brand-name">School Clinic</span>
        <span class="brand-sub">Inventory System</span>
      </div>
    </div>

    <nav class="nav" aria-label="Main navigation">
      <?php foreach ($navItems as $key => $item): ?>
        <?php $isActive = $key === $activeNav; ?>
        <a class="nav-item<?php echo $isActive ? ' active' : ''; ?>" href="<?php echo e($item['href']); ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
          <?php echo icon($item['icon']); ?><span><?php echo e($item['label']); ?></span>
          <?php if ($key === 'notifications' && $unread > 0): ?><span class="nav-badge"><?php echo $unread; ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <nav class="nav nav-secondary" aria-label="Account">
      <a class="nav-item" href="auth/logout.php"><?php echo icon('logout'); ?><span>Log Out</span></a>
    </nav>
  </aside>

  <div class="app-body">
    <header class="topbar">
      <div class="topbar-left">
        <button type="button" class="menu-toggle" id="menuToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="topbar-title">
          <h1><?php echo e($pageTitle); ?></h1>
          <p class="welcome"><?php echo e($subtitle ?? 'Welcome back, ' . $welcomeName); ?></p>
        </div>
      </div>
      <div class="topbar-actions">
        <a class="bell" href="notifications.php" title="<?php echo $unread; ?> unread notifications" aria-label="<?php echo $unread; ?> unread notifications">
          <?php echo icon('bell'); ?>
          <?php if ($unread > 0): ?><span class="bell-count"><?php echo $unread > 99 ? '99+' : $unread; ?></span><?php endif; ?>
        </a>
        <div class="avatar" title="<?php echo e($identity['full_name'] . ($identity['email'] !== '' ? ' - ' . $identity['email'] : '')); ?>"><?php echo e($identity['initials']); ?></div>
      </div>
    </header>
<?php
}

function render_page_end(): void
{
    ?>
  </div>
  <script src="assets/js/dashboard.js"></script>
</body>
</html>
<?php
}
