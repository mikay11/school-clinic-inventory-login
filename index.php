<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';

if (is_authenticated()) {
    header('Location: dashboard.php');
    exit;
}

$error = $_SESSION['login_error'] ?? '';
$oldEmail = $_SESSION['old_email'] ?? '';
unset($_SESSION['login_error'], $_SESSION['old_email']);
$timedOut = isset($_GET['timeout']);
$resetDone = isset($_GET['reset']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>School Clinic Inventory System - Login</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <main class="page">
    <section class="login-card" aria-labelledby="system-name">
      <div class="logo-wrap">
        <img src="assets/img/logo-1.jpg" alt="School logo" class="logo-img">
        <img src="assets/img/logo-2.jpg" alt="Clinic logo" class="logo-img">
      </div>
      <h1 id="system-name">School Clinic Inventory System</h1>
      <p class="subtitle">Medicine Inventory and Expiration Monitoring System</p>

     

      <?php if ($timedOut): ?>
        <div class="alert" role="status">Your session expired after a period of inactivity. Please log in again.</div>
      <?php endif; ?>

      <?php if ($resetDone): ?>
        <div class="alert" style="background:#e8f5f5;color:#12565a;border-color:#bfe3e3;" role="status">Your password has been updated. Please log in with your new password.</div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <form id="loginForm" action="auth/login.php" method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="field">
          <label for="email">Institutional Email Address</label>
          <input
            type="email"
            id="email"
            name="email"
            inputmode="email"
            autocomplete="username"
            placeholder="name@school.edu.ph"
            pattern="^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$"
            value="<?php echo htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8'); ?>"
            required
          >
        </div>

        <div class="field">
          <label for="password">Password</label>
          <div class="password-wrap">
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="button" id="togglePassword" class="icon-btn" aria-label="Show password" aria-pressed="false">
              <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z" fill="none" stroke="currentColor" stroke-width="1.8"/>
                <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/>
              </svg>
            </button>
          </div>
        </div>

        <div class="form-row">
          <label class="checkbox">
            <input type="checkbox" name="remember_me" value="1">
            <span>Remember Me</span>
          </label>
          <a href="forgot-password.php" class="link">Forgot Password?</a>
        </div>

        <button type="submit" class="btn-primary">Login</button>
        <p id="clientError" class="client-error" role="alert" aria-live="polite"></p>
      </form>

      <p class="note">Access is restricted to authorized school clinic personnel only. No public registration.</p>
    </section>
  </main>
  <script src="assets/js/login.js"></script>
</body>
</html>
