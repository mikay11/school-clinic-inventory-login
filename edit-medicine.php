<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';

$id = (int)($_REQUEST['id'] ?? 0);
$medicine = null;
$errors = [];

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT id, name, generic_name, category, unit, location, description, low_stock_threshold FROM medicines WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $medicine = $stmt->fetch() ?: null;
} catch (Throwable $exception) {
    error_log('Edit medicine load error: ' . $exception->getMessage());
    $errors[] = 'Unable to load this medicine right now. Please try again.';
}

if (!$medicine && !$errors) {
    $_SESSION['flash_error'] = 'The requested medicine could not be found.';
    header('Location: medicine-inventory.php');
    exit;
}

$categories = ['Tablet', 'Capsule', 'Syrup', 'Liquid', 'Ointment', 'Other'];

$genericNames = ['Paracetamol', 'Mefenamic Acid', 'Ibuprofen', 'Aspirin', 'Amoxicillin', 'Co-Amoxiclav', 'Doxycycline', 'Cetirizine', 'Loratadine', 'Chlorphenamine', 'Lagundi', 'Carbocisteine', 'Salbutamol', 'Ascorbic Acid', 'Ferrous Sulfate', 'Multivitamins', 'Oral Rehydration Salts', 'Povidone-Iodine', 'Hydrogen Peroxide', 'Simethicone', 'Hyoscine Butylbromide', 'Meclizine', 'Dimenhydrinate', 'Menthol', 'Methyl Salicylate', 'Aluminum Hydroxide', 'Magnesium Hydroxide'];

$medicineNames = ['Biogesic', 'Paracetamol', 'Medicol', 'Tempra', 'Calpol', 'Mefenamic Acid', 'Ibuprofen', 'Aspirin', 'Alaxan', 'Amoxicillin', 'Co-Amoxiclav', 'Doxycycline', 'Cetirizine', 'Loratadine', 'Chlorphenamine', 'Lagundi', 'Solmux', 'Neozep', 'Bioflu', 'Kremil-S', 'Diatabs', 'Hydrite', 'Oral Rehydration Salts', 'Bonamine', 'Buscopan', 'Ambil', 'Multivitamins', 'Ascorbic Acid', 'Ferrous Sulfate', 'Salbutamol', 'Betadine', 'Povidone-Iodine', 'Hydrogen Peroxide', 'Efficascent Oil', 'Katinko'];

$values = [
    'name' => (string)($medicine['name'] ?? ''),
    'generic_name' => (string)($medicine['generic_name'] ?? ''),
    'category' => (string)($medicine['category'] ?? 'Tablet'),
    'location' => (string)($medicine['location'] ?? ''),
    'description' => (string)($medicine['description'] ?? ''),
    'low_stock_threshold' => (string)($medicine['low_stock_threshold'] ?? '20'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $medicine) {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string)($_POST[$key] ?? $default));
    }

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }

    if (!in_array($values['name'], $medicineNames, true)) {
        $errors[] = 'Please select a medicine from the list.';
    }

    if ($values['generic_name'] !== '' && !in_array($values['generic_name'], $genericNames, true)) {
        $errors[] = 'Please select a generic name from the list.';
    }

    if (!in_array($values['category'], $categories, true)) {
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

    if (!$errors) {
        try {
            $duplicate = $pdo->prepare('SELECT id FROM medicines WHERE name = :name AND id <> :id LIMIT 1');
            $duplicate->execute(['name' => $values['name'], 'id' => $id]);
            if ($duplicate->fetch()) {
                $errors[] = 'Another medicine with this name already exists.';
            } else {
                $pdo->beginTransaction();

                $update = $pdo->prepare(
                    'UPDATE medicines
                     SET name = :name, generic_name = :generic_name, category = :category,
                         location = :location, description = :description, low_stock_threshold = :threshold
                     WHERE id = :id'
                );
                $update->execute([
                    'name' => $values['name'],
                    'generic_name' => $values['generic_name'],
                    'category' => $values['category'],
                    'location' => $values['location'],
                    'description' => $values['description'],
                    'threshold' => (int)$values['low_stock_threshold'],
                    'id' => $id,
                ]);

                $activity = $pdo->prepare(
                    'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
                     VALUES (:type, :medicine_id, 0, :note, :performed_by)'
                );
                $activity->execute([
                    'type' => 'update_medicine',
                    'medicine_id' => $id,
                    'note' => 'Medicine details updated',
                    'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
                ]);

                $pdo->commit();

                $_SESSION['flash_success'] = '"' . $values['name'] . '" was updated.';
                header('Location: medicine-view.php?id=' . $id);
                exit;
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Edit medicine error: ' . $exception->getMessage());
            $errors[] = 'The changes could not be saved right now. Please try again.';
        }
    }

    $medicine = array_merge($medicine ?? [], $values);
}

$categoryOptions = $categories;
if ($values['category'] !== '' && !in_array($values['category'], $categoryOptions, true)) {
    array_unshift($categoryOptions, $values['category']);
}

$nameOptions = $medicineNames;
if ($values['name'] !== '' && !in_array($values['name'], $nameOptions, true)) {
    array_unshift($nameOptions, $values['name']);
}

$genericOptions = $genericNames;
if ($values['generic_name'] !== '' && !in_array($values['generic_name'], $genericOptions, true)) {
    array_unshift($genericOptions, $values['generic_name']);
}

$identity = session_identity();
render_page_start('Edit Medicine', 'inventory', $identity['full_name'], unread_notifications_count());
?>
    <main class="content">
      <div class="page-head">
        <div>
          <a class="link back-link" href="medicine-view.php?id=<?php echo $id; ?>"><?php echo icon('back'); ?> Back to Medicine Details</a>
          <p class="muted page-sub">Update the details of this medicine. Batches and stock are managed separately.</p>
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

      <?php if ($medicine): ?>
        <section class="panel form-panel">
          <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

            <h2 class="form-title">Medicine Details</h2>
            <div class="form-grid">
              <div class="field">
                <label for="name">Medicine Name <span class="req">*</span></label>
                <select id="name" name="name" required>
                  <option value="" disabled<?php echo $values['name'] === '' ? ' selected' : ''; ?>>Select medicine</option>
                  <?php foreach ($nameOptions as $nameOption): ?>
                    <option value="<?php echo e($nameOption); ?>"<?php echo $values['name'] === $nameOption ? ' selected' : ''; ?>><?php echo e($nameOption); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label for="generic_name">Generic Name</label>
                <select id="generic_name" name="generic_name">
                  <option value=""<?php echo $values['generic_name'] === '' ? ' selected' : ''; ?>>Select generic name</option>
                  <?php foreach ($genericOptions as $genericName): ?>
                    <option value="<?php echo e($genericName); ?>"<?php echo $values['generic_name'] === $genericName ? ' selected' : ''; ?>><?php echo e($genericName); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label for="category">Category</label>
                <select id="category" name="category">
                  <?php foreach ($categoryOptions as $category): ?>
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
                  <option value="Cabinet A"></option>
                  <option value="Cabinet B"></option>
                  <option value="Cabinet C"></option>
                  <option value="Refrigerator"></option>
                  <option value="Emergency Kit"></option>
                </datalist>
              </div>

              <div class="field full">
                <label for="description">Description <span class="muted small">(optional)</span></label>
                <textarea id="description" name="description" rows="3" maxlength="255"><?php echo e($values['description']); ?></textarea>
              </div>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn primary">Save Changes</button>
              <a class="btn" href="medicine-view.php?id=<?php echo $id; ?>">Cancel</a>
            </div>
          </form>
        </section>
      <?php endif; ?>
    </main>
<?php render_page_end(); ?>
