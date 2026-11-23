<?php
declare(strict_types=1);

function notification_suppression_key(string $message): string
{
    return 'notif_suppress_' . hash('sha256', $message);
}

function notification_message_suppressed(PDO $pdo, string $message): bool
{
    try {
        $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
        $statement->execute(['key' => notification_suppression_key($message)]);
        return $statement->fetch() !== false;
    } catch (Throwable $exception) {
        error_log('Notification suppression check error: ' . $exception->getMessage());
        return false;
    }
}

function suppress_notification_message(PDO $pdo, string $message): void
{
    try {
        $statement = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $statement->execute(['key' => notification_suppression_key($message), 'value' => '1']);
    } catch (Throwable $exception) {
        error_log('Notification suppression save error: ' . $exception->getMessage());
    }
}

function delete_notifications_mentioning(PDO $pdo, string $medicineName): void
{
    $term = trim($medicineName);
    if ($term === '') {
        return;
    }

    try {
        $select = $pdo->prepare('SELECT message FROM notifications WHERE LOCATE(:term, message) > 0');
        $select->execute(['term' => $term]);
        $messages = $select->fetchAll(PDO::FETCH_COLUMN);

        foreach ($messages as $message) {
            suppress_notification_message($pdo, (string)$message);
        }

        $delete = $pdo->prepare('DELETE FROM notifications WHERE LOCATE(:term, message) > 0');
        $delete->execute(['term' => $term]);
    } catch (Throwable $exception) {
        error_log('Delete medicine notifications error: ' . $exception->getMessage());
    }
}
