<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';

$message = '';
$error = '';
$resetLink = '';
$genericMessage = 'If the account exists and is authorized, recovery instructions have been sent. Please contact the clinic system administrator if you need assistance.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please submit the form again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$/i', $email)) {
        $error = 'Please enter a valid institutional email address ending in @edu.ph.';
    } else {
        try {
            $pdo = get_db_connection();
            $statement = $pdo->prepare('SELECT id, full_name FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);

                $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id')->execute(['id' => (int)$user['id']]);
                $insert = $pdo->prepare(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:id, :hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
                );
                $insert->execute(['id' => (int)$user['id'], 'hash' => $tokenHash]);

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
                $base = APP_BASE_URL !== '' ? APP_BASE_URL : $scheme . $host . $path;
                $resetLink = $base . '/reset-password.php?token=' . $token;

                $subject = 'School Clinic Inventory - Password Recovery';
                $body = "Hello " . $user['full_name'] . ",\n\n"
                    . "Use the link below to reset your password. It expires in 60 minutes.\n\n"
                    . $resetLink . "\n\n"
                    . "If you did not request this, you can ignore this message.\n";
                $headers = 'From: ' . MAIL_FROM . "\r\n" . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
                $sent = @mail($email, $subject, $body, $headers);

                if (!$sent && SHOW_RESET_LINK) {
                    $message = 'Email delivery is not configured on this host. Use the one-time link below to reset your password (valid for 60 minutes).';
                } else {
                    $message = $genericMessage;
                    $resetLink = '';
                }
            } else {
                $message = $genericMessage;
            }
        } catch (Throwable $exception) {
            error_log('Password recovery error: ' . $exception->getMessage());
            $message = $genericMessage;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password - School Clinic Inventory System</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <main class="simple-page">
    <div class="card">
      <h1>Forgot Password</h1>
      <p class="muted">Enter your institutional email address to request a password reset link.</p>
      <?php if ($message): ?><div class="alert" style="background:#e8f5f5;color:#12565a;border-color:#bfe3e3;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($resetLink !== ''): ?><p><a class="link" href="<?php echo htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8'); ?>">Open password reset link</a></p><?php endif; ?>
      <?php if ($error): ?><div class="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <form method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="field">
          <label for="email">Institutional Email Address (@edu.ph)</label>
          <input type="email" id="email" name="email" required pattern="^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$" placeholder="name@school.edu.ph" value="<?php echo htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <button type="submit" class="btn-primary">Request Recovery</button>
      </form>
      <p style="margin-top:14px;"><a class="link" href="index.php">Back to Login</a></p>
    </div>
  </main>
</body>
</html>
