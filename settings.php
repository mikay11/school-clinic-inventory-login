<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

$defaults = [
    'clinic_name' => 'School Clinic',
    'clinic_address' => 'Main Campus, School Health Office',
    'clinic_contact' => '',
    'low_stock_threshold' => '20',
    'expiry_window' => '30',
    'notify_low_stock' => '1',
    'notify_expiring' => '1',
    'notify_expired' => '1',
    'notify_summary' => '0',
    'items_per_page' => '10',
    'date_format' => 'M j, Y',
    'landing_page' => 'dashboard',
];

$settings = $defaults;
$errors = [];
$dbError = '';

try {
    $pdo = get_db_connection();
    foreach ($pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll() as $row) {
        $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
    }
} catch (Throwable $exception) {
    error_log('Settings load error: ' . $exception->getMessage());
    $dbError = 'Unable to load saved settings. Defaults are shown below.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    foreach ($defaults as $key => $default) {
        $settings[$key] = (string)($_POST[$key] ?? $default);
    }
    foreach (['notify_low_stock', 'notify_expiring', 'notify_expired', 'notify_summary'] as $key) {
        $settings[$key] = isset($_POST[$key]) ? '1' : '0';
    }

    if ($fullName === '' || mb_strlen($fullName) > 120) {
        $errors[] = 'Full name is required and must be 120 characters or fewer.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$/i', $email)) {
        $errors[] = 'Please enter a valid institutional email address ending in @edu.ph.';
    }
    if ($newPassword !== '') {
        if (mb_strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }
    }

    if (mb_strlen($settings['clinic_name']) > 120 || mb_strlen($settings['clinic_address']) > 180 || mb_strlen($settings['clinic_contact']) > 40) {
        $errors[] = 'Clinic details exceed the maximum allowed length.';
    }
    if (!ctype_digit($settings['low_stock_threshold']) || (int)$settings['low_stock_threshold'] > 10000) {
        $errors[] = 'Default low stock level must be a whole number up to 10,000.';
    }
    if (!in_array($settings['expiry_window'], ['30', '60', '90'], true)) {
        $errors[] = 'Please choose a valid expiry alert window.';
    }
    if (!in_array($settings['items_per_page'], ['10', '25', '50'], true)) {
        $errors[] = 'Please choose a valid number of items per page.';
    }
    if (!in_array($settings['date_format'], ['M j, Y', 'F j, Y', 'Y-m-d', 'm/d/Y'], true)) {
        $errors[] = 'Please choose a valid date format.';
    }
    if (!in_array($settings['landing_page'], ['dashboard', 'inventory', 'expiration'], true)) {
        $errors[] = 'Please choose a valid landing page.';
    }

    if (!$errors) {
        try {
            $pdo = get_db_connection();
            $pdo->beginTransaction();

            $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $duplicate->execute(['email' => $email, 'id' => current_user_id()]);
            if ($duplicate->fetch()) {
                throw new RuntimeException('That email address is already in use.');
            }

            if ($newPassword !== '') {
                $updateUser = $pdo->prepare(
                    'UPDATE users SET full_name = :full_name, email = :email, password_hash = :password_hash WHERE id = :id'
                );
                $updateUser->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'id' => current_user_id(),
                ]);
            } else {
                $updateUser = $pdo->prepare('UPDATE users SET full_name = :full_name, email = :email WHERE id = :id');
                $updateUser->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'id' => current_user_id(),
                ]);
            }

            $saveSetting = $pdo->prepare(
                'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($defaults as $key => $default) {
                $saveSetting->execute(['key' => $key, 'value' => $settings[$key]]);
            }

            $pdo->commit();

            $_SESSION['full_name'] = $fullName;
            $_SESSION['email'] = $email;
            set_flash('success', 'Settings saved successfully.');
            header('Location: settings.php');
            exit;
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Settings save error: ' . $exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Settings could not be saved right now. Please try again.';
        }
    }
}

$success = take_flash('success');
$identity = session_identity();
render_page_start('Settings', 'settings', $identity['full_name'], unread_notifications_count(), 'Manage your account, clinic details, and system preferences.');
?>
    <main class="content">
      <?php if ($success !== ''): ?><div class="alert success" role="status"><?php echo e($success); ?></div><?php endif; ?>
      <?php if ($dbError !== ''): ?><div class="alert" role="alert"><?php echo e($dbError); ?></div><?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert" role="alert">
          <ul class="alert-list">
            <?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="POST" action="settings.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

        <div class="grid-2">
          <section class="panel">
            <div class="panel-head">
              <h2>Account Information</h2>
              <span class="badge teal"><?php echo e(ucwords(str_replace('_', ' ', $identity['role']))); ?></span>
            </div>
            <div class="field">
              <label for="full_name">Full Name</label>
              <input type="text" id="full_name" name="full_name" value="<?php echo e($fullName ?? $identity['full_name']); ?>" maxlength="120" required>
            </div>
            <div class="field">
              <label for="email">Email Address</label>
              <input type="email" id="email" name="email" value="<?php echo e($email ?? $identity['email']); ?>" maxlength="150" placeholder="name@school.edu.ph" required>
            </div>
            <div class="field">
              <label for="new_password">New Password</label>
              <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current password" autocomplete="new-password">
              <span class="help-inline">Minimum 8 characters.</span>
            </div>
            <div class="field">
              <label for="confirm_password">Confirm New Password</label>
              <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter new password" autocomplete="new-password">
            </div>
          </section>

          <section class="panel">
            <div class="panel-head"><h2>Clinic Information</h2></div>
            <div class="field">
              <label for="clinic_name">Clinic Name</label>
              <input type="text" id="clinic_name" name="clinic_name" value="<?php echo e($settings['clinic_name']); ?>" maxlength="120">
            </div>
            <div class="field">
              <label for="clinic_address">Address</label>
              <input type="text" id="clinic_address" name="clinic_address" value="<?php echo e($settings['clinic_address']); ?>" maxlength="180">
            </div>
            <div class="field">
              <label for="clinic_contact">Contact Number</label>
              <input type="text" id="clinic_contact" name="clinic_contact" value="<?php echo e($settings['clinic_contact']); ?>" maxlength="40" placeholder="e.g. (02) 8123-4567">
            </div>
            <div class="form-grid">
              <div class="field">
                <label for="low_stock_threshold">Default Low Stock Level</label>
                <input type="number" id="low_stock_threshold" name="low_stock_threshold" value="<?php echo e($settings['low_stock_threshold']); ?>" min="0" step="1">
              </div>
              <div class="field">
                <label for="expiry_window">Expiry Alert Window</label>
                <select id="expiry_window" name="expiry_window">
                  <?php foreach (['30' => '30 days before expiry', '60' => '60 days before expiry', '90' => '90 days before expiry'] as $value => $label): ?>
                    <option value="<?php echo e((string)$value); ?>"<?php echo $settings['expiry_window'] === (string)$value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </section>

          <section class="panel">
            <div class="panel-head"><h2>Notification Preferences</h2></div>
            <?php
            $notificationOptions = [
                'notify_low_stock' => 'Low stock alerts',
                'notify_expiring' => 'Expiring soon alerts',
                'notify_expired' => 'Expired batch alerts',
                'notify_summary' => 'Weekly inventory summary',
            ];
            foreach ($notificationOptions as $key => $label): ?>
              <label class="check-field">
                <input type="checkbox" name="<?php echo e($key); ?>" value="1"<?php echo $settings[$key] === '1' ? ' checked' : ''; ?>>
                <?php echo e($label); ?>
              </label>
            <?php endforeach; ?>
          </section>

          <section class="panel">
            <div class="panel-head"><h2>Display Preferences</h2></div>
            <div class="field">
              <label for="items_per_page">Items Per Page</label>
              <select id="items_per_page" name="items_per_page">
                <?php foreach (['10' => '10 items', '25' => '25 items', '50' => '50 items'] as $value => $label): ?>
                  <option value="<?php echo e((string)$value); ?>"<?php echo $settings['items_per_page'] === (string)$value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="date_format">Date Format</label>
              <select id="date_format" name="date_format">
                <?php
                $dateFormats = [
                    'M j, Y' => date('M j, Y'),
                    'F j, Y' => date('F j, Y'),
                    'Y-m-d' => date('Y-m-d'),
                    'm/d/Y' => date('m/d/Y'),
                ];
                foreach ($dateFormats as $value => $label): ?>
                  <option value="<?php echo e((string)$value); ?>"<?php echo $settings['date_format'] === (string)$value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="landing_page">Default Landing Page</label>
              <select id="landing_page" name="landing_page">
                <?php
                $landingPages = [
                    'dashboard' => 'Dashboard',
                    'inventory' => 'Medicine Inventory',
                    'expiration' => 'Expiration Monitoring',
                ];
                foreach ($landingPages as $value => $label): ?>
                  <option value="<?php echo e((string)$value); ?>"<?php echo $settings['landing_page'] === (string)$value ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </section>
        </div>

        <section class="panel">
          <div class="form-actions">
            <button type="submit" class="btn primary">Save Settings</button>
            <button type="reset" class="btn">Reset Changes</button>
            <a class="btn" href="dashboard.php">Cancel</a>
          </div>
        </section>
      </form>
    </main>
<?php render_page_end(); ?>
