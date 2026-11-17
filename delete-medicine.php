<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: medicine-inventory.php');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    $_SESSION['flash_error'] = 'Your session expired. Please try again.';
    header('Location: medicine-inventory.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash_error'] = 'The requested medicine could not be found.';
    header('Location: medicine-inventory.php');
    exit;
}

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT id, name FROM medicines WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $medicine = $stmt->fetch();

    if (!$medicine) {
        $_SESSION['flash_error'] = 'The requested medicine could not be found.';
    } else {
        $pdo->beginTransaction();

        $delete = $pdo->prepare('DELETE FROM medicines WHERE id = :id');
        $delete->execute(['id' => $id]);

        $activity = $pdo->prepare(
            'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
             VALUES (:type, NULL, 0, :note, :performed_by)'
        );
        $activity->execute([
            'type' => 'delete_medicine',
            'note' => 'Deleted "' . $medicine['name'] . '" and all of its batches',
            'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
        ]);

        $pdo->commit();

        $_SESSION['flash_success'] = '"' . $medicine['name'] . '" was deleted from the inventory.';
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Delete medicine error: ' . $exception->getMessage());
    $_SESSION['flash_error'] = 'The medicine could not be deleted right now. Please try again.';
}

header('Location: medicine-inventory.php');
exit;
