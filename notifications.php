<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        try {
            $pdo = get_db_connection();
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'all') {
                $pdo->exec('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
                set_flash('success', 'All notifications were marked as read.');
            } elseif ($action === 'one') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $statement = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id');
                    $statement->execute(['id' => $id]);
                    set_flash('success', 'Notification marked as read.');
                }
            }
        } catch (Throwable $exception) {
            error_log('Notification update error: ' . $exception->getMessage());
            set_flash('error', 'The notification could not be updated right now.');
        }
    }

    header('Location: notifications.php');
    exit;
}

$notifications = [];
$unread = 0;
$dbError = '';

try {
    $pdo = get_db_connection();
    $notifications = $pdo->query(
        'SELECT id, message, is_read, created_at
         FROM notifications
         ORDER BY is_read ASC, id DESC
         LIMIT 50'
    )->fetchAll();
    $unread = (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
} catch (Throwable $exception) {
    error_log('Notifications load error: ' . $exception->getMessage());
    $dbError = 'Unable to load notifications right now. Please try again later.';
}

$success = take_flash('success');
$error = take_flash('error');
$identity = session_identity();
render_page_start('Notifications', 'notifications', $identity['full_name'], $unread, 'Alerts and updates about clinic inventory.');
?>
    <main class="content">
      <?php if ($success !== ''): ?><div class="alert success" role="status"><?php echo e($success); ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="alert" role="alert"><?php echo e($error); ?></div><?php endif; ?>
      <?php if ($dbError !== ''): ?><div class="alert" role="alert"><?php echo e($dbError); ?></div><?php endif; ?>

      <section class="panel">
        <div class="panel-head">
          <h2>All Notifications</h2>
          <div class="page-actions">
            <span class="badge teal"><?php echo number_format($unread); ?> unread</span>
            <?php if ($unread > 0): ?>
              <form method="POST" action="notifications.php">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="all">
                <button type="submit" class="btn-sm">Mark all as read</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$notifications): ?>
          <div class="empty-state">
            <span class="empty-icon"><?php echo icon('bell'); ?></span>
            <h3>You are all caught up</h3>
            <p>Low stock and expiration alerts will appear here.</p>
          </div>
        <?php else: ?>
          <ul class="notification-list">
            <?php foreach ($notifications as $notification): ?>
              <li class="notification-item<?php echo (int)$notification['is_read'] === 0 ? ' is-unread' : ''; ?>">
                <span class="notification-icon"><?php echo icon('bell'); ?></span>
                <div class="notification-body">
                  <p><?php echo e((string)$notification['message']); ?></p>
                  <span class="notification-time"><?php echo date('M j, Y g:i A', strtotime((string)$notification['created_at'])); ?></span>
                  <?php if ((int)$notification['is_read'] === 0): ?>
                    <form method="POST" action="notifications.php" class="notification-action">
                      <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                      <input type="hidden" name="action" value="one">
                      <input type="hidden" name="id" value="<?php echo (int)$notification['id']; ?>">
                      <button type="submit" class="link-button">Mark as read</button>
                    </form>
                  <?php endif; ?>
                </div>
                <?php if ((int)$notification['is_read'] === 0): ?>
                  <span class="unread-dot" aria-label="Unread"></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </main>
<?php render_page_end(); ?>
