<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';

$errors = [];
$values = [
    'name' => '',
    'generic_name' => '',
    'category' => 'Tablet',
    'location' => '',
    'description' => '',
    'low_stock_threshold' => '20',
    'quantity' => '0',
    'batch_no' => '',
    'received_date' => date('Y-m-d'),
    'expiration_date' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string)($_POST[$key] ?? $default));
    }

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }

    if (!in_array($values['name'], MEDICINE_NAMES, true)) {
        $errors[] = 'Please select a medicine from the list.';
    }

    if ($values['generic_name'] !== '' && !in_array($values['generic_name'], GENERIC_NAMES, true)) {
        $errors[] = 'Please select a generic name from the list.';
    }

    if (!in_array($values['category'], CATEGORIES, true)) {
        $values['category'] = 'Other';
    }

    if (mb_strlen($values['location']) > 80) {
        $errors[] = 'Storage location must be 80 characters or fewer.';
    }

    if (mb_strlen($values['description']) > 255) {
        $errors[] = 'Description must be 255 characters or fewer.';
    }

    if ($values['low_stock_threshold'] === '' || !ctype_digit($values['low_stock_threshold'])) {
        $errors[] = 'Minimum stock level is required and must be a whole number (0 or more).';
    }

    if (!ctype_digit($values['quantity'])) {
        $errors[] = 'Initial quantity must be a whole number (0 or more).';
    }

    $quantity = (int)$values['quantity'];
    $receivedDate = $values['received_date'] !== '' ? $values['received_date'] : date('Y-m-d');
    $values['received_date'] = $receivedDate;
    $receivedValid = DateTime::createFromFormat('Y-m-d', $receivedDate) !== false;
    if (!$receivedValid) {
        $errors[] = 'Received date is not a valid date.';
    }

    $expirationValid = DateTime::createFromFormat('Y-m-d', $values['expiration_date']) !== false;
    if ($quantity > 0) {
        if ($values['expiration_date'] === '' || !$expirationValid) {
            $errors[] = 'An expiration date is required when initial stock is provided.';
        } elseif ($receivedValid && $values['expiration_date'] <= $receivedDate) {
            $errors[] = 'Expiration date must be after the received date.';
        }

        if ($values['batch_no'] === '') {
            $errors[] = 'Batch name is required when initial stock is provided.';
        } elseif (mb_strlen($values['batch_no']) > 60) {
            $errors[] = 'Batch name must be 60 characters or fewer.';
        }
    }

    if (!$errors) {
        try {
            $pdo = get_db_connection();

            $duplicate = $pdo->prepare('SELECT id FROM medicines WHERE name = :name LIMIT 1');
            $duplicate->execute(['name' => $values['name']]);
            if ($duplicate->fetch()) {
                $errors[] = 'A medicine with this name already exists.';
            } else {
                $pdo->beginTransaction();

                $insert = $pdo->prepare(
                    'INSERT INTO medicines (name, generic_name, category, unit, location, description, low_stock_threshold)
                     VALUES (:name, :generic_name, :category, :unit, :location, :description, :threshold)'
                );
                $insert->execute([
                    'name' => $values['name'],
                    'generic_name' => $values['generic_name'],
                    'category' => $values['category'],
                    'unit' => 'piece',
                    'location' => $values['location'],
                    'description' => $values['description'],
                    'threshold' => (int)$values['low_stock_threshold'],
                ]);
                $medicineId = (int)$pdo->lastInsertId();

                $batchNo = $values['batch_no'];

                if ($quantity > 0) {
                    $batch = $pdo->prepare(
                        'INSERT INTO medicine_batches (medicine_id, batch_no, quantity, received_date, expiration_date)
                         VALUES (:medicine_id, :batch_no, :quantity, :received_date, :expiration_date)'
                    );
                    $batch->execute([
                        'medicine_id' => $medicineId,
                        'batch_no' => $batchNo,
                        'quantity' => $quantity,
                        'received_date' => $receivedDate,
                        'expiration_date' => $values['expiration_date'],
                    ]);
                }

                $activity = $pdo->prepare(
                    'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
                     VALUES (:type, :medicine_id, :quantity, :note, :performed_by)'
                );
                $activity->execute([
                    'type' => 'add_medicine',
                    'medicine_id' => $medicineId,
                    'quantity' => $quantity,
                    'note' => $quantity > 0 ? 'New medicine added with initial stock (batch ' . $batchNo . ')' : 'New medicine added to inventory',
                    'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
                ]);

                create_notification($pdo, 'New medicine registered: ' . $values['name'] . '.');

                $pdo->commit();

                $_SESSION['flash_success'] = '"' . $values['name'] . '" was added to the inventory.';
                header('Location: medicine-inventory.php');
                exit;
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Add medicine error: ' . $exception->getMessage());
            $errors[] = 'The medicine could not be saved right now. Please try again.';
        }
    }
}

$identity = session_identity();
render_page_start('Add Medicine', 'inventory', $identity['full_name'], unread_notifications_count());
?>
    <main class="content">
      <div class="page-head">
        <div>
          <a class="link back-link" href="medicine-inventory.php"><?php echo icon('back'); ?> Back to Medicine Inventory</a>
          <p class="muted page-sub">Register a new medicine item. Initial stock is optional and can also be recorded later through Stock In.</p>
        </div>
      </div>

      <?php if ($errors): ?>
        <div class="alert" role="alert">
          <ul class="alert-list">
            <?php foreach ($errors as $error): ?>
              <li><?php echo e($error); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <section class="panel form-panel">
        <form method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

          <h2 class="form-title">Medicine Details</h2>
          <div class="form-grid">
            <div class="field">
              <label for="name">Medicine Name <span class="req">*</span></label>
              <select id="name" name="name" required>
                <option value="" disabled<?php echo $values['name'] === '' ? ' selected' : ''; ?>>Select medicine</option>
                <?php foreach (MEDICINE_NAMES as $medicineName): ?>
                  <option value="<?php echo e($medicineName); ?>"<?php echo $values['name'] === $medicineName ? ' selected' : ''; ?>><?php echo e($medicineName); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="generic_name">Generic Name</label>
              <select id="generic_name" name="generic_name">
                <option value=""<?php echo $values['generic_name'] === '' ? ' selected' : ''; ?>>Select generic name</option>
                <?php foreach (GENERIC_NAMES as $genericName): ?>
                  <option value="<?php echo e($genericName); ?>"<?php echo $values['generic_name'] === $genericName ? ' selected' : ''; ?>><?php echo e($genericName); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="category">Category</label>
              <select id="category" name="category">
                <?php foreach (CATEGORIES as $category): ?>
                  <option value="<?php echo e($category); ?>"<?php echo $values['category'] === $category ? ' selected' : ''; ?>><?php echo e($category); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="low_stock_threshold">Minimum Stock Level <span class="req">*</span></label>
              <input type="number" id="low_stock_threshold" name="low_stock_threshold" min="0" step="1" value="<?php echo e($values['low_stock_threshold']); ?>" required>
              <span class="help-inline">Alerts appear when available stock reaches this number.</span>
            </div>

            <div class="field">
              <label for="location">Storage Location</label>
              <input type="text" id="location" name="location" list="locationOptions" maxlength="80" placeholder="e.g. Cabinet A" value="<?php echo e($values['location']); ?>">
              <datalist id="locationOptions">
                <?php foreach (STOCK_LOCATIONS as $location): ?>
                  <option value="<?php echo e($location); ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>

            <div class="field full">
              <label for="description">Description <span class="muted small">(optional)</span></label>
              <textarea id="description" name="description" rows="3" maxlength="255" placeholder="Notes about this medicine, e.g. uses, dosage form, or handling reminders."><?php echo e($values['description']); ?></textarea>
            </div>
          </div>

          <hr class="divider">

          <h2 class="form-title">Initial Stock <span class="muted small">(optional)</span></h2>
          <div class="form-grid">
            <div class="field">
              <label for="batch_no">Batch Name <span class="req">*</span></label>
              <input type="text" id="batch_no" name="batch_no" maxlength="60" placeholder="e.g. BT-2026-001" value="<?php echo e($values['batch_no']); ?>">
              <span class="help-inline">Required only when quantity is greater than zero.</span>
            </div>

            <div class="field">
              <label for="quantity">Quantity</label>
              <input type="number" id="quantity" name="quantity" min="0" step="1" value="<?php echo e($values['quantity']); ?>">
            </div>

            <div class="field">
              <label for="received_date">Received Date</label>
              <input type="date" id="received_date" name="received_date" value="<?php echo e($values['received_date']); ?>">
            </div>

            <div class="field">
              <label for="expiration_date">Expiration Date</label>
              <input type="date" id="expiration_date" name="expiration_date" value="<?php echo e($values['expiration_date']); ?>">
              <span class="help-inline">Required only when quantity is greater than zero.</span>
            </div>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn primary"><?php echo icon('plus'); ?> Save Medicine</button>
            <a class="btn" href="medicine-inventory.php">Cancel</a>
          </div>
        </form>
      </section>
    </main>
<?php render_page_end(); ?>
