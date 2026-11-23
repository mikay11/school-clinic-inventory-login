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
          <style>
            .notification-menu-wrap { position: relative; margin-left: auto; }
            .notification-menu-trigger { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; padding: 0; border: 1px solid transparent; border-radius: 8px; background: transparent; color: var(--muted); cursor: pointer; }
            .notification-menu-trigger:hover, .notification-menu-trigger[aria-expanded="true"] { border-color: var(--border); background: var(--bg); color: var(--teal); }
            .notification-menu-trigger svg { width: 16px; height: 16px; fill: currentColor; }
            .notification-menu { position: absolute; right: 0; top: calc(100% + 4px); min-width: 130px; padding: 6px; border: 1px solid var(--border); border-radius: 10px; background: var(--bg); box-shadow: var(--shadow); z-index: 10; }
            .notification-menu[hidden] { display: none; }
            .notification-menu .notification-action { margin-top: 0; }
            .notification-menu .btn { width: 100%; justify-content: center; }
          </style>
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
                <div class="notification-menu-wrap">
                  <button type="button" class="notification-menu-trigger" aria-label="Notification options" aria-haspopup="true" aria-expanded="false">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="3" cy="8" r="1.4"></circle><circle cx="8" cy="8" r="1.4"></circle><circle cx="13" cy="8" r="1.4"></circle></svg>
                  </button>
                  <div class="notification-menu" hidden>
                    <form method="POST" action="delete-notification.php" class="notification-action" onsubmit="return confirm('Delete this notification? This cannot be undone.');">
                      <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                      <input type="hidden" name="id" value="<?php echo (int)$notification['id']; ?>">
                      <button type="submit" class="btn btn-sm danger">Delete</button>
                    </form>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <script>
        (function () {
          var triggers = Array.prototype.slice.call(document.querySelectorAll('.notification-menu-trigger'));
          var menus = Array.prototype.slice.call(document.querySelectorAll('.notification-menu'));

          function closeMenus() {
            menus.forEach(function (menu) { menu.hidden = true; });
            triggers.forEach(function (trigger) { trigger.setAttribute('aria-expanded', 'false'); });
          }

          triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function (event) {
              event.stopPropagation();
              var menu = trigger.nextElementSibling;
              var open = menu.hidden;
              closeMenus();
              menu.hidden = !open;
              trigger.setAttribute('aria-expanded', String(open));
            });
          });

          document.addEventListener('click', function (event) {
            if (!event.target.closest('.notification-menu-wrap')) {
              closeMenus();
            }
          });

          document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
              closeMenus();
            }
          });
        })();
      </script>
    </main>
<?php render_page_end(); ?>
