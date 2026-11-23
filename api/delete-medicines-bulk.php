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

$rawIds = $_POST['ids'] ?? [];
if (!is_array($rawIds)) {
    $_SESSION['flash_error'] = 'Select at least one medicine to delete.';
    header('Location: medicine-inventory.php');
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', array_filter($rawIds, 'is_scalar')), static function (int $id): bool {
    return $id > 0;
})));

if ($ids === []) {
    $_SESSION['flash_error'] = 'Select at least one medicine to delete.';
    header('Location: medicine-inventory.php');
    exit;
}

try {
    $pdo = get_db_connection();
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $select = $pdo->prepare('SELECT id, name FROM medicines WHERE id IN (' . $placeholders . ') ORDER BY name');
    $select->execute($ids);
    $medicines = $select->fetchAll();

    if ($medicines === []) {
        $_SESSION['flash_error'] = 'The selected medicines could not be found.';
    } else {
        $pdo->beginTransaction();

        $delete = $pdo->prepare('DELETE FROM medicines WHERE id = :id');
        foreach ($medicines as $medicine) {
            $delete->execute(['id' => (int)$medicine['id']]);
            delete_notifications_mentioning($pdo, (string)$medicine['name']);
        }

        $activity = $pdo->prepare(
            'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
             VALUES (:type, NULL, 0, :note, :performed_by)'
        );
        foreach ($medicines as $medicine) {
            $activity->execute([
                'type' => 'delete_medicine',
                'note' => 'Deleted "' . $medicine['name'] . '" and all of its batches',
                'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
            ]);
        }

        $pdo->commit();

        $count = count($medicines);
        $_SESSION['flash_success'] = $count . ' medicine' . ($count === 1 ? '' : 's') . ' deleted from the inventory.';
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Bulk delete medicine error: ' . $exception->getMessage());
    $_SESSION['flash_error'] = 'The selected medicines could not be deleted right now. Please try again.';
}

header('Location: medicine-inventory.php');
exit;
