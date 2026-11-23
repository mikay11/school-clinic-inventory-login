<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';

$token = (string)($_REQUEST['token'] ?? '');
$validTokenFormat = (bool)preg_match('/^[a-f0-9]{64}$/', $token);
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please open the reset link again.';
    } elseif (!$validTokenFormat) {
        $error = 'This reset link is invalid.';
    } elseif (mb_strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Password and confirmation do not match.';
    } else {
        try {
            $pdo = get_db_connection();
            $tokenHash = hash('sha256', $token);
            $statement = $pdo->prepare(
                'SELECT id, user_id FROM password_resets
                 WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
                 LIMIT 1'
            );
            $statement->execute(['hash' => $tokenHash]);
            $reset = $statement->fetch();

            if (!$reset) {
                $error = 'This reset link has expired or was already used. Please request a new one.';
            } else {
                $pdo->beginTransaction();
                $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $update->execute([
                    'hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => (int)$reset['user_id'],
                ]);
                $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => (int)$reset['user_id']]);
                $pdo->commit();
                $success = true;
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Password reset error: ' . $exception->getMessage());
            $error = 'Your password could not be reset right now. Please try again.';
        }
    }

    if ($success) {
        header('Location: index.php?reset=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password - School Clinic Inventory System</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <main class="simple-page">
    <div class="card">
      <h1>Reset Password</h1>
      <p class="muted">Choose a new password for your clinic account.</p>
      <?php if (!$validTokenFormat): ?>
        <div class="alert">This reset link is invalid or incomplete.</div>
      <?php else: ?>
        <?php if ($error): ?><div class="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <form method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="field">
            <label for="password">New Password</label>
            <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
            <span class="help-inline">Minimum 8 characters.</span>
          </div>
          <div class="field">
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
          </div>
          <button type="submit" class="btn-primary">Set New Password</button>
        </form>
      <?php endif; ?>
      <p style="margin-top:14px;"><a class="link" href="forgot-password.php">Request a new link</a> &middot; <a class="link" href="index.php">Back to Login</a></p>
    </div>
  </main>
</body>
</html>
