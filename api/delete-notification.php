<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: notifications.php');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    set_flash('error', 'Your session expired. Please try again.');
    header('Location: notifications.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'The requested notification could not be found.');
    header('Location: notifications.php');
    exit;
}

try {
    $pdo = get_db_connection();
    $statement = $pdo->prepare('SELECT id, message FROM notifications WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $notification = $statement->fetch();

    if (!$notification) {
        set_flash('error', 'The requested notification could not be found.');
    } else {
        suppress_notification_message($pdo, (string)$notification['message']);
        $delete = $pdo->prepare('DELETE FROM notifications WHERE id = :id');
        $delete->execute(['id' => $id]);
        set_flash('success', 'Notification deleted.');
    }
} catch (Throwable $exception) {
    error_log('Delete notification error: ' . $exception->getMessage());
    set_flash('error', 'The notification could not be deleted right now.');
}

header('Location: notifications.php');
exit;
